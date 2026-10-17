<?php

declare(strict_types=1);

namespace Drumeo\Video\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Next-lesson prefetch: query, then POST prepare {recipe, intent: prefetch}
 * every 5 s until ready; 429 means retry later. Repeats must be no-ops.
 */
final class PrefetchPollingTest extends TestCase
{
    private TestHarness $h;

    protected function setUp(): void
    {
        $this->h = new TestHarness();
        $this->h->seedProbed('film1', 24.0);
    }

    protected function tearDown(): void
    {
        $this->h->destroy();
    }

    public function testRepeatedPrefetchWhileQueuedOrRunningIsANoOp(): void
    {
        $first = $this->prefetch('film1');
        $this->assertSame(202, $first->status);
        $jobId = $this->h->app->locks->read('film1', 'remux', 0)['jobId'];

        $this->assertSame(202, $this->prefetch('film1')->status, 'queued');
        $this->h->app->worker->tick();
        $metaBefore = (string) file_get_contents($this->metaPath());
        $this->h->clock->advance(5);
        for ($i = 0; $i < 3; $i++) {
            $res = $this->prefetch('film1');
            $this->assertSame(202, $res->status, 'running');
            $this->assertSame('running', $res->body['state']);
        }

        $this->assertSame($jobId, $this->h->app->locks->read('film1', 'remux', 0)['jobId']);
        $this->assertCount(1, $this->h->app->jobs->all(), 'one job record');
        $this->assertSame(1, $this->h->runner->starts, 'one FFmpeg');
        $this->assertSame($metaBefore, (string) file_get_contents($this->metaPath()), 'polls do not rewrite metadata');
    }

    public function testRepeatedPrefetchOnReadyVariantIsANoOp(): void
    {
        $this->h->seedProbed('film1', 24.0, [TestHarness::variant('remux', 0, 'ready', ['lastAccessAt' => $this->h->clock->now()])]);
        $this->h->plantHls('film1', 'remux', 0, 4, true);
        $this->assertSame(200, $this->prefetch('film1')->status); // first call versions the segment URLs
        $metaBefore = (string) file_get_contents($this->metaPath());
        $indexBefore = (string) file_get_contents($this->h->root . '/cache/film1/remux/0/index.m3u8');

        for ($i = 0; $i < 3; $i++) {
            $this->h->clock->advance(5);
            $res = $this->prefetch('film1');
            $this->assertSame(200, $res->status);
            $this->assertIsString($res->body['playlistUrl']);
        }
        $this->assertSame([], $this->h->app->locks->all());
        $this->assertSame([], $this->h->app->jobs->all());
        $this->assertSame($metaBefore, (string) file_get_contents($this->metaPath()));
        $this->assertSame($indexBefore, (string) file_get_contents($this->h->root . '/cache/film1/remux/0/index.m3u8'));
        $this->assertNull($this->h->app->meta->read('film1')['lastPlayedAt'], 'prefetch is not a play');
    }

    public function testQueueFullReturns429ThenEnqueuesOnceOnRetry(): void
    {
        $this->h->seedProbed('film2', 24.0);
        $this->h->seedProbed('film3', 24.0);
        $this->assertSame(202, $this->prefetch('film2')->status);
        $this->assertSame(202, $this->prefetch('film3')->status);

        $full = $this->prefetch('film1');
        $this->assertSame(429, $full->status);
        $this->assertSame('15', $full->headers['Retry-After']);
        $this->assertNull($this->h->app->locks->read('film1', 'remux', 0), '429 creates nothing');

        $this->h->app->worker->tick();
        $runner = $this->h->runner;
        $this->assertInstanceOf(FakeFfmpegRunner::class, $runner);
        $runner->finishAll();
        $this->h->app->worker->tick(); // both done, slots free

        $this->assertSame(202, $this->prefetch('film1')->status);
        $this->assertSame(202, $this->prefetch('film1')->status);
        $this->assertCount(1, array_filter($this->h->app->locks->all(), fn ($l) => $l['videoId'] === 'film1'));
    }

    public function testFailedPrefetchIsNotRequeued(): void
    {
        $this->h->seedProbed('film1', 24.0, [TestHarness::variant('remux', 0, 'failed', ['failCount' => 1])]);
        $this->assertSame(409, $this->prefetch('film1')->status);
        $this->assertSame([], $this->h->app->locks->all());
    }

    public function testNativeHlsCapabilityIsOptional(): void
    {
        $caps = ['protocols' => ['hls'], 'videoCodecs' => ['avc1'], 'audioCodecs' => ['mp4a.40.2'], 'maxWidth' => 1920, 'maxHeight' => 1080];
        $without = $this->h->app->handle($this->h->post('/api/playback/query', ['videoId' => 'film1', 'audioIndex' => 0, 'capabilities' => $caps]));
        $with = $this->h->app->handle($this->h->post('/api/playback/query', ['videoId' => 'film1', 'audioIndex' => 0, 'capabilities' => $caps + ['nativeHls' => true]]));
        $this->assertSame(200, $without->status);
        $this->assertSame($without->body['recipe'], $with->body['recipe']);
        $this->assertArrayNotHasKey('playerUrl', $without->body);
        $this->assertArrayHasKey('playlistPath', $without->body);
    }

    private function prefetch(string $id): \Drumeo\Video\Http\Response
    {
        return $this->h->app->handle($this->h->post('/api/playback/prepare', [
            'videoId' => $id, 'recipe' => 'remux', 'audioIndex' => 0, 'intent' => 'prefetch',
        ]));
    }

    private function metaPath(): string
    {
        return $this->h->config->metadataDir . '/film1.json';
    }
}
