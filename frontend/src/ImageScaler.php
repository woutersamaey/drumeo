<?php

declare(strict_types=1);

namespace Drumeo\App;

final class ImageScaler
{
    public const WIDTHS = [160, 320, 640, 960, 1280, 1920];
    public const FORMATS = ['webp', 'jpg'];
    private const WEBP_QUALITY = 85;
    private const JPEG_QUALITY = 86;

    /** @var callable(string):(?string) */
    private $sourceFinder;

    public function __construct(
        private readonly string $cacheDir,
        callable $sourceFinder,
    ) {
        $this->sourceFinder = $sourceFinder;
    }

    public static function fromCatalog(Catalog $catalog, string $cacheDir): self
    {
        return new self($cacheDir, static function (string $id) use ($catalog): ?string {
            $jpg = $catalog->mediaPath($id, 'jpg');
            if ($jpg !== null) {
                return $jpg;
            }
            return $catalog->mediaPath($id, 'png');
        });
    }

    public static function isAllowedWidth(int $width): bool
    {
        return in_array($width, self::WIDTHS, true);
    }

    public static function isAllowedFormat(string $format): bool
    {
        return in_array($format, self::FORMATS, true);
    }

    public function cachePath(string $id, int $width, string $format): string
    {
        return $this->cacheDir . '/img/' . $id . '/' . $width . '.' . $format;
    }

    public function ensure(string $id, int $width, string $format): ?string
    {
        if (!preg_match('/^[a-zA-Z0-9_-]{1,32}$/', $id) || !self::isAllowedWidth($width) || !self::isAllowedFormat($format)) {
            return null;
        }
        if ($format === 'webp' && !$this->supportsWebp()) {
            $format = 'jpg';
        }
        $dest = $this->cachePath($id, $width, $format);
        if (is_file($dest) && filesize($dest) > 32) {
            return $dest;
        }
        $source = ($this->sourceFinder)($id);
        if ($source === null || !is_file($source)) {
            return null;
        }
        $dir = dirname($dest);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return null;
        }
        $lockPath = $dest . '.lock';
        $lock = @fopen($lockPath, 'c');
        if ($lock === false) {
            return null;
        }
        try {
            flock($lock, LOCK_EX);
            if (is_file($dest) && filesize($dest) > 32) {
                return $dest;
            }
            if (!$this->writeVariant($source, $dest, $width, $format)) {
                return null;
            }
            return $dest;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
            @unlink($lockPath);
        }
    }

    public function supportsWebp(): bool
    {
        return function_exists('imagewebp');
    }

    private function writeVariant(string $source, string $dest, int $width, string $format): bool
    {
        $src = $this->load($source);
        if ($src === false) {
            return false;
        }
        $srcW = imagesx($src);
        $srcH = imagesy($src);
        if ($srcW < 1 || $srcH < 1) {
            return false;
        }
        $targetW = min($width, $srcW);
        $targetH = max(1, (int) round($srcH * ($targetW / $srcW)));
        $dst = false;
        if (defined('IMG_BICUBIC_FIXED')) {
            $dst = @imagescale($src, $targetW, $targetH, IMG_BICUBIC_FIXED);
        }
        if ($dst === false) {
            $dst = imagescale($src, $targetW, $targetH);
        }
        unset($src);
        if ($dst === false) {
            return false;
        }
        $tmp = $dest . '.tmp';
        $ok = $format === 'webp'
            ? imagewebp($dst, $tmp, self::WEBP_QUALITY)
            : imagejpeg($dst, $tmp, self::JPEG_QUALITY);
        unset($dst);
        if (!$ok || !is_file($tmp)) {
            @unlink($tmp);
            return false;
        }
        if (!@rename($tmp, $dest)) {
            @unlink($tmp);
            return false;
        }
        @chmod($dest, 0664);
        return true;
    }

    /** @return \GdImage|false */
    private function load(string $path): mixed
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return match ($ext) {
            'png' => @imagecreatefrompng($path),
            'webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            default => @imagecreatefromjpeg($path),
        };
    }
}
