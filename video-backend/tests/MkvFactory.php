<?php

declare(strict_types=1);

namespace Drumeo\Video\Tests;

final class MkvFactory
{
    public static function ffmpegAvailable(): bool
    {
        exec('ffmpeg -version >/dev/null 2>&1', $o, $c);
        return $c === 0;
    }

    public static function make(string $path, string $vcodec, string $acodec, string $pix = 'yuv420p', string $size = '640x360', float $seconds = 2.0): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $cmd = [
            'ffmpeg', '-hide_banner', '-loglevel', 'error', '-y',
            '-f', 'lavfi', '-i', 'testsrc=size=' . $size . ':rate=24',
            '-f', 'lavfi', '-i', 'sine=frequency=440:sample_rate=48000',
            '-c:v', $vcodec,
            '-pix_fmt', $pix,
            '-c:a', $acodec,
            '-t', (string) $seconds,
            '-shortest',
            $path,
        ];
        if ($vcodec === 'libx265' || $vcodec === 'hevc') {
            array_splice($cmd, -1, 0, ['-tag:v', 'hvc1']);
        }
        $line = implode(' ', array_map('escapeshellarg', $cmd));
        exec($line . ' 2>&1', $out, $code);
        if ($code !== 0 || !is_file($path)) {
            throw new \RuntimeException("ffmpeg fixture failed ($code): " . implode("\n", $out));
        }
    }
}
