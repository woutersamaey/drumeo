<?php

declare(strict_types=1);

namespace Drumeo\Video\Store;

final class AtomicJson
{
    /**
     * @param array<string,mixed> $data
     */
    public static function write(string $path, array $data): void
    {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($json === false) {
            throw new \RuntimeException('JSON encode failed');
        }
        self::writeFile($path, $json . "\n");
    }

    /**
     * Tempfile in the same directory + rename, so readers (php, nginx) never see a partial file.
     */
    public static function writeFile(string $path, string $contents): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create directory: ' . $dir);
        }
        $tmp = $dir . '/.' . basename($path) . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (file_put_contents($tmp, $contents) === false) {
            throw new \RuntimeException('Cannot write tempfile: ' . $tmp);
        }
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException('Cannot rename tempfile onto ' . $path);
        }
    }

    /** @return array<string,mixed>|null */
    public static function read(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }
}
