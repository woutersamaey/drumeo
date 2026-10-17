<?php

declare(strict_types=1);

namespace Drumeo\Video\Store;

/**
 * Exclusive flock on a small, never-renamed file. The api and worker containers
 * share the lock directory volume, so this serialises them too.
 */
final class FileMutex
{
    /**
     * @template T
     * @param callable():T $fn
     * @return T
     */
    public static function run(string $path, callable $fn): mixed
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create lock directory: ' . $dir);
        }
        $fp = fopen($path, 'c');
        if ($fp === false) {
            throw new \RuntimeException('Cannot open lock ' . $path);
        }
        try {
            if (!flock($fp, LOCK_EX)) {
                throw new \RuntimeException('Cannot lock ' . $path);
            }
            return $fn();
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }
}
