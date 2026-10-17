<?php

declare(strict_types=1);

namespace Drumeo\Video\Jobs;

use Drumeo\Video\Config;
use Drumeo\Video\Hls\MasterPlaylist;
use Drumeo\Video\Recipe\Matcher;
use Drumeo\Video\Recipe\Recipe;

/**
 * Single module that turns (source, recipe, audioIndex) into an FFmpeg argv.
 * EncoderPicker smoke-tests encoders with this same argv.
 */
final class FfmpegCommand
{
    public const DEFAULT_VAAPI_DEVICE = '/dev/dri/renderD128';

    public function __construct(
        private readonly Config $config,
        private readonly Matcher $matcher,
        private readonly EncoderPicker $encoders,
    ) {
    }

    /**
     * @param array<string,mixed> $meta
     * @param string|null $encoder H.264 encoder for avc_1080; null = EncoderPicker's choice
     * @return list<string>
     */
    public function build(string $sourcePath, string $outDir, array $meta, string $recipe, int $audioIndex, ?string $encoder = null): array
    {
        $video = is_array($meta['video'] ?? null) ? $meta['video'] : [];
        $fps = (float) ($video['fps'] ?? 24.0);
        if ($fps <= 0) {
            $fps = 24.0;
        }
        $gop = max(24, (int) round($fps * 2));
        $srcCodec = $this->matcher->normalizeVideoCodec((string) ($video['codec'] ?? ''));
        if ($recipe === Recipe::AVC_1080) {
            $encoder ??= $this->encoders->codec();
        }

        $args = [$this->config->ffmpegBin, '-hide_banner', '-loglevel', 'error', '-y'];
        if ($recipe === Recipe::AVC_1080) {
            // Decoder/device options must precede -i.
            $args = array_merge($args, $this->inputArgs((string) $encoder));
        }
        $args = array_merge($args, [
            '-i', $sourcePath,
            '-map', '0:v:0',
            '-map', '0:a:' . $audioIndex,
        ]);

        if ($recipe === Recipe::REMUX) {
            $args = array_merge($args, ['-c:v', 'copy', '-c:a', 'copy'], $this->tagArgs($srcCodec));
        } elseif ($recipe === Recipe::AUDIO_AAC) {
            $args = array_merge($args, ['-c:v', 'copy'], $this->tagArgs($srcCodec), $this->aacArgs());
        } elseif ($recipe === Recipe::AVC_1080) {
            $args = array_merge($args, $this->avc1080Args((string) $encoder, $video, $gop), $this->aacArgs());
        } else {
            throw new \InvalidArgumentException('Unknown recipe: ' . $recipe);
        }

        return array_merge($args, [
            '-f', 'hls',
            '-hls_time', '6',
            '-hls_list_size', '0',
            '-hls_playlist_type', 'event',
            '-hls_flags', 'independent_segments+temp_file',
            '-hls_segment_type', 'mpegts',
            '-hls_segment_filename', $outDir . '/seg_%03d.ts',
            $outDir . '/index.m3u8',
        ]);
    }

    /**
     * @param array<string,mixed> $meta
     */
    public function writeMaster(string $masterPath, array $meta, string $recipe, array $offerVideo, array $offerAudio): void
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
    private function inputArgs(string $encoder): array
    {
        if ($encoder !== 'h264_vaapi') {
            return [];
        }
        // -vaapi_device creates the device and makes it the filter device;
        // -hwaccel vaapi then decodes on that same device when it can.
        return [
            '-vaapi_device', $this->encoders->vaapiDevice() ?? self::DEFAULT_VAAPI_DEVICE,
            '-hwaccel', 'vaapi',
            '-hwaccel_output_format', 'vaapi',
        ];
    }

    /** @return list<string> */
    private function tagArgs(string $srcCodec): array
    {
        return match ($srcCodec) {
            'hevc' => ['-tag:v', 'hvc1'],
            'h264' => ['-tag:v', 'avc1'],
            default => [],
        };
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
    private function avc1080Args(string $encoder, array $video, int $gop): array
    {
        $srcW = (int) ($video['width'] ?? 1920);
        $srcH = (int) ($video['height'] ?? 1080);
        [$w, $h] = $this->matcher->scale1080($srcW, $srcH);
        $scale = 'scale=' . $w . ':' . $h . ':force_original_aspect_ratio=decrease,scale=trunc(iw/2)*2:trunc(ih/2)*2,format=yuv420p';

        $commonRate = ['-b:v', '5M', '-maxrate', '6M', '-bufsize', '10M', '-g', (string) $gop, '-keyint_min', (string) $gop];

        return match ($encoder) {
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
                    '-c:v', 'h264_vaapi',
                    '-profile:v', 'high',
                    // Frames are vaapi surfaces after hw decode, system memory after a
                    // software-decode fallback; format=nv12|vaapi,hwupload handles both.
                    '-vf', 'format=nv12|vaapi,hwupload,scale_vaapi=w=' . $w . ':h=' . $h . ':format=nv12',
                ],
                $commonRate,
            ),
            'h264_videotoolbox' => array_merge(
                ['-c:v', 'h264_videotoolbox', '-profile:v', 'high', '-pix_fmt', 'yuv420p', '-vf', $scale],
                $commonRate,
            ),
            // libx264: keep this argv byte-for-byte stable (production default).
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
