<?php

declare(strict_types=1);

namespace Drumeo\Video\Jobs;

use Drumeo\Video\Config;
use Drumeo\Video\Hls\MasterPlaylist;
use Drumeo\Video\Recipe\Matcher;
use Drumeo\Video\Recipe\Recipe;

/**
 * Single module that turns (source, recipe, audioIndex) into an FFmpeg argv.
 */
final class FfmpegCommand
{
    public function __construct(
        private readonly Config $config,
        private readonly Matcher $matcher,
        private readonly EncoderPicker $encoders,
    ) {
    }

    /**
     * @param array<string,mixed> $meta
     * @return list<string>
     */
    public function build(string $sourcePath, string $outDir, array $meta, string $recipe, int $audioIndex): array
    {
        $video = is_array($meta['video'] ?? null) ? $meta['video'] : [];
        $fps = (float) ($video['fps'] ?? 24.0);
        if ($fps <= 0) {
            $fps = 24.0;
        }
        $gop = max(24, (int) round($fps * 2));
        $srcCodec = $this->matcher->normalizeVideoCodec((string) ($video['codec'] ?? ''));

        $args = [
            $this->config->ffmpegBin,
            '-hide_banner',
            '-loglevel', 'error',
            '-y',
            '-i', $sourcePath,
            '-map', '0:v:0',
            '-map', '0:a:' . $audioIndex,
        ];

        if ($recipe === Recipe::REMUX) {
            $args = array_merge($args, ['-c:v', 'copy', '-c:a', 'copy']);
            if ($srcCodec === 'hevc') {
                $args = array_merge($args, ['-tag:v', 'hvc1']);
            } elseif ($srcCodec === 'h264') {
                $args = array_merge($args, ['-tag:v', 'avc1']);
            }
        } elseif ($recipe === Recipe::AUDIO_AAC) {
            $args = array_merge($args, ['-c:v', 'copy']);
            if ($srcCodec === 'hevc') {
                $args = array_merge($args, ['-tag:v', 'hvc1']);
            } elseif ($srcCodec === 'h264') {
                $args = array_merge($args, ['-tag:v', 'avc1']);
            }
            $args = array_merge($args, $this->aacArgs());
        } elseif ($recipe === Recipe::AVC_1080) {
            $args = array_merge($args, $this->avc1080Args($video, $gop));
            $args = array_merge($args, $this->aacArgs());
        } else {
            throw new \InvalidArgumentException('Unknown recipe: ' . $recipe);
        }

        $playlist = $outDir . '/index.m3u8';
        $segment = $outDir . '/seg_%03d.ts';
        $args = array_merge($args, [
            '-f', 'hls',
            '-hls_time', '6',
            '-hls_list_size', '0',
            '-hls_playlist_type', 'event',
            '-hls_flags', 'independent_segments+temp_file',
            '-hls_segment_type', 'mpegts',
            '-hls_segment_filename', $segment,
            $playlist,
        ]);

        return $args;
    }

    /**
     * @param array<string,mixed> $meta
     */
    public function writeMaster(string $masterPath, array $meta, string $recipe, int $audioIndex, array $offerVideo, array $offerAudio): void
    {
        $w = (int) ($offerVideo['width'] ?? 0);
        $h = (int) ($offerVideo['height'] ?? 0);
        $fps = (float) ($meta['video']['fps'] ?? 24);
        $bw = MasterPlaylist::bandwidthFor($recipe, $w, $h);
        MasterPlaylist::write(
            $masterPath,
            MasterPlaylist::codecs((string) ($offerVideo['codec'] ?? 'avc1'), (string) ($offerAudio['codec'] ?? 'aac')),
            max(1, $w),
            max(1, $h),
            $fps > 0 ? $fps : 24.0,
            $bw['peak'],
            $bw['avg'],
        );
    }

    /** @return list<string> */
    private function aacArgs(): array
    {
        return ['-c:a', 'aac', '-profile:a', 'aac_low', '-ac', '2', '-b:a', '128k'];
    }

    /**
     * @param array<string,mixed> $video
     * @return list<string>
     */
    private function avc1080Args(array $video, int $gop): array
    {
        $srcW = (int) ($video['width'] ?? 1920);
        $srcH = (int) ($video['height'] ?? 1080);
        [$w, $h] = $this->matcher->scale1080($srcW, $srcH);
        $vcodec = $this->encoders->codec();
        $scale = 'scale=' . $w . ':' . $h . ':force_original_aspect_ratio=decrease,scale=trunc(iw/2)*2:trunc(ih/2)*2,format=yuv420p';

        $commonRate = ['-b:v', '5M', '-maxrate', '6M', '-bufsize', '10M', '-g', (string) $gop, '-keyint_min', (string) $gop];

        return match ($vcodec) {
            'h264_nvenc' => array_merge(
                ['-c:v', 'h264_nvenc', '-preset', 'p4', '-profile:v', 'high', '-pix_fmt', 'yuv420p', '-vf', $scale],
                $commonRate,
                ['-forced-idr', '1'],
            ),
            'h264_qsv' => array_merge(
                ['-c:v', 'h264_qsv', '-preset', 'veryfast', '-profile:v', 'high', '-vf', $scale],
                $commonRate,
            ),
            'h264_vaapi' => array_merge(
                [
                    '-hwaccel', 'vaapi',
                    '-hwaccel_output_format', 'vaapi',
                    '-c:v', 'h264_vaapi',
                    '-vf', 'format=nv12|vaapi,hwupload,scale_vaapi=w=' . $w . ':h=' . $h . ':format=nv12',
                ],
                $commonRate,
            ),
            default => array_merge(
                [
                    '-c:v', 'libx264',
                    '-preset', 'veryfast',
                    '-profile:v', 'high',
                    '-pix_fmt', 'yuv420p',
                    '-vf', $scale,
                    '-sc_threshold', '0',
                ],
                $commonRate,
            ),
        };
    }
}
