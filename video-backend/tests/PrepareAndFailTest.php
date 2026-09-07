<?php

declare(strict_types=1);

namespace Drumeo\Video\Tests;

use Drumeo\Video\Http\Request;
use Drumeo\Video\Store\AtomicJson;
use PHPUnit\Framework\TestCase;

final class PrepareAndFailTest extends TestCase
{
    public function testIdempotentPrepareDoesNotStartSecondFfmpeg(): void
    {
        $h = new TestHarness();
        try {
            $this->seedProbed($h, 'film1');
            $body = $this->prepareBody('film1', 'play');
            $r1 = $h->app->handle($this->post('/api/playback/prepare', $body));
            $this->assertSame(202, $r1->status);
            $h->app->worker->tick();
            $starts = $h->runner->starts;
            $this->assertSame(1, $starts);
            $r2 = $h->app->handle($this->post('/api/playback/prepare', $body));
            $this->assertSame(202, $r2->status);
            $h->app->worker->tick();
            $this->assertSame(1, $h->runner->starts);
        } finally {
            $h->destroy();
        }
    }

    public function testPrefetchDoesNotStartSecondVideoTranscode(): void
    {
        $h = new TestHarness();
        try {
            $this->seedProbed($h, 'film1', 'hevc', 'aac', 3840, 2160);
            $this->seedProbed($h, 'film2', 'hevc', 'aac', 3840, 2160);
            $caps = $this->oldCaps();
            $h->app->handle($this->post('/api/playback/prepare', [
                'videoId' => 'film1',
                'audioIndex' => 0,
                'intent' => 'play',
                'capabilities' => $caps,
            ]));
            $h->app->worker->tick();
            $this->assertSame(1, $h->runner->starts);
            $r = $h->app->handle($this->post('/api/playback/prepare', [
                'videoId' => 'film2',
                'audioIndex' => 0,
                'intent' => 'prefetch',
                'capabilities' => $caps,
            ]));
            $this->assertContains($r->status, [202, 429]);
            $h->app->worker->tick();
            $avcStarts = 0;
            foreach ($h->runner->commands as $cmd) {
                if (in_array('libx264', $cmd, true) || in_array('h264_nvenc', $cmd, true)) {
                    $avcStarts++;
                }
            }
            $this->assertLessThanOrEqual(1, $avcStarts);
        } finally {
            $h->destroy();
        }
    }

    public function testThreeFailsBlockPrepareWithoutForce(): void
    {
        $h = new TestHarness();
        try {
            $this->seedProbed($h, 'film1');
            $h->app->meta->update('film1', function (array $m) {
                $m['variants'][] = [
                    'recipe' => 'remux',
                    'audioIndex' => 0,
                    'state' => 'failed',
                    'mode' => 'remux',
                    'protocol' => 'hls',
                    'video' => ['codec' => 'hvc1', 'width' => 1280, 'height' => 720],
                    'audio' => ['index' => 0, 'codec' => 'aac', 'channels' => 2],
                    'failCount' => 3,
                    'error' => 'boom',
                    'lastFailAt' => '2026-01-01T00:00:00Z',
                ];
                return $m;
            });
            $r = $h->app->handle($this->post('/api/playback/prepare', $this->prepareBody('film1', 'play')));
            $this->assertSame(409, $r->status);
            $this->assertSame(0, $h->runner->starts);
            $force = $this->prepareBody('film1', 'play');
            $force['force'] = true;
            $r2 = $h->app->handle($this->post('/api/playback/prepare', $force));
            $this->assertSame(202, $r2->status);
        } finally {
            $h->destroy();
        }
    }

    public function testLastPlayedAtSetOnPlayNotPrefetch(): void
    {
        $h = new TestHarness();
        try {
            $this->seedProbed($h, 'film1');
            $h->plantHls('film1', 'remux', 0, 4, true);
            $h->app->meta->update('film1', function (array $m) {
                $m['variants'][] = [
                    'recipe' => 'remux',
                    'audioIndex' => 0,
                    'state' => 'ready',
                    'mode' => 'remux',
                    'protocol' => 'hls',
                    'video' => ['codec' => 'hvc1', 'width' => 1280, 'height' => 720],
                    'audio' => ['index' => 0, 'codec' => 'aac', 'channels' => 2],
                    'playlistPath' => 'film1/remux/0/master.m3u8',
                    'failCount' => 0,
                    'error' => null,
                ];
                return $m;
            });
            $h->app->handle($this->post('/api/playback/prepare', $this->prepareBody('film1', 'prefetch')));
            $meta = $h->app->meta->read('film1');
            $this->assertNull($meta['lastPlayedAt'] ?? null);

            $h->app->handle($this->post('/api/playback/prepare', $this->prepareBody('film1', 'play')));
            $meta = $h->app->meta->read('film1');
            $this->assertNotNull($meta['lastPlayedAt'] ?? null);
        } finally {
            $h->destroy();
        }
    }

    public function testAudioIndexZeroDoesNotCreateTrackOneCache(): void
    {
        $h = new TestHarness();
        try {
            $this->seedProbed($h, 'film1');
            $h->app->meta->update('film1', function (array $m) {
                $m['audioTracks'][] = ['index' => 1, 'codec' => 'aac', 'channels' => 2];
                return $m;
            });
            $h->app->handle($this->post('/api/playback/prepare', $this->prepareBody('film1', 'play')));
            $h->app->worker->tick();
            $this->assertDirectoryDoesNotExist($h->root . '/cache/film1/remux/1');
            $this->assertDirectoryExists($h->root . '/cache/film1/remux/0');
        } finally {
            $h->destroy();
        }
    }

    public function testStaleMtimeReprobesAndClearsVariants(): void
    {
        $h = new TestHarness();
        try {
            $path = $h->writeNamedSource('film1', str_repeat('a', 100));
            $h->app->meta->write('film1', [
                'id' => 'film1',
                'source' => [
                    'mtimeMs' => 1,
                    'size' => 1,
                    'probeError' => null,
                    'failCount' => 0,
                ],
                'durationSec' => 1,
                'video' => ['index' => 0, 'codec' => 'hevc', 'width' => 100, 'height' => 100, 'pixFmt' => 'yuv420p'],
                'audioTracks' => [['index' => 0, 'codec' => 'aac', 'channels' => 2]],
                'lastPlayedAt' => null,
                'variants' => [[
                    'recipe' => 'remux',
                    'audioIndex' => 0,
                    'state' => 'ready',
                    'mode' => 'remux',
                    'protocol' => 'hls',
                    'failCount' => 0,
                ]],
            ]);
            $h->plantHls('film1', 'remux', 0, 4, true);
            $req = new Request('GET', '/api/videos/film1', [], [], [], '');
            $res = $h->app->handle($req);
            $this->assertSame(200, $res->status);
            $stat = stat($path);
            $this->assertSame((int) round($stat['mtime'] * 1000), $res->body['source']['mtimeMs']);
            $this->assertSame($stat['size'], $res->body['source']['size']);
        } finally {
            $h->destroy();
        }
    }

    public function testProbeStopsAfterThreeFailsUntilForceOrChange(): void
    {
        $h = new TestHarness();
        try {
            $h->writeNamedSource('broken', 'not-mkv');
            $h->app->meta->write('broken', [
                'id' => 'broken',
                'source' => [
                    'mtimeMs' => $h->app->sources->stat('broken')['mtimeMs'],
                    'size' => $h->app->sources->stat('broken')['size'],
                    'probeError' => 'ffprobe exit 1',
                    'failCount' => 3,
                ],
                'durationSec' => 0,
                'video' => ['index' => 0, 'codec' => '', 'width' => 0, 'height' => 0, 'pixFmt' => ''],
                'audioTracks' => [],
                'lastPlayedAt' => null,
                'variants' => [],
            ]);
            $before = AtomicJson::read($h->config->metadataDir . '/broken.json');
            $h->app->handle(new Request('GET', '/api/videos/broken', [], [], [], ''));
            $after = AtomicJson::read($h->config->metadataDir . '/broken.json');
            $this->assertSame(3, $after['source']['failCount']);
            $this->assertSame($before['source']['probeError'], $after['source']['probeError']);
        } finally {
            $h->destroy();
        }
    }

    public function testCleanupDryRunDoesNotTouchSource(): void
    {
        $h = new TestHarness();
        try {
            $src = $h->writeNamedSource('film1', 'abc');
            $h->plantHls('film1', 'remux', 0, 2, true);
            $before = file_get_contents($src);
            $result = $h->app->cleaner->run(true);
            $this->assertTrue($result['dryRun']);
            $this->assertSame($before, file_get_contents($src));
            $this->assertFileExists($src);
        } finally {
            $h->destroy();
        }
    }

    public function testInvalidAudioIndex400ListsAvailable(): void
    {
        $h = new TestHarness();
        try {
            $this->seedProbed($h, 'film1');
            $r = $h->app->handle($this->post('/api/playback/query', [
                'videoId' => 'film1',
                'audioIndex' => 9,
                'capabilities' => $this->modernCaps(),
            ]));
            $this->assertSame(400, $r->status);
            $this->assertArrayHasKey('available', $r->body);
            $this->assertContains(0, $r->body['available']);
        } finally {
            $h->destroy();
        }
    }

    public function testMissingSource404(): void
    {
        $h = new TestHarness();
        try {
            $r = $h->app->handle(new Request('GET', '/api/videos/nope', [], [], [], ''));
            $this->assertSame(404, $r->status);
        } finally {
            $h->destroy();
        }
    }

    public function testBearerTokenRequiredWhenConfigured(): void
    {
        $h = new TestHarness();
        try {
            $h->config = new \Drumeo\Video\Config(
                sourceDir: $h->config->sourceDir,
                cacheDir: $h->config->cacheDir,
                metadataDir: $h->config->metadataDir,
                apiToken: 'secret',
                tmpDir: $h->config->tmpDir,
                playerHlsJsPath: $h->config->playerHlsJsPath,
                ffmpegVcodec: 'libx264',
            );
            $h->app = \Drumeo\Video\App::build($h->config, $h->runner, $h->clock);
            $r = $h->app->handle(new Request('GET', '/api/videos', [], [], [], ''));
            $this->assertSame(401, $r->status);
            $r = $h->app->handle(new Request('GET', '/api/videos', [], ['authorization' => 'Bearer secret'], [], ''));
            $this->assertSame(200, $r->status);
        } finally {
            $h->destroy();
        }
    }

    /** @param array<string,mixed> $body */
    private function post(string $path, array $body): Request
    {
        return new Request('POST', $path, [], ['content-type' => 'application/json'], $body, json_encode($body) ?: '');
    }

    /** @return array<string,mixed> */
    private function prepareBody(string $id, string $intent): array
    {
        return [
            'videoId' => $id,
            'audioIndex' => 0,
            'intent' => $intent,
            'capabilities' => $this->modernCaps(),
        ];
    }

    private function modernCaps(): array
    {
        return [
            'protocols' => ['hls'],
            'videoCodecs' => ['avc1', 'hvc1'],
            'audioCodecs' => ['mp4a.40.2'],
            'maxWidth' => 3840,
            'maxHeight' => 2160,
            'nativeHls' => true,
        ];
    }

    private function oldCaps(): array
    {
        return [
            'protocols' => ['hls'],
            'videoCodecs' => ['avc1'],
            'audioCodecs' => ['mp4a.40.2'],
            'maxWidth' => 1920,
            'maxHeight' => 1080,
            'nativeHls' => false,
        ];
    }

    private function seedProbed(TestHarness $h, string $id, string $vcodec = 'hevc', string $acodec = 'aac', int $w = 1280, int $hgt = 720): void
    {
        $path = $h->root . '/bron/' . $id . '.mkv';
        file_put_contents($path, 'fake');
        $stat = stat($path);
        $h->app->meta->write($id, [
            'id' => $id,
            'source' => [
                'mtimeMs' => (int) round($stat['mtime'] * 1000),
                'size' => $stat['size'],
                'probeError' => null,
                'failCount' => 0,
            ],
            'durationSec' => 12,
            'video' => [
                'index' => 0,
                'codec' => $vcodec,
                'width' => $w,
                'height' => $hgt,
                'pixFmt' => 'yuv420p',
                'bits' => 8,
                'hdr' => false,
                'fps' => 24,
            ],
            'audioTracks' => [
                ['index' => 0, 'codec' => $acodec, 'channels' => $acodec === 'aac' ? 2 : 6],
            ],
            'lastPlayedAt' => null,
            'variants' => [],
        ]);
        $h->app->sources->refresh();
    }
}
