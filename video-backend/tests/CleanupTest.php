<?php

declare(strict_types=1);

namespace Drumeo\Video\Tests;

use Drumeo\Video\App;
use Drumeo\Video\Config;
use PHPUnit\Framework\TestCase;

final class CleanupTest extends TestCase
{
    private TestHarness $h;

    protected function setUp(): void
    {
        $this->h = new TestHarness();
    }

    protected function tearDown(): void
    {
        $this->h->destroy();
    }

    public function testVariantWithAJobLockIsNeverCleaned(): void
    {
        $this->h->seedProbed('film1', 24.0, [TestHarness::variant('remux', 0, 'failed', ['failCount' => 1])]);
        $this->h->plantHls('film1', 'remux', 0, 2, true);
        // A queued retry exists, but the metadata still says failed.
        $this->h->app->worker->enqueue('film1', 'remux', 0, 'play', ['mode' => 'remux']);
        $this->assertSame([], $this->h->app->cleaner->plan());

        $this->h->app->worker->cancel('film1', 'remux', 0);
        $this->assertSame('failed', $this->h->app->cleaner->plan()[0]['reason']);
    }

    public function testAgeUsesMostRecentOfLastPlayedAndLastAccess(): void
    {
        $recent = $this->h->clock->now();
        $this->h->seedProbed('film1', 24.0, [TestHarness::variant('remux', 0, 'ready', ['lastAccessAt' => $recent])]);
        $this->h->app->meta->update('film1', function (array $m) {
            $m['lastPlayedAt'] = '2020-01-01T00:00:00Z'; // played long ago, prefetched just now
            return $m;
        });
        $this->h->plantHls('film1', 'remux', 0, 2, true);
        $this->assertSame([], $this->h->app->cleaner->plan());
    }

    public function testWorkerRunsCleanupOncePerInterval(): void
    {
        $this->h->seedProbed('film1', 24.0, [TestHarness::variant('remux', 0, 'failed', ['failCount' => 1])]);
        $this->h->plantHls('film1', 'remux', 0, 2, true);
        $this->h->seedProbed('film2', 12.0, [TestHarness::variant('remux', 0, 'ready', ['lastAccessAt' => $this->h->clock->now()])]);
        $this->h->plantHls('film2', 'remux', 0, 2, true);
        file_put_contents($this->h->root . '/cache/film2/remux/0/ffmpeg.log', 'legacy, publicly served');

        $result = $this->h->app->worker->maintain();
        $this->assertNotNull($result);
        $this->assertCount(1, $result['deleted']);
        $this->assertSame(1, $result['legacyLogsRemoved']);
        $this->assertDirectoryDoesNotExist($this->h->root . '/cache/film1/remux/0');
        $this->assertFileExists($this->h->root . '/cache/film2/remux/0/index.m3u8');
        $this->assertFileDoesNotExist($this->h->root . '/cache/film2/remux/0/ffmpeg.log');
        $this->assertSame((string) $this->h->clock->unix(), trim((string) file_get_contents($this->h->config->cleanupStampPath())));
        $this->assertFileExists($this->h->root . '/bron/film1.mkv');

        // A restarted worker reads the stamp and waits for the interval.
        $restarted = App::build($this->h->config, new FakeFfmpegRunner(), $this->h->clock);
        $this->assertNull($restarted->worker->maintain());
        $this->h->clock->advance($this->h->config->cleanupIntervalSeconds + 1);
        $this->assertNotNull($restarted->worker->maintain());
    }

    public function testDeletionRefusesToReachIntoSourceDir(): void
    {
        // Misconfiguration: SOURCE_DIR inside CACHE_DIR, named like a video id.
        $root = $this->h->root;
        mkdir($root . '/cache/film9', 0777, true);
        file_put_contents($root . '/cache/film9/film9.mkv', 'source');
        $config = new Config(
            sourceDir: $root . '/cache/film9',
            cacheDir: $root . '/cache',
            metadataDir: $root . '/meta',
            tmpDir: $root . '/tmp',
            ffmpegVcodec: 'libx264',
        );
        $app = App::build($config, new FakeFfmpegRunner(), $this->h->clock);
        try {
            $app->hls->deleteAllFor('film9');
            $this->fail('expected refusal');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('SOURCE_DIR', $e->getMessage());
        }
        $this->assertFileExists($root . '/cache/film9/film9.mkv');
    }
}
