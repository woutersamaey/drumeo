<?php

declare(strict_types=1);

namespace Drumeo\Video\Jobs;

use Drumeo\Video\Config;
use Drumeo\Video\Store\AtomicJson;

final class JobRegistry
{
    public function __construct(private readonly Config $config)
    {
    }

    /** @param array<string,mixed> $job */
    public function save(array $job): void
    {
        $dir = $this->config->jobDir();
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $id = (string) $job['jobId'];
        AtomicJson::write($dir . '/' . $id . '.json', $job);
    }

    /** @return array<string,mixed>|null */
    public function get(string $jobId): ?array
    {
        if (!preg_match('/^[a-f0-9]+$/', $jobId)) {
            return null;
        }
        return AtomicJson::read($this->config->jobDir() . '/' . $jobId . '.json');
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
            if (!str_ends_with($name, '.json')) {
                continue;
            }
            $data = AtomicJson::read($dir . '/' . $name);
            if (is_array($data)) {
                $out[] = $data;
            }
        }
        return $out;
    }

    public function newId(): string
    {
        return bin2hex(random_bytes(8));
    }
}
