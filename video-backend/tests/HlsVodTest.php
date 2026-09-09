<?php

declare(strict_types=1);

namespace Drumeo\Video\Tests;

use Drumeo\Video\Hls\MasterPlaylist;
use Drumeo\Video\Hls\MediaPlaylist;
use Drumeo\Video\Http\Request;
use Drumeo\Video\Jobs\FfmpegCommand;
use PHPUnit\Framework\TestCase;

final class HlsVodTest extends TestCase
{
    public function testToVodIsIdempotentAndStartsAtZero(): void
    {
        $event = "#EXTM3U\n#EXT-X-VERSION:3\n#EXT-X-TARGETDURATION:6\n#EXT-X-PLAYLIST-TYPE:EVENT\n#EXTINF:6.0,\nseg_000.ts\n";
        $vod = MediaPlaylist::toVod($event);
        $this->assertStringContainsString('#EXT-X-PLAYLIST-TYPE:VOD', $vod);
        $this->assertStringContainsString('#EXT-X-ENDLIST', $vod);
        $this->assertStringContainsString('#EXT-X-START:TIME-OFFSET=0', $vod);
        $this->assertStringNotContainsString('EVENT', $vod);
        $this->assertSame($vod, MediaPlaylist::toVod($vod));
    }

    public function testMasterWithMediaUriReplacesIndexLine(): void
    {
        $raw = "#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=1\nindex.m3u8\n";
        $out = MasterPlaylist::withMediaUri($raw, 'index.m3u8?v=99');
        $this->assertStringContainsString('index.m3u8?v=99', $out);
        $this->assertSame($out, MasterPlaylist::withMediaUri($out, 'index.m3u8?v=99'));
    }

    public function testStatusOmitsPlaylistUrlUntilEndlist(): void
    {
        $h = new TestHarness();
        try {
            $this->seed($h, 'film1');
            $h->plantHls('film1', 'remux', 0, 8, false);
            $res = $h->app->handle(new Request('GET', '/api/playback/status', [
                'videoId' => 'film1',
                'recipe' => 'remux',
                'audioIndex' => '0',
                'intent' => 'play',
            ], [], [], ''));
            $this->assertSame(200, $res->status);
            $this->assertNull($res->body['playlistUrl']);
            $this->assertNotSame('ready', $res->body['state']);
        } finally {
            $h->destroy();
        }
    }

    public function testStatusExposesPlaylistUrlOnceVod(): void
    {
        $h = new TestHarness();
        try {
            $this->seed($h, 'film1');
            $h->plantHls('film1', 'remux', 0, 4, true);
            $res = $h->app->handle(new Request('GET', '/api/playback/status', [
                'videoId' => 'film1',
                'recipe' => 'remux',
                'audioIndex' => '0',
                'intent' => 'play',
            ], [], [], ''));
            $this->assertSame(200, $res->status);
            $this->assertSame('ready', $res->body['state']);
            $this->assertIsString($res->body['playlistUrl']);
            $this->assertStringContainsString('?v=', $res->body['playlistUrl']);
            $master = file_get_contents($h->root . '/cache/film1/remux/0/master.m3u8') ?: '';
            $this->assertMatchesRegularExpression('/index\\.m3u8\\?v=\\d+/', $master);
        } finally {
            $h->destroy();
        }
    }

    public function testPlayer409WhileEventPlaylistIsStillGrowing(): void
    {
        $h = new TestHarness();
        try {
            $this->seed($h, 'film1');
            $h->plantHls('film1', 'remux', 0, 6, false);
            $res = $h->app->handle(new Request('GET', '/api/playback/player', [
                'videoId' => 'film1',
                'recipe' => 'remux',
                'audioIndex' => '0',
            ], [], [], ''));
            $this->assertSame(409, $res->status);
        } finally {
            $h->destroy();
        }
    }

    public function testPlayableRequiresEndlist(): void
    {
        $h = new TestHarness();
        try {
            $h->plantHls('film1', 'remux', 0, 10, false);
            $this->assertFalse($h->app->hls->playable('film1', 'remux', 0));
            $h->plantHls('film1', 'remux', 0, 2, true);
            $this->assertTrue($h->app->hls->playable('film1', 'remux', 0));
        } finally {
            $h->destroy();
        }
    }

    public function testFfmpegWritesEventWithAtomicSegments(): void
    {
        $h = new TestHarness();
        try {
            $cmd = new FfmpegCommand($h->config, $h->app->matcher, $h->app->encoders);
            $args = $cmd->build('/tmp/in.mkv', '/tmp/out', [
                'video' => ['codec' => 'hevc', 'fps' => 24, 'width' => 1280, 'height' => 720, 'pixFmt' => 'yuv420p'],
            ], 'remux', 0);
            $flags = $args[array_search('-hls_flags', $args, true) + 1];
            $this->assertSame('independent_segments+temp_file', $flags);
            $this->assertSame('event', $args[array_search('-hls_playlist_type', $args, true) + 1]);
        } finally {
            $h->destroy();
        }
    }

    private function seed(TestHarness $h, string $id): void
    {
        $path = $h->writeNamedSource($id, 'x');
        $stat = stat($path);
        $h->app->meta->write($id, [
            'id' => $id,
            'source' => [
                'mtimeMs' => (int) round($stat['mtime'] * 1000),
                'size' => $stat['size'],
                'probeError' => null,
                'failCount' => 0,
            ],
            'durationSec' => 48,
            'video' => ['index' => 0, 'codec' => 'hevc', 'width' => 1280, 'height' => 720, 'pixFmt' => 'yuv420p'],
            'audioTracks' => [['index' => 0, 'codec' => 'aac', 'channels' => 2]],
            'lastPlayedAt' => null,
            'variants' => [[
                'recipe' => 'remux',
                'audioIndex' => 0,
                'state' => 'running',
                'mode' => 'remux',
                'protocol' => 'hls',
                'video' => ['codec' => 'hvc1', 'width' => 1280, 'height' => 720],
                'audio' => ['index' => 0, 'codec' => 'aac', 'channels' => 2],
                'playlistPath' => $id . '/remux/0/master.m3u8',
                'failCount' => 0,
                'error' => null,
            ]],
        ]);
        $h->app->sources->refresh();
    }
}
