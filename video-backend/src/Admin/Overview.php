<?php

declare(strict_types=1);

namespace Drumeo\Video\Admin;

use Drumeo\Video\Cleanup\Cleaner;
use Drumeo\Video\Clock;
use Drumeo\Video\Config;
use Drumeo\Video\Jobs\EncoderPicker;
use Drumeo\Video\Jobs\JobRegistry;
use Drumeo\Video\Jobs\Lock;
use Drumeo\Video\Store\HlsCache;
use Drumeo\Video\Store\MetadataStore;
use Drumeo\Video\Store\SourceStore;
use Drumeo\Video\VideoId;

/**
 * Technical snapshot for the admin dashboard.
 * Source bytes are the MKV archive. Scaled bytes are the avc_1080 HLS cache.
 */
final class Overview
{
    private const LARGEST = 8;
    private const CLEANUP_LIST = 40;
    private const RECENT_JOBS = 8;

    public function __construct(
        private readonly Config $config,
        private readonly SourceStore $sources,
        private readonly MetadataStore $meta,
        private readonly HlsCache $hls,
        private readonly Cleaner $cleaner,
        private readonly JobRegistry $jobs,
        private readonly Lock $locks,
        private readonly EncoderPicker $encoders,
        private readonly Clock $clock,
    ) {
    }

    /** @return array<string,mixed> */
    public function collect(): array
    {
        $source = $this->sourceSummary();
        $cache = $this->cacheSummary($source['ids']);
        $byKey = $cache['byKey'];
        $source['probeErrors'] = $cache['probeErrors'];
        $source['unprobed'] = $cache['unprobed'];
        unset($cache['byKey'], $cache['probeErrors'], $cache['unprobed']);

        return [
            'now' => $this->clock->now(),
            'limits' => [
                'maxAgeDays' => $this->config->maxAgeDays,
                'maxCacheBytes' => (int) round($this->config->maxCacheGb * 1024 * 1024 * 1024),
                'maxConcurrentJobs' => $this->config->maxConcurrentJobs,
                'maxConcurrentVideoTranscodes' => $this->config->maxConcurrentVideoTranscodes,
                'jobTimeoutSeconds' => $this->config->jobTimeoutSeconds,
                'vcodec' => $this->config->ffmpegVcodec,
            ],
            'encoder' => $this->encoder(),
            'ffmpeg' => $this->ffmpegOk(),
            'disk' => [
                'source' => $this->disk($this->config->sourceDir),
                'cache' => $this->disk($this->config->cacheDir),
            ],
            'source' => $source,
            'cache' => $cache,
            'meta' => $this->dirStats($this->config->metadataDir),
            'queue' => $this->queue(),
            'jobs' => $this->jobSummary(),
            'cleanup' => $this->cleanupSummary($byKey),
        ];
    }

    /** @return array<string,mixed> */
    private function sourceSummary(): array
    {
        $ids = $this->sources->listIds();
        $bytes = 0;
        $unreadable = 0;
        foreach ($ids as $id) {
            $stat = $this->sources->stat($id);
            if ($stat === null) {
                $unreadable++;
                continue;
            }
            $bytes += (int) $stat['size'];
        }

        return [
            'count' => count($ids),
            'bytes' => $bytes,
            'unreadable' => $unreadable,
            'skippedNames' => $this->skippedSourceNames(),
            'ids' => $ids,
        ];
    }

    private function skippedSourceNames(): int
    {
        $dir = $this->config->sourceDir;
        if (!is_dir($dir)) {
            return 0;
        }
        $n = 0;
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
                continue;
            }
            if (!str_ends_with(strtolower($entry), '.mkv')) {
                continue;
            }
            if (VideoId::extractFromFilename($entry) === null) {
                $n++;
            }
        }
        return $n;
    }

    /**
     * @param list<string> $sourceIds
     * @return array<string,mixed>
     */
    private function cacheSummary(array $sourceIds): array
    {
        $sourceSet = array_fill_keys($sourceIds, true);
        /** @var array<string,?array<string,mixed>> $metaCache */
        $metaCache = [];
        $byRecipe = [
            'avc_1080' => ['count' => 0, 'bytes' => 0],
            'remux' => ['count' => 0, 'bytes' => 0],
            'audio_aac' => ['count' => 0, 'bytes' => 0],
        ];
        /** @var array<string,array{count:int,bytes:int}> $byState */
        $byState = [];
        /** @var array<string,array{count:int,bytes:int}> $byAudio */
        $byAudio = [];
        /** @var array<string,array<string,mixed>> $byKey */
        $byKey = [];
        /** @var list<array<string,mixed>> $rows */
        $rows = [];
        $orphanCount = 0;
        $orphanBytes = 0;

        foreach ($this->hls->listVariantDirs() as $dir) {
            $id = (string) $dir['id'];
            $recipe = (string) $dir['recipe'];
            $audio = (int) $dir['audioIndex'];
            $meta = $this->readMeta($id, $metaCache);
            $variant = is_array($meta) ? $this->meta->findVariant($meta, $recipe, $audio) : null;
            $state = is_array($variant) ? (string) ($variant['state'] ?? '') : '';
            if ($state === '') {
                $state = 'onbekend';
            }
            $lastPlayed = null;
            if (is_array($meta) && is_string($meta['lastPlayedAt'] ?? null) && $meta['lastPlayedAt'] !== '') {
                $lastPlayed = $meta['lastPlayedAt'];
            }
            $hasSource = isset($sourceSet[$id]);
            $bytes = (int) $dir['bytes'];
            $row = [
                'id' => $id,
                'recipe' => $recipe,
                'audioIndex' => $audio,
                'bytes' => $bytes,
                'state' => $state,
                'lastPlayedAt' => $lastPlayed,
                'ageDays' => $this->ageDays($meta, $variant, (int) $dir['mtime']),
                'hasSource' => $hasSource,
            ];
            $key = $id . '|' . $recipe . '|' . $audio;
            $byKey[$key] = $row;
            $rows[] = $row;

            if (!isset($byRecipe[$recipe])) {
                $byRecipe[$recipe] = ['count' => 0, 'bytes' => 0];
            }
            $byRecipe[$recipe]['count']++;
            $byRecipe[$recipe]['bytes'] += $bytes;

            if (!isset($byState[$state])) {
                $byState[$state] = ['count' => 0, 'bytes' => 0];
            }
            $byState[$state]['count']++;
            $byState[$state]['bytes'] += $bytes;

            $audioKey = (string) $audio;
            if (!isset($byAudio[$audioKey])) {
                $byAudio[$audioKey] = ['count' => 0, 'bytes' => 0];
            }
            $byAudio[$audioKey]['count']++;
            $byAudio[$audioKey]['bytes'] += $bytes;

            if (!$hasSource) {
                $orphanCount++;
                $orphanBytes += $bytes;
            }
        }

        $probeErrors = 0;
        $unprobed = 0;
        foreach ($sourceIds as $id) {
            $meta = $this->readMeta($id, $metaCache);
            if (!is_array($meta) || ($meta['video']['codec'] ?? '') === '') {
                $unprobed++;
            }
            if (is_array($meta) && !empty($meta['source']['probeError'])) {
                $probeErrors++;
            }
        }

        usort($rows, static fn (array $a, array $b): int => $b['bytes'] <=> $a['bytes']);
        $largest = [];
        foreach (array_slice($rows, 0, self::LARGEST) as $row) {
            $largest[] = $row;
        }

        $totalBytes = 0;
        $variants = 0;
        foreach ($byRecipe as $bucket) {
            $totalBytes += $bucket['bytes'];
            $variants += $bucket['count'];
        }

        return [
            'bytes' => $totalBytes,
            'variants' => $variants,
            'scaledBytes' => $byRecipe['avc_1080']['bytes'],
            'scaledCount' => $byRecipe['avc_1080']['count'],
            'byRecipe' => $byRecipe,
            'byState' => $byState,
            'byAudio' => $byAudio,
            'orphans' => ['count' => $orphanCount, 'bytes' => $orphanBytes],
            'probeErrors' => $probeErrors,
            'unprobed' => $unprobed,
            'largest' => $largest,
            'byKey' => $byKey,
        ];
    }

    /** @param array<string,array<string,mixed>> $byKey @return array<string,mixed> */
    private function cleanupSummary(array $byKey): array
    {
        $plan = $this->cleaner->plan();
        $byReason = [];
        $items = [];
        $bytes = 0;
        foreach ($plan as $item) {
            $reason = (string) ($item['reason'] ?? '');
            $itemBytes = (int) ($item['bytes'] ?? 0);
            $bytes += $itemBytes;
            if (!isset($byReason[$reason])) {
                $byReason[$reason] = ['count' => 0, 'bytes' => 0];
            }
            $byReason[$reason]['count']++;
            $byReason[$reason]['bytes'] += $itemBytes;
            if (count($items) >= self::CLEANUP_LIST) {
                continue;
            }
            $key = $item['id'] . '|' . $item['recipe'] . '|' . $item['audioIndex'];
            $row = $byKey[$key] ?? null;
            $items[] = [
                'id' => $item['id'],
                'recipe' => $item['recipe'],
                'audioIndex' => $item['audioIndex'],
                'reason' => $reason,
                'bytes' => $itemBytes,
                'state' => is_array($row) ? ($row['state'] ?? '') : '',
                'lastPlayedAt' => is_array($row) ? ($row['lastPlayedAt'] ?? null) : null,
                'ageDays' => is_array($row) ? ($row['ageDays'] ?? null) : null,
                'hasSource' => is_array($row) ? ($row['hasSource'] ?? null) : null,
            ];
        }

        return [
            'count' => count($plan),
            'bytes' => $bytes,
            'maxAgeDays' => $this->config->maxAgeDays,
            'byReason' => $byReason,
            'items' => $items,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function queue(): array
    {
        $out = [];
        foreach ($this->locks->all() as $lock) {
            $pid = $lock['pid'] ?? null;
            $state = (string) ($lock['state'] ?? '');
            if ($state === '') {
                $state = (is_int($pid) && $pid > 0) ? 'running' : 'queued';
            }
            $out[] = [
                'videoId' => (string) ($lock['videoId'] ?? ''),
                'recipe' => (string) ($lock['recipe'] ?? ''),
                'audioIndex' => (int) ($lock['audioIndex'] ?? 0),
                'state' => $state,
                'intent' => (string) ($lock['intent'] ?? ''),
                'pid' => is_int($pid) ? $pid : null,
                'startedAt' => isset($lock['startedAt']) && is_string($lock['startedAt']) ? $lock['startedAt'] : null,
                'queuedAt' => isset($lock['queuedAt']) && is_string($lock['queuedAt']) ? $lock['queuedAt'] : null,
            ];
        }
        return $out;
    }

    /** @return array{active:list<array<string,mixed>>,recent:list<array<string,mixed>>} */
    private function jobSummary(): array
    {
        $active = [];
        $done = [];
        foreach ($this->jobs->all() as $job) {
            $row = $this->publicJob($job);
            $exit = $job['exitCode'] ?? null;
            $state = (string) ($job['state'] ?? '');
            $live = $exit === null && in_array($state, ['queued', 'running', 'starting'], true);
            if ($live) {
                $active[] = $row;
            } else {
                $done[] = $row;
            }
        }
        usort($done, static function (array $a, array $b): int {
            return strcmp((string) ($b['startedAt'] ?? ''), (string) ($a['startedAt'] ?? ''));
        });

        return [
            'active' => $active,
            'recent' => array_slice($done, 0, self::RECENT_JOBS),
        ];
    }

    /** @param array<string,mixed> $job @return array<string,mixed> */
    private function publicJob(array $job): array
    {
        return [
            'jobId' => (string) ($job['jobId'] ?? ''),
            'videoId' => (string) ($job['videoId'] ?? ''),
            'recipe' => (string) ($job['recipe'] ?? ''),
            'audioIndex' => isset($job['audioIndex']) ? (int) $job['audioIndex'] : null,
            'state' => (string) ($job['state'] ?? ''),
            'intent' => (string) ($job['intent'] ?? ''),
            'pid' => isset($job['pid']) && is_int($job['pid']) ? $job['pid'] : null,
            'startedAt' => isset($job['startedAt']) && is_string($job['startedAt']) ? $job['startedAt'] : null,
            'exitCode' => array_key_exists('exitCode', $job) && $job['exitCode'] !== null ? (int) $job['exitCode'] : null,
        ];
    }

    /** @return array{encoder:?string,requested:string,error:?string} */
    private function encoder(): array
    {
        try {
            $choice = $this->encoders->choice();
            return [
                'encoder' => isset($choice['encoder']) ? (string) $choice['encoder'] : null,
                'requested' => (string) ($choice['requested'] ?? $this->config->ffmpegVcodec),
                'error' => null,
            ];
        } catch (\Throwable $e) {
            return [
                'encoder' => null,
                'requested' => $this->config->ffmpegVcodec,
                'error' => $e->getMessage(),
            ];
        }
    }

    private function ffmpegOk(): bool
    {
        $bin = $this->config->ffmpegBin;
        if ($bin === '' || !function_exists('exec')) {
            return false;
        }
        $out = [];
        $code = 1;
        @exec(escapeshellcmd($bin) . ' -version 2>&1', $out, $code);
        return $code === 0;
    }

    /** @return array{exists:bool,freeBytes:?int,totalBytes:?int} */
    private function disk(string $dir): array
    {
        if (!is_dir($dir)) {
            return ['exists' => false, 'freeBytes' => null, 'totalBytes' => null];
        }
        $free = @disk_free_space($dir);
        $total = @disk_total_space($dir);
        return [
            'exists' => true,
            'freeBytes' => $free === false ? null : (int) $free,
            'totalBytes' => $total === false ? null : (int) $total,
        ];
    }

    /** @return array{bytes:int,files:int,exists:bool} */
    private function dirStats(string $dir): array
    {
        if (!is_dir($dir)) {
            return ['bytes' => 0, 'files' => 0, 'exists' => false];
        }
        $bytes = 0;
        $files = 0;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if ($file->isFile()) {
                $bytes += $file->getSize();
                $files++;
            }
        }
        return ['bytes' => $bytes, 'files' => $files, 'exists' => true];
    }

    /** @param array<string,?array<string,mixed>> $cache */
    private function readMeta(string $id, array &$cache): ?array
    {
        if (array_key_exists($id, $cache)) {
            return $cache[$id];
        }
        if (!VideoId::isValid($id)) {
            $cache[$id] = null;
            return null;
        }
        $cache[$id] = $this->meta->read($id);
        return $cache[$id];
    }

    /** @param array<string,mixed>|null $meta @param array<string,mixed>|null $variant */
    private function ageDays(?array $meta, ?array $variant, int $mtime): int
    {
        $unix = $mtime;
        $iso = is_array($meta) ? ($meta['lastPlayedAt'] ?? null) : null;
        if (!is_string($iso) || $iso === '') {
            $iso = is_array($variant) ? ($variant['lastAccessAt'] ?? null) : null;
        }
        if (is_string($iso) && $iso !== '') {
            $t = strtotime($iso);
            if ($t !== false) {
                $unix = $t;
            }
        }
        $age = $this->clock->unix() - $unix;
        if ($age < 0) {
            return 0;
        }
        return (int) floor($age / 86400);
    }
}
