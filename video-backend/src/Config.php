<?php

declare(strict_types=1);

namespace Drumeo\Video;

final class Config
{
    public function __construct(
        public readonly string $sourceDir,
        public readonly string $cacheDir,
        public readonly string $metadataDir,
        public readonly string $apiHost = '0.0.0.0',
        public readonly int $apiPort = 8080,
        public readonly string $apiToken = '',
        public readonly string $ffmpegBin = 'ffmpeg',
        public readonly string $ffprobeBin = 'ffprobe',
        public readonly string $ffmpegVcodec = 'auto',
        public readonly int $maxConcurrentJobs = 2,
        public readonly int $maxConcurrentVideoTranscodes = 1,
        public readonly int $maxVariantFails = 3,
        public readonly int $prepareMinSegments = 2,
        public readonly int $jobTimeoutSeconds = 14400,
        public readonly int $maxAgeDays = 90,
        public readonly float $maxCacheGb = 80.0,
        public readonly string $publicHlsBase = '/hls',
        public readonly string $tmpDir = '/tmp',
        public readonly string $playerHlsJsPath = '',
    ) {
    }

    public static function fromEnv(): self
    {
        $root = dirname(__DIR__);
        $player = $root . '/resources/player/hls.min.js';

        return new self(
            sourceDir: self::env('SOURCE_DIR', '/media/bron'),
            cacheDir: self::env('CACHE_DIR', '/media/cache'),
            metadataDir: self::env('METADATA_DIR', '/media/meta'),
            apiHost: self::env('API_HOST', '0.0.0.0'),
            apiPort: (int) self::env('API_PORT', '8080'),
            apiToken: self::env('API_TOKEN', ''),
            ffmpegBin: self::env('FFMPEG_BIN', 'ffmpeg'),
            ffprobeBin: self::env('FFPROBE_BIN', 'ffprobe'),
            ffmpegVcodec: self::env('FFMPEG_VCODEC', 'auto'),
            maxConcurrentJobs: (int) self::env('MAX_CONCURRENT_JOBS', '2'),
            maxConcurrentVideoTranscodes: (int) self::env('MAX_CONCURRENT_VIDEO_TRANSCODES', '1'),
            maxVariantFails: (int) self::env('MAX_VARIANT_FAILS', '3'),
            prepareMinSegments: (int) self::env('PREPARE_MIN_SEGMENTS', '2'),
            jobTimeoutSeconds: (int) self::env('JOB_TIMEOUT_SECONDS', '14400'),
            maxAgeDays: (int) self::env('MAX_AGE_DAYS', '90'),
            maxCacheGb: (float) self::env('MAX_CACHE_GB', '80'),
            publicHlsBase: rtrim(self::env('PUBLIC_HLS_BASE', '/hls'), '/'),
            tmpDir: self::env('TMPDIR', '/tmp'),
            playerHlsJsPath: self::env('PLAYER_HLS_JS', $player),
        );
    }

    public function ensureDirectories(): void
    {
        foreach ([$this->cacheDir, $this->metadataDir, $this->lockDir(), $this->jobDir()] as $dir) {
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new \RuntimeException('Cannot create directory: ' . $dir);
            }
        }
    }

    public function lockDir(): string
    {
        return rtrim($this->tmpDir, '/') . '/vb-locks';
    }

    public function jobDir(): string
    {
        return rtrim($this->tmpDir, '/') . '/vb-jobs';
    }

    public function sourceIndexPath(): string
    {
        return rtrim($this->tmpDir, '/') . '/vb-source-index.json';
    }

    private static function env(string $key, string $default): string
    {
        $value = getenv($key);
        if ($value === false || $value === '') {
            return $default;
        }
        return $value;
    }
}
