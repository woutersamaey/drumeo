<?php

declare(strict_types=1);

namespace Drumeo\Video\Recipe;

/**
 * Capability-driven recipe picker. One module for the FFmpeg decision tree.
 */
final class Matcher
{
    /**
     * @param array<string,mixed> $meta
     * @param list<array<string,mixed>> $variants
     * @return array{recipe:string,mode:string,video:array,audio:array}|null
     */
    public function match(array $meta, Capabilities $caps, int $audioIndex, array $variants = []): ?array
    {
        if (!$caps->allowsHls() || !$caps->allowsAac()) {
            return null;
        }

        $tracks = $meta['audioTracks'] ?? [];
        $audio = $this->audioTrack($tracks, $audioIndex);
        if ($audio === null) {
            return null;
        }

        $video = is_array($meta['video'] ?? null) ? $meta['video'] : [];
        $srcW = (int) ($video['width'] ?? 0);
        $srcH = (int) ($video['height'] ?? 0);
        $candidates = $this->candidates($video, $audio, $caps, $srcW, $srcH);
        if ($candidates === []) {
            return null;
        }

        $ready = [];
        foreach ($candidates as $c) {
            if ($this->isReady($variants, $c['recipe'], $audioIndex)) {
                $ready[] = $c;
            }
        }
        $pool = $ready !== [] ? $ready : $candidates;
        usort($pool, fn ($a, $b) => Recipe::weight($a['recipe']) <=> Recipe::weight($b['recipe']));
        return $pool[0];
    }

    /**
     * @param array<string,mixed> $video
     * @param array{index:int,codec:string,channels:int} $audio
     * @return list<array{recipe:string,mode:string,video:array,audio:array}>
     */
    public function candidates(array $video, array $audio, Capabilities $caps, int $srcW, int $srcH): array
    {
        $out = [];
        $remuxableVideo = $this->videoRemuxable($video);
        $audioIsAac = $this->isAac((string) ($audio['codec'] ?? ''));
        $srcCodec = $this->normalizeVideoCodec((string) ($video['codec'] ?? ''));

        $copyW = $srcW;
        $copyH = $srcH;
        $fitsClient = $this->fitsMax($srcW, $srcH, $caps);

        $mustDownscale = $srcH > 1080 && $caps->maxHeight < $srcH;
        $clientNeedsAvc1080 = $mustDownscale || ($srcCodec === 'hevc' && !$caps->allowsHevc());

        if ($remuxableVideo && $audioIsAac && $fitsClient && !$mustDownscale) {
            if ($srcCodec === 'hevc' && $caps->allowsHevc()) {
                $out[] = $this->offer(Recipe::REMUX, 'remux', 'hvc1', $copyW, $copyH, $audio, 'aac');
            } elseif ($srcCodec === 'h264' && $caps->allowsAvc()) {
                $out[] = $this->offer(Recipe::REMUX, 'remux', 'avc1', $copyW, $copyH, $audio, 'aac');
            }
        }

        if ($remuxableVideo && !$audioIsAac && $fitsClient && !$mustDownscale) {
            if ($srcCodec === 'hevc' && $caps->allowsHevc()) {
                $out[] = $this->offer(Recipe::AUDIO_AAC, 'audio_aac', 'hvc1', $copyW, $copyH, $audio, 'aac');
            } elseif ($srcCodec === 'h264' && $caps->allowsAvc()) {
                $out[] = $this->offer(Recipe::AUDIO_AAC, 'audio_aac', 'avc1', $copyW, $copyH, $audio, 'aac');
            }
        }

        if ($caps->allowsAvc()) {
            $scaled = $this->scale1080($srcW, $srcH);
            $out[] = $this->offer(Recipe::AVC_1080, 'avc_1080', 'avc1', $scaled[0], $scaled[1], $audio, 'aac');
        }

        // If the client cannot take the copy path, avc_1080 is already added.
        // Drop duplicate avc_1080 if somehow added twice.
        $seen = [];
        $uniq = [];
        foreach ($out as $c) {
            if (isset($seen[$c['recipe']])) {
                continue;
            }
            $seen[$c['recipe']] = true;
            $uniq[] = $c;
        }

        if ($clientNeedsAvc1080) {
            // remux / audio_aac already excluded by mustDownscale / hevc checks
        }

        return $uniq;
    }

    /**
     * @param array<string,mixed> $video
     */
    public function videoRemuxable(array $video): bool
    {
        $codec = $this->normalizeVideoCodec((string) ($video['codec'] ?? ''));
        if ($codec !== 'h264' && $codec !== 'hevc') {
            return false;
        }
        $pix = strtolower((string) ($video['pixFmt'] ?? ''));
        if ($pix !== 'yuv420p') {
            return false;
        }
        if (!empty($video['hdr'])) {
            return false;
        }
        $bits = (int) ($video['bits'] ?? 8);
        if ($bits > 8) {
            return false;
        }
        if (str_contains($pix, 'p10') || str_contains($pix, 'p12')) {
            return false;
        }
        return true;
    }

    /** @param list<mixed> $tracks */
    public function audioTrack(array $tracks, int $index): ?array
    {
        foreach ($tracks as $track) {
            if (!is_array($track)) {
                continue;
            }
            if ((int) ($track['index'] ?? -1) === $index) {
                return [
                    'index' => $index,
                    'codec' => (string) ($track['codec'] ?? ''),
                    'channels' => (int) ($track['channels'] ?? 2),
                ];
            }
        }
        return null;
    }

    /** @return list<int> */
    public function availableAudioIndexes(array $meta): array
    {
        $out = [];
        foreach ($meta['audioTracks'] ?? [] as $track) {
            if (is_array($track) && isset($track['index'])) {
                $out[] = (int) $track['index'];
            }
        }
        return $out;
    }

    public function normalizeVideoCodec(string $codec): string
    {
        $c = strtolower($codec);
        return match ($c) {
            'h264', 'avc', 'avc1', 'libx264' => 'h264',
            'hevc', 'h265', 'hvc1', 'hev1', 'libx265' => 'hevc',
            default => $c,
        };
    }

    public function isAac(string $codec): bool
    {
        $c = strtolower($codec);
        return $c === 'aac' || str_starts_with($c, 'mp4a');
    }

    /** @return array{0:int,1:int} */
    public function scale1080(int $width, int $height): array
    {
        if ($width <= 0 || $height <= 0) {
            return [1920, 1080];
        }
        $maxW = 1920;
        $maxH = 1080;
        $scale = min($maxW / $width, $maxH / $height, 1.0);
        $w = (int) floor(($width * $scale) / 2) * 2;
        $h = (int) floor(($height * $scale) / 2) * 2;
        return [max(2, $w), max(2, $h)];
    }

    private function fitsMax(int $w, int $h, Capabilities $caps): bool
    {
        if ($w <= 0 || $h <= 0) {
            return true;
        }
        return $w <= $caps->maxWidth && $h <= $caps->maxHeight;
    }

    /**
     * @param array{index:int,codec:string,channels:int} $audio
     * @return array{recipe:string,mode:string,video:array,audio:array}
     */
    private function offer(string $recipe, string $mode, string $outCodec, int $w, int $h, array $audio, string $outAudio): array
    {
        return [
            'recipe' => $recipe,
            'mode' => $mode,
            'video' => [
                'codec' => $outCodec,
                'width' => $w,
                'height' => $h,
            ],
            'audio' => [
                'index' => $audio['index'],
                'codec' => $outAudio,
                'channels' => $outAudio === 'aac' ? 2 : $audio['channels'],
            ],
        ];
    }

    /** @param list<array<string,mixed>> $variants */
    private function isReady(array $variants, string $recipe, int $audioIndex): bool
    {
        foreach ($variants as $v) {
            if (($v['recipe'] ?? '') === $recipe
                && (int) ($v['audioIndex'] ?? -1) === $audioIndex
                && ($v['state'] ?? '') === 'ready'
            ) {
                return true;
            }
        }
        return false;
    }
}
