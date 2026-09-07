<?php

declare(strict_types=1);

namespace Drumeo\Video\Store;

use Drumeo\Video\Clock;
use Drumeo\Video\Config;
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
        return AtomicJson::read($this->pathFor(VideoId::assert($id)));
    }

    /**
     * Load-or-probe. Returns metadata or null if the MKV is missing.
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
        $meta = $this->read($id);
        if ($meta === null) {
            $meta = $this->restoreFromCache($id, $stat) ?? $this->blank($id, $stat);
        }

        $needProbe = $forceProbe
            || empty($meta['video']['codec'])
            || (int) ($meta['source']['mtimeMs'] ?? 0) !== $stat['mtimeMs']
            || (int) ($meta['source']['size'] ?? 0) !== $stat['size'];

        $failCount = (int) ($meta['source']['failCount'] ?? 0);
        $probeError = $meta['source']['probeError'] ?? null;
        $sourceChanged = (int) ($meta['source']['mtimeMs'] ?? 0) !== $stat['mtimeMs']
            || (int) ($meta['source']['size'] ?? 0) !== $stat['size'];

        if ($needProbe) {
            if (!$forceProbe && !$sourceChanged && $probeError && $failCount >= $this->config->maxVariantFails) {
                // Frozen until force or mtime/size change.
                return $this->publicMeta($meta);
            }
            if ($sourceChanged) {
                $this->invalidateCaches($id);
                $meta['variants'] = [];
                $failCount = 0;
                $probeError = null;
            }
            try {
                $probed = $this->probe->inspect($stat['path']);
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
                $meta['audioTracks'] = array_map(static function (array $t): array {
                    return [
                        'index' => $t['index'],
                        'codec' => $t['codec'],
                        'channels' => $t['channels'],
                    ];
                }, $probed['audioTracks']);
                $this->write($id, $meta);
            } catch (\Throwable $e) {
                $failCount++;
                $meta['source'] = [
                    'mtimeMs' => $stat['mtimeMs'],
                    'size' => $stat['size'],
                    'probeError' => $e->getMessage(),
                    'failCount' => $failCount,
                ];
                $this->write($id, $meta);
            }
        }

        $meta = $this->syncVariantsFromDisk($id, $meta);
        return $this->publicMeta($meta);
    }

    /**
     * @param callable(array):array $mutator
     * @return array<string,mixed>
     */
    public function update(string $id, callable $mutator): array
    {
        $lock = $this->fileLock($id);
        try {
            $meta = $this->read($id) ?? $this->load($id);
            if ($meta === null) {
                throw new \RuntimeException('Video not found: ' . $id);
            }
            $meta = $mutator($meta);
            $this->write($id, $meta);
            return $meta;
        } finally {
            $this->fileUnlock($lock);
        }
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

    /** @param array<string,mixed> $stat */
    private function restoreFromCache(string $id, array $stat): ?array
    {
        $dirs = $this->hls->listVariantDirs();
        $mine = array_filter($dirs, fn ($d) => $d['id'] === $id);
        if ($mine === []) {
            return null;
        }
        $meta = $this->blank($id, $stat);
        foreach ($mine as $dir) {
            $info = $this->hls->inspect($id, $dir['recipe'], $dir['audioIndex']);
            if (!$info['hasPlaylist']) {
                continue;
            }
            $state = $info['hasEndlist'] ? 'ready' : 'running';
            $meta['variants'][] = [
                'recipe' => $dir['recipe'],
                'audioIndex' => $dir['audioIndex'],
                'state' => $state,
                'mode' => $dir['recipe'] === 'remux' ? 'remux' : $dir['recipe'],
                'protocol' => 'hls',
                'video' => ['codec' => '', 'width' => 0, 'height' => 0],
                'audio' => ['index' => $dir['audioIndex'], 'codec' => 'aac', 'channels' => 2],
                'playlistPath' => $this->hls->relativeMaster($id, $dir['recipe'], $dir['audioIndex']),
                'segmentCount' => $info['segmentCount'],
                'durationReadySec' => $info['durationReadySec'],
                'lastAccessAt' => $this->clock->now(),
                'readyAt' => $state === 'ready' ? $this->clock->now() : null,
                'failCount' => 0,
                'lastFailAt' => null,
                'error' => null,
            ];
        }
        $this->write($id, $meta);
        return $meta;
    }

    /**
     * @param array<string,mixed> $meta
     * @return array<string,mixed>
     */
    private function syncVariantsFromDisk(string $id, array $meta): array
    {
        $changed = false;
        $variants = [];
        foreach ($meta['variants'] ?? [] as $variant) {
            if (!is_array($variant)) {
                continue;
            }
            $recipe = (string) ($variant['recipe'] ?? '');
            $audio = (int) ($variant['audioIndex'] ?? 0);
            $info = $this->hls->inspect($id, $recipe, $audio);
            if (($variant['state'] ?? '') === 'ready' && !$info['hasPlaylist']) {
                $changed = true;
                continue;
            }
            if ($info['hasPlaylist']) {
                $variant['segmentCount'] = $info['segmentCount'];
                $variant['durationReadySec'] = $info['durationReadySec'];
                $variant['playlistPath'] = $this->hls->relativeMaster($id, $recipe, $audio);
                if ($info['hasEndlist'] && ($variant['state'] ?? '') !== 'failed') {
                    if (($variant['state'] ?? '') !== 'ready') {
                        $variant['state'] = 'ready';
                        $variant['readyAt'] = $variant['readyAt'] ?? $this->clock->now();
                        $changed = true;
                    }
                }
            }
            $variants[] = $variant;
        }
        if ($changed || count($variants) !== count($meta['variants'] ?? [])) {
            $meta['variants'] = $variants;
            $this->write($id, $meta);
        }
        return $meta;
    }

    private function invalidateCaches(string $id): void
    {
        $this->hls->deleteAllFor($id);
    }

    /** @param array<string,mixed> $meta @return array<string,mixed> */
    private function publicMeta(array $meta): array
    {
        unset($meta['sourcePath'], $meta['_internal']);
        return $meta;
    }

    /** @return array{fp:resource,path:string} */
    private function fileLock(string $id): array
    {
        $path = $this->config->lockDir() . '/meta-' . $id . '.lock';
        if (!is_dir($this->config->lockDir())) {
            mkdir($this->config->lockDir(), 0775, true);
        }
        $fp = fopen($path, 'c+');
        if ($fp === false) {
            throw new \RuntimeException('Cannot open lock ' . $path);
        }
        flock($fp, LOCK_EX);
        return ['fp' => $fp, 'path' => $path];
    }

    /** @param array{fp:resource,path:string} $lock */
    private function fileUnlock(array $lock): void
    {
        flock($lock['fp'], LOCK_UN);
        fclose($lock['fp']);
    }
}
