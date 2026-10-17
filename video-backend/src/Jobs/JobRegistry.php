<?php

declare(strict_types=1);

namespace Drumeo\Video\Jobs;

use Drumeo\Video\Config;
use Drumeo\Video\Store\AtomicJson;

/**
 * Debug/admin history of jobs ({jobId}.json). The lock files are the live state;
 * these records are pruned by the worker.
 */
final class JobRegistry
{
    /** Finished job records are kept this long. */
    public const KEEP_FINISHED_SECONDS = 7 * 86400;

    /** Queued/running records without a lock are only pruned after this grace period. */
    public const ORPHAN_GRACE_SECONDS = 600;

    public const LIVE_STATES = ['queued', 'running', 'starting'];

    public function __construct(private readonly Config $config)
    {
    }

    /** @param array<string,mixed> $job */
    public function save(array $job): void
    {
        $id = (string) $job['jobId'];
        if (!$this->validId($id)) {
            throw new \InvalidArgumentException('Invalid job id');
        }
        AtomicJson::write($this->path($id), $job);
    }

    /** @return array<string,mixed>|null */
    public function get(string $jobId): ?array
    {
        if (!$this->validId($jobId)) {
            return null;
        }
        return AtomicJson::read($this->path($jobId));
    }

    /**
     * Merge fields into a record (created if it was pruned). Empty jobId: no-op.
     *
     * @param array<string,mixed> $fields
     */
    public function patch(?string $jobId, array $fields): void
    {
        if ($jobId === null || $jobId === '' || !$this->validId($jobId)) {
            return;
        }
        $job = $this->get($jobId) ?? ['jobId' => $jobId];
        $this->save(array_merge($job, $fields));
    }

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        $dir = $this->config->jobDir();
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

    /**
     * Remove finished records older than KEEP_FINISHED_SECONDS and queued/running
     * records that no lock refers to anymore.
     *
     * @param array<string,true> $liveJobIds jobIds referenced by current lock files
     * @return int number of records removed
     */
    public function prune(array $liveJobIds, int $now): int
    {
        $dir = $this->config->jobDir();
        if (!is_dir($dir)) {
            return 0;
        }
        $removed = 0;
        foreach (scandir($dir) ?: [] as $name) {
            if (!str_ends_with($name, '.json') || str_starts_with($name, '.')) {
                continue;
            }
            $path = $dir . '/' . $name;
            $mtime = @filemtime($path);
            if ($mtime === false) {
                continue;
            }
            $job = AtomicJson::read($path);
            $jobId = substr($name, 0, -5);
            $state = is_array($job) ? (string) ($job['state'] ?? '') : '';
            $age = $now - $mtime;
            $live = in_array($state, self::LIVE_STATES, true);
            $drop = $live
                ? !isset($liveJobIds[$jobId]) && $age > self::ORPHAN_GRACE_SECONDS
                : $age > self::KEEP_FINISHED_SECONDS;
            if ($drop && @unlink($path)) {
                $removed++;
            }
        }
        return $removed;
    }

    public function newId(): string
    {
        return bin2hex(random_bytes(8));
    }

    private function path(string $jobId): string
    {
        return $this->config->jobDir() . '/' . $jobId . '.json';
    }

    private function validId(string $jobId): bool
    {
        return preg_match('/^[a-f0-9]+$/', $jobId) === 1;
    }
}
