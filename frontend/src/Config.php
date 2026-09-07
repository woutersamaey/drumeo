<?php

declare(strict_types=1);

namespace Drumeo\App;

final class Config
{
    public function __construct(
        public readonly string $dbHost,
        public readonly int $dbPort,
        public readonly string $dbName,
        public readonly string $dbUser,
        public readonly string $dbPass,
        public readonly string $redisHost,
        public readonly int $redisPort,
        public readonly string $lessonsDir,
        public readonly string $mediaDir,
        public readonly string $imageCacheDir,
        public readonly string $notationPath,
        public readonly string $cookieName = 'drumeo_profile',
    ) {
    }

    public static function fromEnv(): self
    {
        $root = dirname(__DIR__);
        return new self(
            dbHost: self::env('DB_HOST', 'mysql'),
            dbPort: (int) self::env('DB_PORT', '3306'),
            dbName: self::env('DB_NAME', 'drumeo'),
            dbUser: self::env('DB_USER', 'drumeo'),
            dbPass: self::env('DB_PASS', 'drumeo'),
            redisHost: self::env('REDIS_HOST', 'redis'),
            redisPort: (int) self::env('REDIS_PORT', '6379'),
            lessonsDir: self::env('LESSONS_DIR', $root . '/lessons'),
            mediaDir: self::env('MEDIA_DIR', '/media/nas'),
            imageCacheDir: self::env('IMAGE_CACHE_DIR', '/media/img-cache'),
            notationPath: self::env('NOTATION_PATH', $root . '/notation.pdf'),
        );
    }

    private static function env(string $key, string $default): string
    {
        $v = getenv($key);
        return ($v === false || $v === '') ? $default : $v;
    }
}
