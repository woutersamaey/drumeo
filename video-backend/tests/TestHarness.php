<?php

declare(strict_types=1);

namespace Drumeo\Video\Tests;

use Drumeo\Video\App;
use Drumeo\Video\Clock;
use Drumeo\Video\Config;
use Drumeo\Video\Jobs\FfmpegRunner;

final class TestHarness
{
    public string $root;
    public Config $config;
    public FakeClock $clock;
    /** A FakeFfmpegRunner unless a real runner was passed in. */
    public FfmpegRunner $runner;
    public App $app;

    public function __construct(?FfmpegRunner $runner = null, ?Clock $clock = null, string $adminToken = '')
    {
        $this->root = sys_get_temp_dir() . '/vbtest-' . bin2hex(random_bytes(6));
        foreach (['bron', 'cache', 'meta', 'tmp'] as $d) {
            mkdir($this->root . '/' . $d, 0777, true);
        }
        $this->clock = $clock instanceof FakeClock ? $clock : new FakeClock();
        $this->runner = $runner ?? new FakeFfmpegRunner();
        $this->config = new Config(
            sourceDir: $this->root . '/bron',
            cacheDir: $this->root . '/cache',
            metadataDir: $this->root . '/meta',
            tmpDir: $this->root . '/tmp',
            ffmpegBin: 'ffmpeg',
            ffprobeBin: 'ffprobe',
            ffmpegVcodec: 'libx264',
            adminToken: $adminToken,
        );
        $this->app = App::build($this->config, $this->runner, $this->clock);
    }

    public function destroy(): void
    {
        $this->rm($this->root);
    }

    public function copySource(string $src, string $id): string
    {
        $dest = $this->root . '/bron/' . $id . '.mkv';
        copy($src, $dest);
        return $dest;
    }

    public function writeNamedSource(string $id, string $contents = 'not-a-real-mkv'): string
    {
        $dest = $this->root . '/bron/clip [' . $id . '].mkv';
        file_put_contents($dest, $contents);
        return $dest;
    }

    /**
     * Probed metadata for {id}.mkv (a fake file), with optional variants.
     *
     * @param list<array<string,mixed>> $variants
     */
    public function seedProbed(string $id, float $durationSec = 12.0, array $variants = [], string $vcodec = 'hevc', int $w = 1280, int $h = 720): void
    {
        $path = $this->root . '/bron/' . $id . '.mkv';
        file_put_contents($path, 'fake');
        $stat = stat($path);
        $this->app->meta->write($id, [
            'id' => $id,
            'source' => [
                'mtimeMs' => (int) round($stat['mtime'] * 1000),
                'size' => $stat['size'],
                'probeError' => null,
                'failCount' => 0,
            ],
            'durationSec' => $durationSec,
            'video' => ['index' => 0, 'codec' => $vcodec, 'width' => $w, 'height' => $h, 'pixFmt' => 'yuv420p', 'bits' => 8, 'hdr' => false, 'fps' => 24],
            'audioTracks' => [['index' => 0, 'codec' => 'aac', 'channels' => 2]],
            'lastPlayedAt' => null,
            'variants' => $variants,
        ]);
        $this->app->sources->refresh();
    }

    /** @return array<string,mixed> */
    public static function variant(string $recipe, int $audio, string $state, array $extra = []): array
    {
        return $extra + [
            'recipe' => $recipe,
            'audioIndex' => $audio,
            'state' => $state,
            'mode' => $recipe,
            'protocol' => 'hls',
            'video' => ['codec' => 'hvc1', 'width' => 1280, 'height' => 720],
            'audio' => ['index' => $audio, 'codec' => 'aac', 'channels' => 2],
            'playlistPath' => '',
            'failCount' => 0,
            'error' => null,
        ];
    }

    /** @param array<string,mixed> $json */
    public function post(string $path, array $json, array $headers = []): \Drumeo\Video\Http\Request
    {
        return new \Drumeo\Video\Http\Request('POST', $path, [], ['content-type' => 'application/json'] + $headers, $json, json_encode($json) ?: '');
    }

    /** @param array<string,string> $query */
    public function get(string $path, array $query = [], array $headers = []): \Drumeo\Video\Http\Request
    {
        return new \Drumeo\Video\Http\Request('GET', $path, $query, $headers, [], '');
    }

    public function plantHls(string $id, string $recipe, int $audio, int $segments, bool $endlist): void
    {
        $dir = $this->root . '/cache/' . $id . '/' . $recipe . '/' . $audio;
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot plant HLS dir: ' . $dir);
        }
        $body = "#EXTM3U\n#EXT-X-VERSION:3\n#EXT-X-TARGETDURATION:6\n#EXT-X-PLAYLIST-TYPE:EVENT\n";
        for ($i = 0; $i < $segments; $i++) {
            $body .= "#EXTINF:6.0,\nseg_" . sprintf('%03d', $i) . ".ts\n";
            file_put_contents($dir . '/seg_' . sprintf('%03d', $i) . '.ts', 'ts');
        }
        if ($endlist) {
            $body = str_replace('EVENT', 'VOD', $body) . "#EXT-X-ENDLIST\n";
        }
        file_put_contents($dir . '/index.m3u8', $body);
        file_put_contents($dir . '/master.m3u8', "#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=1000,RESOLUTION=1280x720,CODECS=\"avc1.640028,mp4a.40.2\",VIDEO-RANGE=SDR\nindex.m3u8\n");
    }

    private function rm(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }
        if (is_file($path) || is_link($path)) {
            @unlink($path);
            return;
        }
        foreach (scandir($path) ?: [] as $n) {
            if ($n === '.' || $n === '..') {
                continue;
            }
            $this->rm($path . '/' . $n);
        }
        @rmdir($path);
    }
}
