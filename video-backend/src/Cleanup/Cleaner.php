<?php

declare(strict_types=1);

namespace Drumeo\Video\Cleanup;

use Drumeo\Video\Clock;
use Drumeo\Video\Config;
use Drumeo\Video\Jobs\Lock;
use Drumeo\Video\Store\HlsCache;
use Drumeo\Video\Store\MetadataStore;
use Drumeo\Video\VideoId;

/**
 * Deletes CACHE_DIR/{id}/{recipe}/{audioIndex}/ only (HlsCache refuses anything
 * outside CACHE_DIR or inside SOURCE_DIR). Variants with a job lock or in
 * starting/running state are never touched.
 */
final class Cleaner
{
    public function __construct(
        private readonly Config $config,
        private readonly MetadataStore $meta,
        private readonly HlsCache $hls,
        private readonly Clock $clock,
        private readonly Lock $locks,
    ) {
    }

    /**
     * @return list<array{id:string,recipe:string,audioIndex:int,reason:string,bytes:int}>
     */
    public function plan(): array
    {
        $maxAge = $this->config->maxAgeDays * 86400;
        $now = $this->clock->unix();
        $maxBytes = (int) round($this->config->maxCacheGb * 1024 * 1024 * 1024);
        $candidates = [];

        foreach ($this->hls->listVariantDirs() as $dir) {
            $id = $dir['id'];
            $recipe = $dir['recipe'];
            $audio = $dir['audioIndex'];
            $meta = VideoId::isValid($id) ? $this->meta->read($id) : null;
            $variant = $meta ? $this->meta->findVariant($meta, $recipe, $audio) : null;
            $state = $variant['state'] ?? '';
            if ($this->inUse($id, $recipe, $audio, $state)) {
                continue;
            }
            $age = $now - $this->ageUnix($meta, $variant, $dir['mtime']);
            $reason = null;
            if ($state === 'failed') {
                $reason = 'failed';
            } elseif ($age > $maxAge) {
                $reason = 'age';
            }
            $candidates[] = [
                'id' => $id,
                'recipe' => $recipe,
                'audioIndex' => $audio,
                'reason' => $reason,
                'bytes' => $dir['bytes'],
                'age' => $age,
            ];
        }

        $delete = array_values(array_filter($candidates, fn ($c) => $c['reason'] !== null));
        $remaining = array_values(array_filter($candidates, fn ($c) => $c['reason'] === null));
        $used = array_sum(array_map(fn ($c) => $c['bytes'], $remaining));
        if ($used > $maxBytes) {
            usort($remaining, fn ($a, $b) => $b['age'] <=> $a['age']); // least recently used first
            foreach ($remaining as $c) {
                if ($used <= $maxBytes) {
                    break;
                }
                $c['reason'] = 'lru';
                $delete[] = $c;
                $used -= $c['bytes'];
            }
        }

        return array_map(static fn ($c) => [
            'id' => $c['id'],
            'recipe' => $c['recipe'],
            'audioIndex' => $c['audioIndex'],
            'reason' => $c['reason'],
            'bytes' => $c['bytes'],
        ], $delete);
    }

    /** @return array{dryRun:bool,deleted:list<array<string,mixed>>,legacyLogsRemoved?:int} */
    public function run(bool $dryRun): array
    {
        $plan = $this->plan();
        if ($dryRun) {
            return ['dryRun' => true, 'deleted' => $plan];
        }
        $deleted = [];
        foreach ($plan as $item) {
            // Re-check right before deleting: a prepare may have started since plan().
            $meta = $this->meta->read($item['id']);
            $variant = $meta ? $this->meta->findVariant($meta, $item['recipe'], $item['audioIndex']) : null;
            if ($this->inUse($item['id'], $item['recipe'], $item['audioIndex'], (string) ($variant['state'] ?? ''))) {
                continue;
            }
            $this->hls->deleteVariant($item['id'], $item['recipe'], $item['audioIndex']);
            $deleted[] = $item;
            if ($meta === null) {
                continue;
            }
            $this->meta->update($item['id'], function (array $m) use ($item) {
                $variant = $this->meta->findVariant($m, $item['recipe'], $item['audioIndex']);
                if (!$variant) {
                    return $m;
                }
                if (($variant['state'] ?? '') === 'failed') {
                    // Keep failCount so we do not immediately re-encode.
                    $variant['segmentCount'] = 0;
                    $variant['durationReadySec'] = 0;
                    return $this->meta->upsertVariant($m, $variant);
                }
                return $this->meta->removeVariant($m, $item['recipe'], $item['audioIndex']);
            });
        }
        return ['dryRun' => false, 'deleted' => $deleted, 'legacyLogsRemoved' => $this->hls->removeLegacyLogs()];
    }

    /**
     * Most recent of lastPlayedAt (video) and lastAccessAt (variant); dir mtime if neither.
     *
     * @param array<string,mixed>|null $meta
     * @param array<string,mixed>|null $variant
     */
    public function ageUnix(?array $meta, ?array $variant, int $mtime): int
    {
        $best = null;
        foreach ([$meta['lastPlayedAt'] ?? null, $variant['lastAccessAt'] ?? null] as $iso) {
            if (!is_string($iso) || $iso === '') {
                continue;
            }
            $t = strtotime($iso);
            if ($t !== false && ($best === null || $t > $best)) {
                $best = $t;
            }
        }
        return $best ?? $mtime;
    }

    private function inUse(string $id, string $recipe, int $audio, string $state): bool
    {
        if ($state === 'starting' || $state === 'running') {
            return true;
        }
        return VideoId::isValid($id) && $this->locks->read($id, $recipe, $audio) !== null;
    }
}
