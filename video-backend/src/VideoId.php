<?php

declare(strict_types=1);

namespace Drumeo\Video;

final class VideoId
{
    public const PATTERN = '/^[a-zA-Z0-9_-]+$/';

    public static function normalize(mixed $id): string
    {
        if (is_int($id) || is_float($id)) {
            return (string) $id;
        }
        return trim((string) $id);
    }

    public static function isValid(mixed $id): bool
    {
        $id = self::normalize($id);
        return $id !== '' && preg_match(self::PATTERN, $id) === 1 && !str_contains($id, '..');
    }

    public static function assert(mixed $id): string
    {
        $id = self::normalize($id);
        if (!self::isValid($id)) {
            throw new \InvalidArgumentException('Invalid video id');
        }
        return $id;
    }

    public static function extractFromFilename(string $filename): ?string
    {
        $base = basename($filename);
        if (preg_match('/\[([a-zA-Z0-9_-]+)\]\.(mkv|jpg|jpeg|png)$/i', $base, $m)) {
            return $m[1];
        }
        if (preg_match('/^([a-zA-Z0-9_-]+)\.(mkv|jpg|jpeg|png)$/i', $base, $m)) {
            return $m[1];
        }
        return null;
    }
}
