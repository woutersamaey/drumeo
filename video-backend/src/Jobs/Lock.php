<?php

declare(strict_types=1);

namespace Drumeo\Video\Jobs;

use Drumeo\Video\Config;

final class Lock
{
    public function __construct(private readonly Config $config)
    {
    }

    public function key(string $id, string $recipe, int $audioIndex): string
    {
        $id = \Drumeo\Video\VideoId::assert($id);
        return $id . '__' . $recipe . '__' . $audioIndex;
    }

    public function path(string $id, string $recipe, int $audioIndex): string
    {
        return $this->config->lockDir() . '/' . $this->key($id, $recipe, $audioIndex) . '.lock';
    }

    /** @return array<string,mixed>|null */
    public function read(int|string $id, string $recipe, int $audioIndex): ?array
    {
        $id = \Drumeo\Video\VideoId::assert($id);
        $path = $this->path($id, $recipe, $audioIndex);
        if (!is_file($path)) {
            return null;
        }
        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    /** @param array<string,mixed> $data */
    public function write(int|string $id, string $recipe, int $audioIndex, array $data): void
    {
        $id = \Drumeo\Video\VideoId::assert($id);
        $dir = $this->config->lockDir();
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($this->path($id, $recipe, $audioIndex), json_encode($data, JSON_UNESCAPED_SLASHES));
    }

    public function release(int|string $id, string $recipe, int $audioIndex): void
    {
        $id = \Drumeo\Video\VideoId::assert($id);
        $path = $this->path($id, $recipe, $audioIndex);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    public function pidAlive(?int $pid): bool
    {
        if ($pid === null || $pid <= 0) {
            return false;
        }
        if (function_exists('posix_kill')) {
            return @posix_kill($pid, 0);
        }
        $result = [];
        $code = 0;
        exec('kill -0 ' . (int) $pid . ' 2>/dev/null', $result, $code);
        return $code === 0;
    }

    public function kill(?int $pid): void
    {
        if ($pid === null || $pid <= 0) {
            return;
        }
        if (function_exists('posix_kill')) {
            @posix_kill($pid, SIGTERM);
            usleep(200000);
            if (@posix_kill($pid, 0)) {
                @posix_kill($pid, SIGKILL);
            }
            return;
        }
        exec('kill ' . (int) $pid . ' 2>/dev/null');
        usleep(200000);
        exec('kill -9 ' . (int) $pid . ' 2>/dev/null');
    }

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        $dir = $this->config->lockDir();
        if (!is_dir($dir)) {
            return [];
        }
        $out = [];
        foreach (scandir($dir) ?: [] as $name) {
            if (!str_ends_with($name, '.lock') || str_starts_with($name, 'meta-')) {
                continue;
            }
            $raw = file_get_contents($dir . '/' . $name);
            $data = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($data)) {
                $out[] = $data;
            }
        }
        return $out;
    }
}
