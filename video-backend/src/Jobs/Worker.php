<?php

declare(strict_types=1);

namespace Drumeo\Video\Jobs;

use Drumeo\Video\Clock;
use Drumeo\Video\Config;
use Drumeo\Video\Recipe\Recipe;
use Drumeo\Video\Store\HlsCache;
use Drumeo\Video\Store\MetadataStore;
use Drumeo\Video\Store\SourceStore;
use Drumeo\Video\VideoId;

final class Worker
{
    public function __construct(
        private readonly Config $config,
        private readonly MetadataStore $meta,
        private readonly SourceStore $sources,
        private readonly HlsCache $hls,
        private readonly Lock $locks,
        private readonly JobRegistry $jobs,
        private readonly FfmpegCommand $commands,
        private readonly FfmpegRunner $runner,
        private readonly Clock $clock,
    ) {
    }

    public function tick(): void
    {
        $this->reap();
        $this->startEligible();
    }

    public function runLoop(int $sleepMicros = 400000): void
    {
        $this->config->ensureDirectories();
        $this->recoverAbandoned();
        while (true) {
            try {
                $this->tick();
            } catch (\Throwable $e) {
                fwrite(STDERR, '[worker] ' . $e->getMessage() . "\n");
            }
            usleep($sleepMicros);
        }
    }

    public function recoverAbandoned(): void
    {
        foreach ($this->allMetadata() as $id => $meta) {
            $id = VideoId::normalize($id);
            $changed = false;
            foreach ($meta['variants'] ?? [] as $i => $variant) {
                if (!is_array($variant)) {
                    continue;
                }
                $state = $variant['state'] ?? '';
                if ($state !== 'starting' && $state !== 'running') {
                    continue;
                }
                $recipe = (string) $variant['recipe'];
                $audio = (int) $variant['audioIndex'];
                $lock = $this->locks->read($id, $recipe, $audio);
                $pid = is_array($lock) ? ($lock['pid'] ?? null) : null;
                $info = $this->hls->inspect($id, $recipe, $audio);
                if ($info['hasEndlist']) {
                    $meta['variants'][$i]['state'] = 'ready';
                    $meta['variants'][$i]['readyAt'] = $this->clock->now();
                    $meta['variants'][$i]['segmentCount'] = $info['segmentCount'];
                    $meta['variants'][$i]['durationReadySec'] = $info['durationReadySec'];
                    $this->finalizePlaylist($id, $recipe, $audio);
                    $this->locks->release($id, $recipe, $audio);
                    $changed = true;
                    continue;
                }
                if (!$this->locks->pidAlive(is_int($pid) ? $pid : null)) {
                    $meta['variants'][$i] = $this->failVariant($variant, 'abandoned');
                    $this->locks->release($id, $recipe, $audio);
                    $changed = true;
                }
            }
            if ($changed) {
                $this->meta->write($id, $meta);
            }
        }
    }

    public function enqueue(string $id, string $recipe, int $audioIndex, string $intent, array $offer): string
    {
        $jobId = $this->jobs->newId();
        $existing = $this->locks->read($id, $recipe, $audioIndex);
        if (is_array($existing) && !empty($existing['jobId'])) {
            if ($intent === 'play' && ($existing['intent'] ?? '') !== 'play') {
                $existing['intent'] = 'play';
                $this->locks->write($id, $recipe, $audioIndex, $existing);
                $job = $this->jobs->get((string) $existing['jobId']);
                if ($job) {
                    $job['intent'] = 'play';
                    $this->jobs->save($job);
                }
            }
            return (string) $existing['jobId'];
        }

        $job = [
            'jobId' => $jobId,
            'videoId' => $id,
            'recipe' => $recipe,
            'audioIndex' => $audioIndex,
            'pid' => null,
            'mode' => $offer['mode'] ?? $recipe,
            'intent' => $intent,
            'startedAt' => null,
            'exitCode' => null,
            'offer' => $offer,
            'state' => 'queued',
        ];
        $this->jobs->save($job);
        $this->locks->write($id, $recipe, $audioIndex, [
            'jobId' => $jobId,
            'videoId' => $id,
            'recipe' => $recipe,
            'audioIndex' => $audioIndex,
            'pid' => null,
            'intent' => $intent,
            'queuedAt' => $this->clock->now(),
            'offer' => $offer,
            'mode' => $offer['mode'] ?? $recipe,
        ]);
        return $jobId;
    }

    public function activeCount(): int
    {
        $n = 0;
        foreach ($this->locks->all() as $lock) {
            $pid = $lock['pid'] ?? null;
            if ($this->pidRunning($pid)) {
                $n++;
            } elseif (($lock['state'] ?? '') === 'queued' || ($lock['pid'] ?? null) === null) {
                $n++;
            }
        }
        return $n;
    }

    public function queuedOrRunningCount(): int
    {
        return count($this->locks->all());
    }

    public function runningVideoTranscodes(): int
    {
        $n = 0;
        foreach ($this->locks->all() as $lock) {
            if (($lock['recipe'] ?? '') !== Recipe::AVC_1080) {
                continue;
            }
            $pid = $lock['pid'] ?? null;
            if ($this->pidRunning($pid)) {
                $n++;
            }
        }
        return $n;
    }

    public function isActive(string $id, string $recipe, int $audioIndex): bool
    {
        $lock = $this->locks->read($id, $recipe, $audioIndex);
        if (!is_array($lock)) {
            return false;
        }
        $pid = $lock['pid'] ?? null;
        if ($this->pidRunning($pid)) {
            return true;
        }
        return ($lock['pid'] ?? null) === null; // queued
    }

    public function upgradeIntent(string $id, string $recipe, int $audioIndex): void
    {
        $lock = $this->locks->read($id, $recipe, $audioIndex);
        if (!is_array($lock)) {
            return;
        }
        $lock['intent'] = 'play';
        $this->locks->write($id, $recipe, $audioIndex, $lock);
        if (!empty($lock['jobId'])) {
            $job = $this->jobs->get((string) $lock['jobId']);
            if ($job) {
                $job['intent'] = 'play';
                $this->jobs->save($job);
            }
        }
    }

    private function reap(): void
    {
        foreach ($this->locks->all() as $lock) {
            $id = (string) ($lock['videoId'] ?? '');
            $recipe = (string) ($lock['recipe'] ?? '');
            $audio = (int) ($lock['audioIndex'] ?? 0);
            if ($id === '' || $recipe === '') {
                continue;
            }
            $pid = $lock['pid'] ?? null;
            if (!is_int($pid) || $pid <= 0) {
                continue;
            }
            $started = isset($lock['startedUnix']) ? (int) $lock['startedUnix'] : $this->clock->unix();
            if ($this->clock->unix() - $started > $this->config->jobTimeoutSeconds) {
                $this->runner->stop($pid);
                $this->failJob($id, $recipe, $audio, $lock, 'timeout');
                continue;
            }

            $poll = $this->runner->poll($pid);
            $info = $this->hls->inspect($id, $recipe, $audio);
            if ($poll['running']) {
                $this->meta->update($id, function (array $meta) use ($recipe, $audio, $info) {
                    $variant = $this->meta->findVariant($meta, $recipe, $audio);
                    if ($variant) {
                        $variant['state'] = 'running';
                        $variant['segmentCount'] = $info['segmentCount'];
                        $variant['durationReadySec'] = $info['durationReadySec'];
                        $meta = $this->meta->upsertVariant($meta, $variant);
                    }
                    return $meta;
                });
                continue;
            }

            $exit = $poll['exitCode'];
            if ($exit === 0 || $info['hasEndlist'] || $info['segmentCount'] > 0 && $exit === 0) {
                $this->succeedJob($id, $recipe, $audio, $lock, $exit ?? 0);
            } elseif ($exit === 0) {
                $this->succeedJob($id, $recipe, $audio, $lock, 0);
            } else {
                $this->failJob($id, $recipe, $audio, $lock, 'ffmpeg exit ' . ($exit ?? 'unknown'));
            }
        }
    }

    private function startEligible(): void
    {
        $queued = [];
        foreach ($this->locks->all() as $lock) {
            if (($lock['pid'] ?? null) !== null) {
                continue;
            }
            $queued[] = $lock;
        }
        usort($queued, function ($a, $b) {
            $ia = (($a['intent'] ?? '') === 'play') ? 0 : 1;
            $ib = (($b['intent'] ?? '') === 'play') ? 0 : 1;
            return $ia <=> $ib;
        });

        $running = 0;
        $transcodes = 0;
        foreach ($this->locks->all() as $lock) {
            $pid = $lock['pid'] ?? null;
            if ($this->pidRunning($pid)) {
                $running++;
                if (($lock['recipe'] ?? '') === Recipe::AVC_1080) {
                    $transcodes++;
                }
            }
        }

        foreach ($queued as $lock) {
            if ($running >= $this->config->maxConcurrentJobs) {
                break;
            }
            $recipe = (string) ($lock['recipe'] ?? '');
            if ($recipe === Recipe::AVC_1080 && $transcodes >= $this->config->maxConcurrentVideoTranscodes) {
                continue;
            }
            try {
                $this->startJob($lock);
                $running++;
                if ($recipe === Recipe::AVC_1080) {
                    $transcodes++;
                }
            } catch (\Throwable $e) {
                $id = (string) ($lock['videoId'] ?? '');
                $audio = (int) ($lock['audioIndex'] ?? 0);
                $this->failJob($id, $recipe, $audio, $lock, $e->getMessage());
            }
        }
    }

    /** @param array<string,mixed> $lock */
    private function startJob(array $lock): void
    {
        $id = VideoId::assert((string) $lock['videoId']);
        $recipe = (string) $lock['recipe'];
        $audio = (int) $lock['audioIndex'];
        $source = $this->sources->pathFor($id);
        if ($source === null) {
            throw new \RuntimeException('source missing');
        }
        $meta = $this->meta->read($id);
        if ($meta === null) {
            throw new \RuntimeException('metadata missing');
        }
        $outDir = $this->hls->variantDir($id, $recipe, $audio);
        if (!is_dir($outDir) && !mkdir($outDir, 0775, true) && !is_dir($outDir)) {
            throw new \RuntimeException('cannot create cache dir');
        }
        foreach (glob($outDir . '/*') ?: [] as $old) {
            if (is_file($old)) {
                @unlink($old);
            }
        }

        $offer = is_array($lock['offer'] ?? null) ? $lock['offer'] : null;
        if ($offer === null) {
            $variant = $this->meta->findVariant($meta, $recipe, $audio);
            $offer = [
                'mode' => $variant['mode'] ?? $recipe,
                'video' => $variant['video'] ?? ['codec' => 'avc1', 'width' => 1920, 'height' => 1080],
                'audio' => $variant['audio'] ?? ['index' => $audio, 'codec' => 'aac', 'channels' => 2],
            ];
        }
        $this->commands->writeMaster(
            $this->hls->masterPath($id, $recipe, $audio),
            $meta,
            $recipe,
            $audio,
            $offer['video'] ?? [],
            $offer['audio'] ?? [],
        );

        $args = $this->commands->build($source, $outDir, $meta, $recipe, $audio);
        $log = $outDir . '/ffmpeg.log';
        $pid = $this->runner->start($args, $log);

        $lock['pid'] = $pid;
        $lock['startedAt'] = $this->clock->now();
        $lock['startedUnix'] = $this->clock->unix();
        $lock['state'] = 'running';
        $lock['offer'] = $offer;
        $this->locks->write($id, $recipe, $audio, $lock);

        if (!empty($lock['jobId'])) {
            $job = $this->jobs->get((string) $lock['jobId']) ?? [];
            $job['jobId'] = $lock['jobId'];
            $job['videoId'] = $id;
            $job['recipe'] = $recipe;
            $job['audioIndex'] = $audio;
            $job['pid'] = $pid;
            $job['mode'] = $offer['mode'] ?? $recipe;
            $job['intent'] = $lock['intent'] ?? 'play';
            $job['startedAt'] = $lock['startedAt'];
            $job['exitCode'] = null;
            $job['state'] = 'running';
            $this->jobs->save($job);
        }

        $this->meta->update($id, function (array $meta) use ($recipe, $audio, $offer) {
            $variant = $this->meta->findVariant($meta, $recipe, $audio) ?? [];
            $variant['recipe'] = $recipe;
            $variant['audioIndex'] = $audio;
            $variant['state'] = 'running';
            $variant['mode'] = $offer['mode'] ?? $recipe;
            $variant['protocol'] = 'hls';
            $variant['video'] = $offer['video'] ?? $variant['video'] ?? [];
            $variant['audio'] = $offer['audio'] ?? $variant['audio'] ?? [];
            $variant['playlistPath'] = $this->hls->relativeMaster($meta['id'], $recipe, $audio);
            $variant['lastAccessAt'] = $this->clock->now();
            return $this->meta->upsertVariant($meta, $variant);
        });
    }

    /** @param array<string,mixed> $lock */
    private function succeedJob(string $id, string $recipe, int $audio, array $lock, int $exit): void
    {
        $this->finalizePlaylist($id, $recipe, $audio);
        $info = $this->hls->inspect($id, $recipe, $audio);
        $this->meta->update($id, function (array $meta) use ($recipe, $audio, $info) {
            $variant = $this->meta->findVariant($meta, $recipe, $audio) ?? [];
            $variant['state'] = 'ready';
            $variant['segmentCount'] = $info['segmentCount'];
            $variant['durationReadySec'] = $info['durationReadySec'];
            $variant['readyAt'] = $this->clock->now();
            $variant['error'] = null;
            $variant['playlistPath'] = $this->hls->relativeMaster($meta['id'], $recipe, $audio);
            return $this->meta->upsertVariant($meta, $variant);
        });
        if (!empty($lock['jobId'])) {
            $job = $this->jobs->get((string) $lock['jobId']) ?? $lock;
            $job['exitCode'] = $exit;
            $job['state'] = 'done';
            $job['pid'] = $lock['pid'] ?? null;
            $this->jobs->save($job);
        }
        $this->locks->release($id, $recipe, $audio);
    }

    /** @param array<string,mixed> $lock */
    private function failJob(string $id, string $recipe, int $audio, array $lock, string $error): void
    {
        $this->meta->update($id, function (array $meta) use ($recipe, $audio, $error) {
            $variant = $this->meta->findVariant($meta, $recipe, $audio) ?? [
                'recipe' => $recipe,
                'audioIndex' => $audio,
                'protocol' => 'hls',
            ];
            $meta = $this->meta->upsertVariant($meta, $this->failVariant($variant, $error));
            return $meta;
        });
        if (!empty($lock['jobId'])) {
            $job = $this->jobs->get((string) $lock['jobId']) ?? $lock;
            $job['exitCode'] = 1;
            $job['state'] = 'failed';
            $job['error'] = $error;
            $this->jobs->save($job);
        }
        $this->locks->release($id, $recipe, $audio);
    }

    /** @param array<string,mixed> $variant @return array<string,mixed> */
    private function failVariant(array $variant, string $error): array
    {
        $fails = (int) ($variant['failCount'] ?? 0) + 1;
        $variant['failCount'] = $fails;
        $variant['lastFailAt'] = $this->clock->now();
        $variant['error'] = $error;
        $variant['state'] = $fails >= $this->config->maxVariantFails ? 'failed' : 'failed';
        // Always mark failed after a job death; MAX_VARIANT_FAILS blocks re-prepare.
        $variant['state'] = 'failed';
        return $variant;
    }

    private function pidRunning(mixed $pid): bool
    {
        if (!is_int($pid) || $pid <= 0) {
            return false;
        }
        return $this->runner->poll($pid)['running'] === true;
    }

    private function finalizePlaylist(string $id, string $recipe, int $audio): void
    {
        $this->hls->finalizeForVod($id, $recipe, $audio, true);
    }

    /** @return array<string,array<string,mixed>> */
    private function allMetadata(): array
    {
        $dir = $this->config->metadataDir;
        if (!is_dir($dir)) {
            return [];
        }
        $out = [];
        foreach (scandir($dir) ?: [] as $name) {
            if (!str_ends_with($name, '.json')) {
                continue;
            }
            $id = VideoId::normalize(substr($name, 0, -5));
            if (!VideoId::isValid($id)) {
                continue;
            }
            $meta = $this->meta->read($id);
            if (is_array($meta)) {
                $out[$id] = $meta;
            }
        }
        return $out;
    }
}
