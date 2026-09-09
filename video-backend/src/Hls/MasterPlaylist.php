<?php

declare(strict_types=1);

namespace Drumeo\Video\Hls;

final class MasterPlaylist
{
    public static function write(
        string $path,
        string $codecs,
        int $width,
        int $height,
        float $frameRate,
        int $bandwidth,
        int $averageBandwidth,
    ): void {
        $fps = number_format($frameRate, 3, '.', '');
        $body = "#EXTM3U\n"
            . "#EXT-X-VERSION:6\n"
            . "#EXT-X-INDEPENDENT-SEGMENTS\n"
            . '#EXT-X-STREAM-INF:BANDWIDTH=' . $bandwidth
            . ',AVERAGE-BANDWIDTH=' . $averageBandwidth
            . ',RESOLUTION=' . $width . 'x' . $height
            . ',FRAME-RATE=' . $fps
            . ',CODECS="' . $codecs . '"'
            . ",VIDEO-RANGE=SDR\n"
            . "index.m3u8\n";
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create HLS directory: ' . $dir);
        }
        file_put_contents($path, $body);
    }

    public static function withMediaUri(string $contents, string $uri): string
    {
        $updated = preg_replace('/^index\.m3u8(?:\?[^\s]*)?\s*$/m', $uri, $contents);
        return is_string($updated) ? $updated : $contents;
    }

    public static function codecs(string $videoCodec, string $audioCodec): string
    {
        $video = match ($videoCodec) {
            'hvc1', 'hevc', 'h265' => 'hvc1.1.6.L123.B0',
            'avc1', 'h264', 'avc' => 'avc1.640028',
            default => $videoCodec,
        };
        $audio = match ($audioCodec) {
            'aac', 'mp4a.40.2', 'mp4a' => 'mp4a.40.2',
            default => $audioCodec,
        };
        return $video . ',' . $audio;
    }

    public static function bandwidthFor(string $recipe, int $width, int $height): array
    {
        if ($recipe === 'avc_1080') {
            return ['peak' => 6_500_000, 'avg' => 5_200_000];
        }
        $pixels = max(1, $width * $height);
        if ($pixels >= 3840 * 2160) {
            return ['peak' => 80_000_000, 'avg' => 35_000_000];
        }
        if ($pixels >= 1920 * 1080) {
            return ['peak' => 20_000_000, 'avg' => 10_000_000];
        }
        return ['peak' => 8_000_000, 'avg' => 4_000_000];
    }
}
