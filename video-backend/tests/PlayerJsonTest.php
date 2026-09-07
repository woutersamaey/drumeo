<?php

declare(strict_types=1);

namespace Drumeo\Video\Tests;

use Drumeo\Video\Http\Request;
use Drumeo\Video\Playback\PlayerRenderer;
use PHPUnit\Framework\TestCase;

final class PlayerJsonTest extends TestCase
{
    public function testRendererReturnsOnlyHtmlAndJs(): void
    {
        $h = new TestHarness();
        try {
            $r = new PlayerRenderer($h->config);
            $out = $r->render('film1', 'remux', 0, '/hls/film1/remux/0/master.m3u8');
            $this->assertSame(['html', 'js'], array_keys($out));
            $this->assertStringContainsString('<video', $out['html']);
            $this->assertStringContainsString('controls', $out['html']);
            $this->assertStringContainsString('playsinline', $out['html']);
            $this->assertStringContainsString('preload="metadata"', $out['html']);
            $this->assertStringNotContainsString('<script', $out['html']);
            $this->assertStringNotContainsString('<link', $out['html']);
            $this->assertStringNotContainsString('<html', $out['html']);
            $this->assertStringNotContainsString('<script', $out['js']);
            $this->assertStringNotContainsString('cdn.jsdelivr', $out['js']);
            $this->assertStringNotContainsString('/static/player.js', $out['js']);
            $this->assertStringNotContainsString('src="https://', $out['js']);
            $this->assertStringContainsString('/hls/film1/remux/0/master.m3u8', $out['html']);
        } finally {
            $h->destroy();
        }
    }

    public function testPlayerEndpoint409WithoutPlaylist(): void
    {
        $h = new TestHarness();
        try {
            $h->writeNamedSource('clip1', 'x');
            $req = new Request('GET', '/api/playback/player', [
                'videoId' => 'clip1',
                'recipe' => 'remux',
                'audioIndex' => '0',
            ], [], [], '');
            $res = $h->app->handle($req);
            $this->assertSame(409, $res->status);
        } finally {
            $h->destroy();
        }
    }

    public function testPlayerEndpointReturnsExactShapeWhenPlayable(): void
    {
        $h = new TestHarness();
        try {
            $h->writeNamedSource('film1', 'x');
            $h->plantHls('film1', 'remux', 0, 4, true);
            $stat = $h->app->sources->stat('film1');
            $h->app->meta->write('film1', [
                'id' => 'film1',
                'source' => ['mtimeMs' => $stat['mtimeMs'], 'size' => $stat['size'], 'probeError' => null, 'failCount' => 0],
                'durationSec' => 24,
                'video' => ['index' => 0, 'codec' => 'hevc', 'width' => 1280, 'height' => 720, 'pixFmt' => 'yuv420p'],
                'audioTracks' => [['index' => 0, 'codec' => 'aac', 'channels' => 2]],
                'lastPlayedAt' => null,
                'variants' => [[
                    'recipe' => 'remux',
                    'audioIndex' => 0,
                    'state' => 'ready',
                    'mode' => 'remux',
                    'protocol' => 'hls',
                    'video' => ['codec' => 'hvc1', 'width' => 1280, 'height' => 720],
                    'audio' => ['index' => 0, 'codec' => 'aac', 'channels' => 2],
                    'playlistPath' => 'film1/remux/0/master.m3u8',
                    'segmentCount' => 4,
                    'durationReadySec' => 24,
                    'failCount' => 0,
                    'error' => null,
                ]],
            ]);
            $req = new Request('GET', '/api/playback/player', [
                'videoId' => 'film1',
                'recipe' => 'remux',
                'audioIndex' => '0',
            ], [], [], '');
            $res = $h->app->handle($req);
            $this->assertSame(200, $res->status);
            $this->assertSame(['html', 'js'], array_keys($res->body));
            $this->assertStringContainsString('<video', $res->body['html']);
            $this->assertStringNotContainsString('<script src', $res->body['js']);
        } finally {
            $h->destroy();
        }
    }
}
