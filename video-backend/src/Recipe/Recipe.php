<?php

declare(strict_types=1);

namespace Drumeo\Video\Recipe;

final class Recipe
{
    public const REMUX = 'remux';
    public const AUDIO_AAC = 'audio_aac';
    public const AVC_1080 = 'avc_1080';

    public const ALL = [self::REMUX, self::AUDIO_AAC, self::AVC_1080];

    public static function isValid(string $recipe): bool
    {
        return in_array($recipe, self::ALL, true);
    }

    public static function weight(string $recipe): int
    {
        return match ($recipe) {
            self::REMUX => 0,
            self::AUDIO_AAC => 1,
            self::AVC_1080 => 2,
            default => 99,
        };
    }

    public static function isVideoTranscode(string $recipe): bool
    {
        return $recipe === self::AVC_1080;
    }
}
