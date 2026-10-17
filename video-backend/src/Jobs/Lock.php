<?php

declare(strict_types=1);

namespace Drumeo\Video\Jobs;

use Drumeo\Video\Config;
use Drumeo\Video\Recipe\Recipe;
use Drumeo\Video\Store\AtomicJson;
use Drumeo\Video\Store\FileMutex;
use Drumeo\Video\VideoId;

/**
 * One JSON lock per (video, recipe, audioIndex) while a job is queued or running.
 *
 * Every read-modify-write goes through update(), which holds a per-key flock.
 * Files are replaced atomically, so plain read()/all() never see a partial lock.
 * The api only ever creates queued locks or patches intent/cancel; pid,
 * startedUnix and heartbeatUnix are written by the worker alone.
 */
final class Lock
{
    /** Worker refreshes heartbeatUnix at least this often while a job runs. */
    public const HEARTBEAT_EVERY = 5;

    /** A running lock whose heartbeat is older than this is reported as not active. */
    public const STALE_AFTER = 30;

    public function __construct(private readonly Config $config)
    {
    }

    public function key(string $id, string $recipe, int $audioIndex): string
    {
        $id = VideoId::assert($id);
        if (!Recipe::isValid($recipe) || $audioIndex < 0) {
            throw new \InvalidArgumentException('Invalid recipe or audio index');
        }
        return $id . '__' . $recipe . '__' . $audioIndex;
    }

    public function path(string $id, string $recipe, int $audioIndex): string
    {
        return $this->config->lockDir() . '/' . $this->key($id, $recipe, $audioIndex) . '.json';
    }

    /** @return array<string,mixed>|null */
    public function read(string $id, string $recipe, int $audioIndex): ?array
    {
        return AtomicJson::read($this->path($id, $recipe, $audioIndex));
    }

    /**
     * Locked read-modify-write. The callback gets the current lock (or null) and
     * returns the new lock, or null to remove it.
     *
     * @param callable(?array<string,mixed>):?array<string,mixed> $fn
     * @return array<string,mixed>|null the lock as stored afterwards
     */
    public function update(string $id, string $recipe, int $audioIndex, callable $fn): ?array
    {
        $path = $this->path($id, $recipe, $audioIndex);
        $mutex = $this->config->lockDir() . '/' . $this->key($id, $recipe, $audioIndex) . '.mutex';
        return FileMutex::run($mutex, function () use ($path, $fn): ?array {
            $current = AtomicJson::read($path);
            $next = $fn($current);
            if ($next === $current) {
                return $current;
            }
            if ($next === null) {
                @unlink($path);
                return null;
            }
            AtomicJson::write($path, $next);
            return $next;
        });
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
            if (!str_ends_with($name, '.json') || str_starts_with($name, '.')) {
                continue;
            }
            $data = AtomicJson::read($dir . '/' . $name);
            if (is_array($data)) {
                $out[] = $data;
            }
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    public function forVideo(string $id): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn (array $lock): bool => (string) ($lock['videoId'] ?? '') === $id,
        ));
    }

    /**
     * Queued locks count as active. Running locks need a recent worker heartbeat;
     * pids are meaningless here because api and worker have separate pid namespaces.
     *
     * @param array<string,mixed> $lock
     */
    public static function isFresh(array $lock, int $now): bool
    {
        if (!is_int($lock['pid'] ?? null)) {
            return true;
        }
        return $now - self::heartbeatOf($lock) <= self::STALE_AFTER;
    }

    /** @param array<string,mixed> $lock */
    public static function heartbeatOf(array $lock): int
    {
        return (int) ($lock['heartbeatUnix'] ?? $lock['startedUnix'] ?? 0);
    }
}
