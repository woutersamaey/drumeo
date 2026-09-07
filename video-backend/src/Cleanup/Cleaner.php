<?php

declare(strict_types=1);

namespace Drumeo\Video\Cleanup;

use Drumeo\Video\Clock;
use Drumeo\Video\Config;
use Drumeo\Video\Store\HlsCache;
use Drumeo\Video\Store\MetadataStore;
use Drumeo\Video\VideoId;

final class Cleaner
{
    public function __construct(
        private readonly Config $config,
        private readonly MetadataStore $meta,
        private readonly HlsCache $hls,
        private readonly Clock $clock,
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
        $dirs = $this->hls->listVariantDirs();
        $candidates = [];

        foreach ($dirs as $dir) {
            $id = $dir['id'];
            $recipe = $dir['recipe'];
            $audio = $dir['audioIndex'];
            $meta = VideoId::isValid($id) ? $this->meta->read($id) : null;
            $variant = $meta ? $this->meta->findVariant($meta, $recipe, $audio) : null;
            $state = $variant['state'] ?? '';
            if ($state === 'starting' || $state === 'running') {
                continue;
            }
            $ageRef = $this->ageUnix($meta, $variant, $dir['mtime']);
            $age = $now - $ageRef;
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
                'state' => $state,
            ];
        }

        $delete = [];
        $keepBytes = 0;
        usort($candidates, fn ($a, $b) => $b['age'] <=> $a['age']); // oldest first for LRU later

        foreach ($candidates as $c) {
            if ($c['reason'] !== null) {
                $delete[] = $c;
            }
        }

        $remaining = array_values(array_filter($candidates, fn ($c) => $c['reason'] === null));
        $used = array_sum(array_map(fn ($c) => $c['bytes'], $remaining));
        if ($used > $maxBytes) {
            usort($remaining, fn ($a, $b) => $b['age'] <=> $a['age']);
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

    public function run(bool $dryRun): array
    {
        $plan = $this->plan();
        if ($dryRun) {
            return ['dryRun' => true, 'deleted' => $plan];
        }
        foreach ($plan as $item) {
            $this->hls->deleteVariant($item['id'], $item['recipe'], $item['audioIndex']);
            if ($this->meta->read($item['id'])) {
                $this->meta->update($item['id'], function (array $m) use ($item) {
                    $variant = $this->meta->findVariant($m, $item['recipe'], $item['audioIndex']);
                    if (!$variant) {
                        return $m;
                    }
                    if (($variant['state'] ?? '') === 'failed') {
                        // Keep failCount so we do not immediately re-encode.
                        $variant['playlistPath'] = $variant['playlistPath'] ?? null;
                        $variant['segmentCount'] = 0;
                        $variant['durationReadySec'] = 0;
                        return $this->meta->upsertVariant($m, $variant);
                    }
                    return $this->meta->removeVariant($m, $item['recipe'], $item['audioIndex']);
                });
            }
        }
        return ['dryRun' => false, 'deleted' => $plan];
    }

    /** @param array<string,mixed>|null $meta @param array<string,mixed>|null $variant */
    private function ageUnix(?array $meta, ?array $variant, int $mtime): int
    {
        $iso = $meta['lastPlayedAt'] ?? null;
        if (!is_string($iso) || $iso === '') {
            $iso = $variant['lastAccessAt'] ?? null;
        }
        if (is_string($iso) && $iso !== '') {
            $t = strtotime($iso);
            if ($t !== false) {
                return $t;
            }
        }
        return $mtime;
    }
}
