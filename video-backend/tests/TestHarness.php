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
    public FakeFfmpegRunner $runner;
    public App $app;

    public function __construct(?FfmpegRunner $runner = null, ?Clock $clock = null)
    {
        $this->root = sys_get_temp_dir() . '/vbtest-' . bin2hex(random_bytes(6));
        foreach (['bron', 'cache', 'meta', 'tmp'] as $d) {
            mkdir($this->root . '/' . $d, 0777, true);
        }
        $this->clock = $clock instanceof FakeClock ? $clock : new FakeClock();
        $this->runner = $runner instanceof FakeFfmpegRunner ? $runner : new FakeFfmpegRunner();
        $hlsJs = dirname(__DIR__) . '/resources/player/hls.min.js';
        $this->config = new Config(
            sourceDir: $this->root . '/bron',
            cacheDir: $this->root . '/cache',
            metadataDir: $this->root . '/meta',
            tmpDir: $this->root . '/tmp',
            playerHlsJsPath: is_file($hlsJs) ? $hlsJs : '',
            ffmpegBin: 'ffmpeg',
            ffprobeBin: 'ffprobe',
            ffmpegVcodec: 'libx264',
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
