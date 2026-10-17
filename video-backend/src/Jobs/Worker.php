<?php

declare(strict_types=1);

namespace Drumeo\Video\Jobs;

use Drumeo\Video\Cleanup\Cleaner;
use Drumeo\Video\Clock;
use Drumeo\Video\Config;
use Drumeo\Video\Hls\MediaPlaylist;
use Drumeo\Video\Recipe\Recipe;
use Drumeo\Video\Store\AtomicJson;
use Drumeo\Video\Store\HlsCache;
use Drumeo\Video\Store\MetadataStore;
use Drumeo\Video\Store\SourceStore;
use Drumeo\Video\VideoId;

/**
 * Queue + FFmpeg supervisor. Runs in the video-worker container; the api only
 * calls enqueue()/upgradeIntent()/cancel()/isActive()/queuedOrRunningCount().
 */
final class Worker
{
    /** A variant in starting/running without a lock is only declared abandoned after this. */
    private const ABANDON_GRACE_SECONDS = 120;

    /** @var array<string,int> segment count last written to metadata, per running lock key */
    private array $progress = [];

    private int $nextMaintenanceCheck = 0;

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
        private readonly EncoderPicker $encoders,
        private readonly Cleaner $cleaner,
    ) {
    }

    public function tick(): void
    {
        $counts = $this->reap();
        $this->startEligible($counts['running'], $counts['transcodes']);
    }

    public function runLoop(int $sleepMicros = 400000): void
    {
        $this->config->ensureDirectories();
        $this->recoverAbandoned();
        $this->pruneJobs();
        while (true) {
            try {
                $this->tick();
                $this->maintain();
            } catch (\Throwable $e) {
                $this->log('error ' . $e->getMessage());
            }
            usleep($sleepMicros);
        }
    }

    /**
     * Daily cleanup + job-record pruning, tracked by a timestamp file in METADATA_DIR
     * so restarts do not re-run it. Returns the cleanup result when it ran.
     *
     * @return array<string,mixed>|null
     */
    public function maintain(bool $force = false): ?array
    {
        $now = $this->clock->unix();
        if (!$force) {
            if ($now < $this->nextMaintenanceCheck || $this->config->cleanupIntervalSeconds <= 0) {
                return null;
            }
            $this->nextMaintenanceCheck = $now + 60;
            $stamp = $this->config->cleanupStampPath();
            $last = is_file($stamp) ? (int) trim((string) @file_get_contents($stamp)) : 0;
            if ($now - $last < $this->config->cleanupIntervalSeconds) {
                return null;
            }
        }
        // Stamp first: a cleanup that keeps crashing must not run every minute.
        AtomicJson::writeFile($this->config->cleanupStampPath(), $now . "\n");
        $result = $this->cleaner->run(false);
        $result['jobsPruned'] = $this->pruneJobs();
        $bytes = array_sum(array_map(static fn (array $row): int => (int) ($row['bytes'] ?? 0), $result['deleted']));
        $this->log(sprintf(
            'cleanup deleted=%d bytes=%d legacyLogs=%d jobsPruned=%d',
            count($result['deleted']),
            $bytes,
            (int) ($result['legacyLogsRemoved'] ?? 0),
            $result['jobsPruned'],
        ));
        return $result;
    }

    public function pruneJobs(): int
    {
        $live = [];
        foreach ($this->locks->all() as $lock) {
            if (!empty($lock['jobId'])) {
                $live[(string) $lock['jobId']] = true;
            }
        }
        return $this->jobs->prune($live, $this->clock->unix());
    }

    /**
     * At worker start every pid in a lock belongs to a previous worker process, so
     * its FFmpeg is gone and the exit code unknown: that counts as one fail.
     */
    public function recoverAbandoned(): void
    {
        foreach ($this->locks->all() as $lock) {
            $key = $this->keyOf($lock);
            if ($key === null || !is_int($lock['pid'] ?? null)) {
                continue; // queued locks start normally
            }
            [$id, $recipe, $audio] = $key;
            $this->failJob($id, $recipe, $audio, $lock, 'abandoned (worker restarted)');
        }

        $now = $this->clock->unix();
        foreach ($this->allMetadata() as $id => $meta) {
            foreach ($meta['variants'] ?? [] as $variant) {
                if (!is_array($variant) || !in_array($variant['state'] ?? '', ['starting', 'running'], true)) {
                    continue;
                }
                $recipe = (string) ($variant['recipe'] ?? '');
                $audio = (int) ($variant['audioIndex'] ?? -1);
                if (!Recipe::isValid($recipe) || $audio < 0 || $this->locks->read($id, $recipe, $audio) !== null) {
                    continue;
                }
                $touched = strtotime((string) ($variant['lastAccessAt'] ?? '')) ?: 0;
                if ($now - $touched < self::ABANDON_GRACE_SECONDS) {
                    continue; // a prepare may be about to enqueue it
                }
                $this->meta->update($id, function (array $m) use ($recipe, $audio) {
                    $v = $this->meta->findVariant($m, $recipe, $audio);
                    if ($v === null || !in_array($v['state'] ?? '', ['starting', 'running'], true)) {
                        return $m;
                    }
                    return $this->meta->upsertVariant($m, $this->failVariant($v, 'abandoned'));
                });
                $this->log(sprintf('abandoned id=%s recipe=%s audio=%d (no lock)', $id, $recipe, $audio));
            }
        }
    }

    /** api side: queue a job, or return the existing one (upgrading prefetch → play). */
    public function enqueue(string $id, string $recipe, int $audioIndex, string $intent, array $offer): string
    {
        $newId = $this->jobs->newId();
        $created = false;
        $upgraded = false;
        $lock = $this->locks->update($id, $recipe, $audioIndex, function (?array $lock) use ($id, $recipe, $audioIndex, $intent, $offer, $newId, &$created, &$upgraded): array {
            if ($lock !== null) {
                if ($intent === 'play' && ($lock['intent'] ?? '') !== 'play') {
                    $lock['intent'] = 'play';
                    $upgraded = true;
                }
                return $lock;
            }
            $created = true;
            return [
                'jobId' => $newId,
                'videoId' => $id,
                'recipe' => $recipe,
                'audioIndex' => $audioIndex,
                'pid' => null,
                'state' => 'queued',
                'intent' => $intent,
                'queuedAt' => $this->clock->now(),
                'offer' => $offer,
                'mode' => $offer['mode'] ?? $recipe,
            ];
        });
        $jobId = (string) $lock['jobId'];
        if ($created) {
            $this->jobs->save([
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
            ]);
            $this->log(sprintf('queue id=%s recipe=%s audio=%d intent=%s job=%s', $id, $recipe, $audioIndex, $intent, $jobId));
        } elseif ($upgraded) {
            $this->jobs->patch($jobId, ['intent' => 'play']);
            $this->log(sprintf('upgrade id=%s recipe=%s audio=%d job=%s prefetch→play', $id, $recipe, $audioIndex, $jobId));
        }
        return $jobId;
    }

    /** api side: mark an existing job as play; never creates one. */
    public function upgradeIntent(string $id, string $recipe, int $audioIndex): void
    {
        $jobId = null;
        $this->locks->update($id, $recipe, $audioIndex, function (?array $lock) use (&$jobId): ?array {
            if ($lock === null || ($lock['intent'] ?? '') === 'play') {
                return $lock;
            }
            $lock['intent'] = 'play';
            $jobId = (string) ($lock['jobId'] ?? '');
            return $lock;
        });
        $this->jobs->patch($jobId, ['intent' => 'play']);
    }

    /**
     * api side: stop a job for this variant. A queued lock is dropped right away;
     * a running one is flagged and the worker stops FFmpeg on its next tick.
     */
    public function cancel(string $id, string $recipe, int $audioIndex): void
    {
        $dropped = null;
        $this->locks->update($id, $recipe, $audioIndex, function (?array $lock) use (&$dropped): ?array {
            if ($lock === null) {
                return null;
            }
            if (!is_int($lock['pid'] ?? null)) {
                $dropped = (string) ($lock['jobId'] ?? '');
                return null;
            }
            $lock['cancel'] = true;
            return $lock;
        });
        $this->jobs->patch($dropped, ['state' => 'cancelled', 'error' => 'cache deleted']);
    }

    /** Queued, or running with a fresh worker heartbeat. Safe to call from the api. */
    public function isActive(string $id, string $recipe, int $audioIndex): bool
    {
        $lock = $this->locks->read($id, $recipe, $audioIndex);
        return $lock !== null && empty($lock['cancel']) && Lock::isFresh($lock, $this->clock->unix());
    }

    public function hasLock(string $id, string $recipe, int $audioIndex): bool
    {
        return $this->locks->read($id, $recipe, $audioIndex) !== null;
    }

    public function queuedOrRunningCount(): int
    {
        return count($this->locks->all());
    }

    /** @return array{running:int,transcodes:int} */
    private function reap(): array
    {
        $running = 0;
        $transcodes = 0;
        foreach ($this->locks->all() as $lock) {
            $key = $this->keyOf($lock);
            if ($key === null) {
                continue;
            }
            [$id, $recipe, $audio] = $key;
            try {
                $pid = $lock['pid'] ?? null;
                if (!is_int($pid)) {
                    if (!empty($lock['cancel'])) {
                        $this->cancelJob($id, $recipe, $audio, $lock);
                    }
                    continue;
                }
                if (!empty($lock['cancel'])) {
                    $this->runner->stop($pid);
                    $this->cancelJob($id, $recipe, $audio, $lock);
                    continue;
                }
                $now = $this->clock->unix();
                $started = (int) ($lock['startedUnix'] ?? $now);
                if ($now - $started > $this->config->jobTimeoutSeconds) {
                    $this->runner->stop($pid);
                    $this->failOrRetry($id, $recipe, $audio, $lock, 'timeout after ' . $this->config->jobTimeoutSeconds . 's');
                    continue;
                }

                $poll = $this->runner->poll($pid);
                if ($poll['running']) {
                    $running++;
                    if ($recipe === Recipe::AVC_1080) {
                        $transcodes++;
                    }
                    $this->heartbeat($id, $recipe, $audio, $lock, $pid, $now);
                    $this->recordProgress($id, $recipe, $audio);
                    continue;
                }

                $error = $this->verify($id, $recipe, $audio, $poll['exitCode']);
                if ($error === null) {
                    $this->succeedJob($id, $recipe, $audio, $lock);
                } else {
                    $this->failOrRetry($id, $recipe, $audio, $lock, $error);
                }
            } catch (\Throwable $e) {
                $this->log(sprintf('reap error id=%s recipe=%s audio=%d: %s', $id, $recipe, $audio, $e->getMessage()));
            }
        }
        return ['running' => $running, 'transcodes' => $transcodes];
    }

    private function startEligible(int $running, int $transcodes): void
    {
        $queued = array_values(array_filter(
            $this->locks->all(),
            static fn (array $lock): bool => !is_int($lock['pid'] ?? null) && empty($lock['cancel']),
        ));
        usort($queued, static function (array $a, array $b): int {
            $ia = (($a['intent'] ?? '') === 'play') ? 0 : 1;
            $ib = (($b['intent'] ?? '') === 'play') ? 0 : 1;
            return [$ia, (string) ($a['queuedAt'] ?? '')] <=> [$ib, (string) ($b['queuedAt'] ?? '')];
        });

        foreach ($queued as $lock) {
            if ($running >= $this->config->maxConcurrentJobs) {
                break;
            }
            $key = $this->keyOf($lock);
            if ($key === null) {
                continue;
            }
            [$id, $recipe, $audio] = $key;
            if ($recipe === Recipe::AVC_1080 && $transcodes >= $this->config->maxConcurrentVideoTranscodes) {
                continue;
            }
            try {
                if ($this->startJob($id, $recipe, $audio)) {
                    $running++;
                    if ($recipe === Recipe::AVC_1080) {
                        $transcodes++;
                    }
                }
            } catch (\Throwable $e) {
                $this->failJob($id, $recipe, $audio, $lock, $e->getMessage());
            }
        }
    }

    /**
     * Starts FFmpeg while holding the lock's mutex, so an api write cannot slip
     * between "still queued?" and "pid recorded".
     */
    private function startJob(string $id, string $recipe, int $audio): bool
    {
        $started = null;
        $this->locks->update($id, $recipe, $audio, function (?array $lock) use ($id, $recipe, $audio, &$started): ?array {
            if ($lock === null || is_int($lock['pid'] ?? null) || !empty($lock['cancel'])) {
                return $lock; // gone, already started, or cancelled meanwhile
            }
            $source = $this->sources->pathFor($id);
            if ($source === null) {
                throw new \RuntimeException('source missing');
            }
            $meta = $this->meta->read($id);
            if ($meta === null) {
                throw new \RuntimeException('metadata missing');
            }
            $outDir = $this->hls->variantDir($id, $recipe, $audio);
            if (!is_dir($outDir) && !@mkdir($outDir, 0775, true) && !is_dir($outDir)) {
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
                $offer['video'] ?? [],
                $offer['audio'] ?? [],
            );

            $encoder = $recipe === Recipe::AVC_1080
                ? (string) ($lock['encoder'] ?? $this->encoders->codec())
                : null;
            $args = $this->commands->build($source, $outDir, $meta, $recipe, $audio, $encoder);
            $pid = $this->runner->start($args, $this->logPath($id, $recipe, $audio));

            $now = $this->clock->unix();
            $lock['pid'] = $pid;
            $lock['state'] = 'running';
            $lock['startedAt'] = $this->clock->now();
            $lock['startedUnix'] = $now;
            $lock['heartbeatUnix'] = $now;
            $lock['offer'] = $offer;
            if ($encoder !== null) {
                $lock['encoder'] = $encoder;
            }
            $started = $lock;
            return $lock;
        });
        if ($started === null) {
            return false;
        }

        $this->jobs->patch((string) ($started['jobId'] ?? ''), [
            'videoId' => $id,
            'recipe' => $recipe,
            'audioIndex' => $audio,
            'pid' => $started['pid'],
            'mode' => $started['offer']['mode'] ?? $recipe,
            'intent' => $started['intent'] ?? 'play',
            'encoder' => $started['encoder'] ?? null,
            'startedAt' => $started['startedAt'],
            'exitCode' => null,
            'state' => 'running',
        ]);
        $offer = $started['offer'];
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
        $this->log(sprintf(
            'start id=%s recipe=%s audio=%d intent=%s encoder=%s pid=%d',
            $id,
            $recipe,
            $audio,
            $started['intent'] ?? '',
            $started['encoder'] ?? 'copy',
            $started['pid'],
        ));
        return true;
    }

    /** Success needs exit code 0 and a finished playlist that covers the source. */
    private function verify(string $id, string $recipe, int $audio, ?int $exit): ?string
    {
        if ($exit !== 0) {
            return 'ffmpeg exit ' . ($exit === null ? 'unknown' : (string) $exit);
        }
        $info = $this->hls->inspect($id, $recipe, $audio);
        if (!$info['hasEndlist']) {
            return 'playlist has no ENDLIST';
        }
        if ($info['segmentCount'] < 1) {
            return 'no segments';
        }
        $source = (float) ($this->meta->read($id)['durationSec'] ?? 0);
        if (!MediaPlaylist::coversDuration($info['durationReadySec'], $source)) {
            return sprintf('incomplete output: %.1fs of %.1fs', $info['durationReadySec'], $source);
        }
        return null;
    }

    /**
     * A failed hardware encode is retried once with libx264 before it counts
     * towards MAX_VARIANT_FAILS.
     *
     * @param array<string,mixed> $lock
     */
    private function failOrRetry(string $id, string $recipe, int $audio, array $lock, string $error): void
    {
        $encoder = (string) ($lock['encoder'] ?? '');
        if ($recipe !== Recipe::AVC_1080 || $encoder === '' || $encoder === 'libx264' || !empty($lock['fallback'])) {
            $this->failJob($id, $recipe, $audio, $lock, $error);
            return;
        }
        $jobId = $lock['jobId'] ?? null;
        $this->locks->update($id, $recipe, $audio, function (?array $l) use ($jobId, $encoder, $error): ?array {
            if ($l === null || ($l['jobId'] ?? null) !== $jobId) {
                return $l;
            }
            unset($l['startedAt'], $l['startedUnix'], $l['heartbeatUnix']);
            $l['pid'] = null;
            $l['state'] = 'queued';
            $l['encoder'] = 'libx264';
            $l['fallback'] = ['from' => $encoder, 'error' => $error];
            return $l;
        });
        unset($this->progress[$this->locks->key($id, $recipe, $audio)]);
        $this->jobs->patch((string) $jobId, [
            'state' => 'queued',
            'pid' => null,
            'encoder' => 'libx264',
            'fallbackFrom' => $encoder,
            'fallbackReason' => $error,
        ]);
        $this->meta->update($id, function (array $m) use ($recipe, $audio, $encoder, $error) {
            $variant = $this->meta->findVariant($m, $recipe, $audio);
            if ($variant === null) {
                return $m;
            }
            $variant['state'] = 'starting';
            $variant['error'] = $encoder . ' failed (' . $error . '); retrying with libx264';
            return $this->meta->upsertVariant($m, $variant);
        });
        $this->log(sprintf('fallback id=%s recipe=%s audio=%d %s→libx264 error=%s', $id, $recipe, $audio, $encoder, $error));
    }

    /** @param array<string,mixed> $lock */
    private function succeedJob(string $id, string $recipe, int $audio, array $lock): void
    {
        try {
            $this->hls->finalizeForVod($id, $recipe, $audio, true);
            $info = $this->hls->inspect($id, $recipe, $audio);
            $this->meta->update($id, function (array $meta) use ($recipe, $audio, $info) {
                $variant = $this->meta->findVariant($meta, $recipe, $audio) ?? [
                    'recipe' => $recipe,
                    'audioIndex' => $audio,
                    'protocol' => 'hls',
                ];
                $variant['state'] = 'ready';
                $variant['segmentCount'] = $info['segmentCount'];
                $variant['durationReadySec'] = $info['durationReadySec'];
                $variant['readyAt'] = $this->clock->now();
                $variant['failCount'] = 0;
                $variant['error'] = null;
                $variant['playlistPath'] = $this->hls->relativeMaster($meta['id'], $recipe, $audio);
                return $this->meta->upsertVariant($meta, $variant);
            });
            $this->jobs->patch((string) ($lock['jobId'] ?? ''), [
                'exitCode' => 0,
                'state' => 'done',
                'pid' => $lock['pid'] ?? null,
                'finishedAt' => $this->clock->now(),
            ]);
            $elapsed = isset($lock['startedUnix']) ? $this->clock->unix() - (int) $lock['startedUnix'] : null;
            $this->log(sprintf(
                'done id=%s recipe=%s audio=%d encoder=%s elapsedSec=%s durationReady=%s segs=%d',
                $id,
                $recipe,
                $audio,
                (string) ($lock['encoder'] ?? 'copy'),
                $elapsed === null ? '?' : (string) $elapsed,
                (string) $info['durationReadySec'],
                $info['segmentCount'],
            ));
        } finally {
            $this->release($id, $recipe, $audio, $lock);
        }
    }

    /** @param array<string,mixed> $lock */
    private function failJob(string $id, string $recipe, int $audio, array $lock, string $error): void
    {
        try {
            $this->meta->update($id, function (array $meta) use ($recipe, $audio, $error) {
                $variant = $this->meta->findVariant($meta, $recipe, $audio) ?? [
                    'recipe' => $recipe,
                    'audioIndex' => $audio,
                    'protocol' => 'hls',
                ];
                return $this->meta->upsertVariant($meta, $this->failVariant($variant, $error));
            });
        } catch (\Throwable $e) {
            $this->log('fail: metadata not updated: ' . $e->getMessage());
        } finally {
            $this->jobs->patch((string) ($lock['jobId'] ?? ''), [
                'exitCode' => 1,
                'state' => 'failed',
                'error' => $error,
                'finishedAt' => $this->clock->now(),
            ]);
            $this->release($id, $recipe, $audio, $lock);
        }
        $this->log(sprintf('fail id=%s recipe=%s audio=%d error=%s', $id, $recipe, $audio, $error));
    }

    /** @param array<string,mixed> $lock */
    private function cancelJob(string $id, string $recipe, int $audio, array $lock): void
    {
        try {
            $this->hls->deleteVariant($id, $recipe, $audio);
            $this->meta->update($id, fn (array $m) => $this->meta->removeVariant($m, $recipe, $audio));
        } catch (\Throwable $e) {
            $this->log('cancel cleanup: ' . $e->getMessage());
        } finally {
            $this->jobs->patch((string) ($lock['jobId'] ?? ''), [
                'state' => 'cancelled',
                'error' => 'cache deleted',
                'finishedAt' => $this->clock->now(),
            ]);
            $this->release($id, $recipe, $audio, $lock);
        }
        $this->log(sprintf('cancel id=%s recipe=%s audio=%d', $id, $recipe, $audio));
    }

    /** Remove the lock only if it still belongs to this job. @param array<string,mixed> $lock */
    private function release(string $id, string $recipe, int $audio, array $lock): void
    {
        $jobId = $lock['jobId'] ?? null;
        $this->locks->update($id, $recipe, $audio, static fn (?array $l): ?array => ($l !== null && ($l['jobId'] ?? null) === $jobId) ? null : $l);
        unset($this->progress[$this->locks->key($id, $recipe, $audio)]);
    }

    /** @param array<string,mixed> $lock */
    private function heartbeat(string $id, string $recipe, int $audio, array $lock, int $pid, int $now): void
    {
        if ($now - Lock::heartbeatOf($lock) < Lock::HEARTBEAT_EVERY) {
            return;
        }
        $this->locks->update($id, $recipe, $audio, static function (?array $l) use ($pid, $now): ?array {
            if ($l === null || ($l['pid'] ?? null) !== $pid) {
                return $l;
            }
            $l['heartbeatUnix'] = $now;
            return $l;
        });
    }

    private function recordProgress(string $id, string $recipe, int $audio): void
    {
        $info = $this->hls->inspect($id, $recipe, $audio);
        $key = $this->locks->key($id, $recipe, $audio);
        if (($this->progress[$key] ?? -1) === $info['segmentCount']) {
            return;
        }
        $this->progress[$key] = $info['segmentCount'];
        $this->meta->update($id, function (array $meta) use ($recipe, $audio, $info) {
            $variant = $this->meta->findVariant($meta, $recipe, $audio);
            if ($variant === null) {
                return $meta;
            }
            $variant['state'] = 'running';
            $variant['segmentCount'] = $info['segmentCount'];
            $variant['durationReadySec'] = $info['durationReadySec'];
            return $this->meta->upsertVariant($meta, $variant);
        });
    }

    /** @param array<string,mixed> $variant @return array<string,mixed> */
    private function failVariant(array $variant, string $error): array
    {
        // MAX_VARIANT_FAILS is enforced by prepare: failed + failCount ≥ max needs force.
        $variant['failCount'] = (int) ($variant['failCount'] ?? 0) + 1;
        $variant['lastFailAt'] = $this->clock->now();
        $variant['error'] = $error;
        $variant['state'] = 'failed';
        return $variant;
    }

    /**
     * @param array<string,mixed> $lock
     * @return array{0:string,1:string,2:int}|null
     */
    private function keyOf(array $lock): ?array
    {
        $id = VideoId::normalize($lock['videoId'] ?? '');
        $recipe = (string) ($lock['recipe'] ?? '');
        $audio = $lock['audioIndex'] ?? null;
        if (!VideoId::isValid($id) || !Recipe::isValid($recipe) || !is_int($audio) || $audio < 0) {
            return null;
        }
        return [$id, $recipe, $audio];
    }

    private function logPath(string $id, string $recipe, int $audio): string
    {
        return $this->config->logDir() . '/' . $this->locks->key($id, $recipe, $audio) . '.log';
    }

    private function log(string $msg): void
    {
        $line = '[worker] ' . $msg . "\n";
        if (defined('STDERR')) {
            fwrite(STDERR, $line);
            return;
        }
        error_log(rtrim($line));
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
