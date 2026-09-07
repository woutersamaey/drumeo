<?php

declare(strict_types=1);

namespace Drumeo\Video\Recipe;

final class Capabilities
{
    /**
     * @param list<string> $protocols
     * @param list<string> $videoCodecs
     * @param list<string> $audioCodecs
     */
    public function __construct(
        public readonly array $protocols,
        public readonly array $videoCodecs,
        public readonly array $audioCodecs,
        public readonly int $maxWidth,
        public readonly int $maxHeight,
        public readonly bool $hdr,
        public readonly bool $nativeHls,
    ) {
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        $protocols = self::stringList($data['protocols'] ?? ['hls']);
        $video = self::stringList($data['videoCodecs'] ?? ['avc1']);
        $audio = self::stringList($data['audioCodecs'] ?? ['mp4a.40.2']);
        if ($protocols === []) {
            $protocols = ['hls'];
        }
        return new self(
            protocols: $protocols,
            videoCodecs: $video,
            audioCodecs: $audio,
            maxWidth: (int) ($data['maxWidth'] ?? 1920),
            maxHeight: (int) ($data['maxHeight'] ?? 1080),
            hdr: (bool) ($data['hdr'] ?? false),
            nativeHls: (bool) ($data['nativeHls'] ?? false),
        );
    }

    public function allowsHls(): bool
    {
        return in_array('hls', $this->protocols, true);
    }

    public function allowsHevc(): bool
    {
        return $this->hasVideo('hvc1') || $this->hasVideo('hev1') || $this->hasVideo('hevc');
    }

    public function allowsAvc(): bool
    {
        return $this->hasVideo('avc1') || $this->hasVideo('avc') || $this->hasVideo('h264');
    }

    public function allowsAac(): bool
    {
        foreach ($this->audioCodecs as $c) {
            if ($c === 'mp4a.40.2' || $c === 'aac' || str_starts_with($c, 'mp4a')) {
                return true;
            }
        }
        return false;
    }

    private function hasVideo(string $codec): bool
    {
        foreach ($this->videoCodecs as $c) {
            if (strcasecmp($c, $codec) === 0) {
                return true;
            }
        }
        return false;
    }

    /** @return list<string> */
    private static function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if (is_string($item) && $item !== '') {
                $out[] = $item;
            }
        }
        return $out;
    }
}
