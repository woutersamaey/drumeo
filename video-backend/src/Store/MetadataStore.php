<?php

declare(strict_types=1);

namespace Drumeo\Video\Store;

use Drumeo\Video\Clock;
use Drumeo\Video\Config;
use Drumeo\Video\Hls\MediaPlaylist;
use Drumeo\Video\Jobs\Lock;
use Drumeo\Video\Probe\Probe;
use Drumeo\Video\VideoId;

final class MetadataStore
{
    public function __construct(
        private readonly Config $config,
        private readonly SourceStore $sources,
        private readonly Probe $probe,
        private readonly Clock $clock,
        private readonly HlsCache $hls,
        private readonly Lock $locks,
    ) {
    }

    public function pathFor(string $id): string
    {
        $id = VideoId::assert($id);
        return rtrim($this->config->metadataDir, '/') . '/' . $id . '.json';
    }

    /** @return array<string,mixed>|null */
    public function read(string $id): ?array
    {
        return AtomicJson::read($this->pathFor($id));
    }

    /**
     * Load-or-probe. Returns metadata or null if the MKV is missing.
     * ffprobe runs outside the metadata lock; its result is applied under it.
     *
     * @return array<string,mixed>|null
     */
    public function load(string $id, bool $forceProbe = false): ?array
    {
        VideoId::assert($id);
        $stat = $this->sources->stat($id);
        if ($stat === null) {
            return null;
        }
        $snapshot = $this->read($id) ?? $this->initialMeta($id, $stat);
        $sourceChanged = $this->sourceChanged($snapshot, $stat);
        if ($sourceChanged && $this->locks->forVideo($id) !== []) {
            // A job is still writing this video's cache; invalidate once it is done.
            return $this->publicMeta($snapshot);
        }

        $failCount = $sourceChanged ? 0 : (int) ($snapshot['source']['failCount'] ?? 0);
        $frozen = !$forceProbe && !$sourceChanged
            && !empty($snapshot['source']['probeError'])
            && $failCount >= $this->config->maxVariantFails;
        $needProbe = !$frozen && ($forceProbe || $sourceChanged || empty($snapshot['video']['codec']));

        $probed = null;
        $probeError = null;
        if ($needProbe) {
            try {
                $probed = $this->probe->inspect($stat['path']);
            } catch (\Throwable $e) {
                $probeError = $e->getMessage();
            }
        }

        $meta = $this->mutate($id, function (?array $current) use ($id, $stat, $probed, $probeError): array {
            $restored = $current === null;
            $meta = $current ?? $this->initialMeta($id, $stat);
            if (!$restored && $this->sourceChanged($meta, $stat)) {
                if ($this->locks->forVideo($id) !== []) {
                    return $meta;
                }
                $this->hls->deleteAllFor($id);
                $meta['variants'] = [];
                $meta['source']['failCount'] = 0;
                $meta['source']['probeError'] = null;
            }
            if ($probed !== null) {
                $meta = $this->applyProbe($id, $meta, $stat, $probed);
            } elseif ($probeError !== null) {
                $meta['source'] = [
                    'mtimeMs' => $stat['mtimeMs'],
                    'size' => $stat['size'],
                    'probeError' => $probeError,
                    'failCount' => (int) ($meta['source']['failCount'] ?? 0) + 1,
                ];
            }
            return $this->syncVariantsFromDisk($id, $meta);
        });
        return $this->publicMeta($meta);
    }

    /**
     * Locked read-modify-write. If the JSON is missing it starts from the cache on
     * disk (or a blank record); it never probes.
     *
     * @param callable(array):array $mutator
     * @return array<string,mixed>
     */
    public function update(string $id, callable $mutator): array
    {
        return $this->mutate($id, function (?array $current) use ($id, $mutator): array {
            if ($current === null) {
                $stat = $this->sources->stat($id);
                if ($stat === null) {
                    throw new \RuntimeException('Video not found: ' . $id);
                }
                $current = $this->initialMeta($id, $stat);
            }
            return $mutator($current);
        });
    }

    /** @param array<string,mixed> $meta */
    public function findVariant(array $meta, string $recipe, int $audioIndex): ?array
    {
        foreach ($meta['variants'] ?? [] as $variant) {
            if (!is_array($variant)) {
                continue;
            }
            if (($variant['recipe'] ?? '') === $recipe && (int) ($variant['audioIndex'] ?? -1) === $audioIndex) {
                return $variant;
            }
        }
        return null;
    }

    /**
     * @param array<string,mixed> $meta
     * @param array<string,mixed> $variant
     * @return array<string,mixed>
     */
    public function upsertVariant(array $meta, array $variant): array
    {
        $variants = [];
        $found = false;
        foreach ($meta['variants'] ?? [] as $existing) {
            if (!is_array($existing)) {
                continue;
            }
            if (($existing['recipe'] ?? '') === ($variant['recipe'] ?? '')
                && (int) ($existing['audioIndex'] ?? -1) === (int) ($variant['audioIndex'] ?? -1)
            ) {
                $variants[] = $variant;
                $found = true;
            } else {
                $variants[] = $existing;
            }
        }
        if (!$found) {
            $variants[] = $variant;
        }
        $meta['variants'] = $variants;
        return $meta;
    }

    /**
     * @param array<string,mixed> $meta
     * @return array<string,mixed>
     */
    public function removeVariant(array $meta, string $recipe, int $audioIndex): array
    {
        $keep = [];
        foreach ($meta['variants'] ?? [] as $existing) {
            if (!is_array($existing)) {
                continue;
            }
            if (($existing['recipe'] ?? '') === $recipe && (int) ($existing['audioIndex'] ?? -1) === $audioIndex) {
                continue;
            }
            $keep[] = $existing;
        }
        $meta['variants'] = $keep;
        return $meta;
    }

    /** @param array<string,mixed> $meta */
    public function write(string $id, array $meta): void
    {
        $meta['id'] = $id;
        unset($meta['sourcePath'], $meta['_internal']);
        AtomicJson::write($this->pathFor($id), $meta);
    }

    /**
     * @param callable(?array):array $fn
     * @return array<string,mixed>
     */
    private function mutate(string $id, callable $fn): array
    {
        $id = VideoId::assert($id);
        return FileMutex::run($this->config->lockDir() . '/meta-' . $id . '.lock', function () use ($id, $fn): array {
            $current = $this->read($id);
            $next = $fn($current);
            $next['id'] = $id;
            unset($next['sourcePath'], $next['_internal']);
            if ($next !== $current) {
                $this->write($id, $next);
            }
            return $next;
        });
    }

    /**
     * @param array<string,mixed> $meta
     * @param array<string,mixed> $stat
     */
    private function sourceChanged(array $meta, array $stat): bool
    {
        return (int) ($meta['source']['mtimeMs'] ?? 0) !== $stat['mtimeMs']
            || (int) ($meta['source']['size'] ?? 0) !== $stat['size'];
    }

    /**
     * @param array<string,mixed> $meta
     * @param array<string,mixed> $stat
     * @param array<string,mixed> $probed
     * @return array<string,mixed>
     */
    private function applyProbe(string $id, array $meta, array $stat, array $probed): array
    {
        $meta['id'] = $id;
        $meta['source'] = [
            'mtimeMs' => $stat['mtimeMs'],
            'size' => $stat['size'],
            'probeError' => null,
            'failCount' => 0,
        ];
        $meta['durationSec'] = $probed['durationSec'];
        $meta['video'] = [
            'index' => $probed['video']['index'],
            'codec' => $probed['video']['codec'],
            'width' => $probed['video']['width'],
            'height' => $probed['video']['height'],
            'pixFmt' => $probed['video']['pixFmt'],
            'bits' => $probed['video']['bits'],
            'hdr' => $probed['video']['hdr'],
            'fps' => $probed['video']['fps'],
        ];
        $meta['audioTracks'] = array_map(static fn (array $t): array => [
            'index' => $t['index'],
            'codec' => $t['codec'],
            'channels' => $t['channels'],
        ], $probed['audioTracks']);
        return $meta;
    }

    /**
     * Record for a video without JSON: blank, plus finished HLS variants found on disk.
     *
     * @param array<string,mixed> $stat
     * @return array<string,mixed>
     */
    private function initialMeta(string $id, array $stat): array
    {
        $meta = $this->blank($id, $stat);
        foreach ($this->hls->listVariantDirs() as $dir) {
            if ($dir['id'] !== $id) {
                continue;
            }
            $info = $this->hls->inspect($id, $dir['recipe'], $dir['audioIndex']);
            if (!$info['hasEndlist'] || $info['segmentCount'] < 1) {
                continue; // unfinished output has no proof of success: treat as missing
            }
            $meta['variants'][] = [
                'recipe' => $dir['recipe'],
                'audioIndex' => $dir['audioIndex'],
                'state' => 'ready',
                'mode' => $dir['recipe'],
                'protocol' => 'hls',
                'video' => ['codec' => '', 'width' => 0, 'height' => 0],
                'audio' => ['index' => $dir['audioIndex'], 'codec' => 'aac', 'channels' => 2],
                'playlistPath' => $this->hls->relativeMaster($id, $dir['recipe'], $dir['audioIndex']),
                'segmentCount' => $info['segmentCount'],
                'durationReadySec' => $info['durationReadySec'],
                'lastAccessAt' => $this->clock->now(),
                'readyAt' => $this->clock->now(),
                'failCount' => 0,
                'lastFailAt' => null,
                'error' => null,
            ];
        }
        return $meta;
    }

    /** @return array<string,mixed> */
    private function blank(string $id, array $stat): array
    {
        return [
            'id' => $id,
            'source' => [
                'mtimeMs' => $stat['mtimeMs'],
                'size' => $stat['size'],
                'probeError' => null,
                'failCount' => 0,
            ],
            'durationSec' => 0,
            'video' => [
                'index' => 0,
                'codec' => '',
                'width' => 0,
                'height' => 0,
                'pixFmt' => '',
            ],
            'audioTracks' => [],
            'lastPlayedAt' => null,
            'variants' => [],
        ];
    }

    /**
     * Disk is leading for "does the output exist":
     * - ready but playlist gone → drop the record (missing);
     * - ready but shorter than the source → failed (encodes from before exit-code
     *   and duration checks could be truncated).
     * Promotion to ready is the worker's job; it knows the FFmpeg exit code.
     *
     * @param array<string,mixed> $meta
     * @return array<string,mixed>
     */
    private function syncVariantsFromDisk(string $id, array $meta): array
    {
        $source = (float) ($meta['durationSec'] ?? 0);
        $variants = [];
        foreach ($meta['variants'] ?? [] as $variant) {
            if (!is_array($variant)) {
                continue;
            }
            if (($variant['state'] ?? '') !== 'ready') {
                $variants[] = $variant;
                continue;
            }
            $recipe = (string) ($variant['recipe'] ?? '');
            $audio = (int) ($variant['audioIndex'] ?? 0);
            $info = $this->hls->inspect($id, $recipe, $audio);
            if (!$info['hasPlaylist']) {
                continue;
            }
            if ($info['hasEndlist'] && !MediaPlaylist::coversDuration($info['durationReadySec'], $source)) {
                $variant['state'] = 'failed';
                $variant['failCount'] = (int) ($variant['failCount'] ?? 0) + 1;
                $variant['lastFailAt'] = $this->clock->now();
                $variant['error'] = sprintf('incomplete output: %.1fs of %.1fs', $info['durationReadySec'], $source);
            }
            $variants[] = $variant;
        }
        $meta['variants'] = $variants;
        return $meta;
    }

    /** @param array<string,mixed> $meta @return array<string,mixed> */
    private function publicMeta(array $meta): array
    {
        unset($meta['sourcePath'], $meta['_internal']);
        return $meta;
    }
}
