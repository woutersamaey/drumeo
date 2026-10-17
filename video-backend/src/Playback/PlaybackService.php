<?php

declare(strict_types=1);

namespace Drumeo\Video\Playback;

use Drumeo\Video\Clock;
use Drumeo\Video\Config;
use Drumeo\Video\Http\Response;
use Drumeo\Video\Jobs\EncoderPicker;
use Drumeo\Video\Jobs\Lock;
use Drumeo\Video\Jobs\Worker;
use Drumeo\Video\Recipe\Capabilities;
use Drumeo\Video\Recipe\Matcher;
use Drumeo\Video\Recipe\Recipe;
use Drumeo\Video\Store\HlsCache;
use Drumeo\Video\Store\MetadataStore;
use Drumeo\Video\Store\SourceStore;
use Drumeo\Video\VideoId;

/**
 * "ready" comes only from the metadata JSON (set by the worker after checking the
 * FFmpeg exit code and output duration); a playlist with ENDLIST is not enough.
 */
final class PlaybackService
{
    public function __construct(
        private readonly Config $config,
        private readonly SourceStore $sources,
        private readonly MetadataStore $meta,
        private readonly HlsCache $hls,
        private readonly Matcher $matcher,
        private readonly Worker $worker,
        private readonly Clock $clock,
        private readonly EncoderPicker $encoders,
        private readonly Lock $locks,
    ) {
    }

    public function health(): Response
    {
        $choice = $this->encoders->choice();
        return Response::ok([
            'ok' => true,
            'ffmpeg' => $this->encoders->ffmpegAvailable(),
            'encoder' => $choice['encoder'],
            'encoderRequested' => $choice['requested'],
        ]);
    }

    public function listVideos(): Response
    {
        $items = [];
        foreach ($this->sources->listIds() as $id) {
            $id = VideoId::normalize($id);
            $meta = $this->meta->read($id);
            $stat = $this->sources->stat($id);
            if ($meta === null && $stat !== null) {
                $meta = [
                    'id' => $id,
                    'durationSec' => null,
                    'audioTracks' => [],
                    'lastPlayedAt' => null,
                    'variants' => [],
                    'source' => [
                        'mtimeMs' => $stat['mtimeMs'],
                        'size' => $stat['size'],
                        'probeError' => null,
                        'failCount' => 0,
                    ],
                ];
            }
            if ($meta === null) {
                continue;
            }
            $shortVariants = [];
            foreach ($meta['variants'] ?? [] as $v) {
                if (!is_array($v)) {
                    continue;
                }
                $shortVariants[] = [
                    'recipe' => $v['recipe'] ?? null,
                    'audioIndex' => $v['audioIndex'] ?? null,
                    'state' => $v['state'] ?? null,
                ];
            }
            $items[] = [
                'id' => $id,
                'durationSec' => $meta['durationSec'] ?? null,
                'audioTracks' => $meta['audioTracks'] ?? [],
                'lastPlayedAt' => $meta['lastPlayedAt'] ?? null,
                'variants' => $shortVariants,
                'source' => $meta['source'] ?? null,
            ];
        }
        return Response::ok(['videos' => $items]);
    }

    public function getVideo(string $id): Response
    {
        if (!VideoId::isValid($id)) {
            return Response::badRequest('invalid id');
        }
        $meta = $this->meta->load($id);
        if ($meta === null) {
            return Response::notFound('source missing');
        }
        return Response::ok($meta);
    }

    public function query(array $body): Response
    {
        $id = (string) ($body['videoId'] ?? '');
        if (!VideoId::isValid($id)) {
            return Response::badRequest('invalid id');
        }
        if (!$this->sources->exists($id)) {
            return Response::notFound('source missing');
        }
        $meta = $this->meta->load($id);
        if ($meta === null) {
            return Response::notFound('source missing');
        }
        if (!empty($meta['source']['probeError']) && empty($meta['video']['codec'])) {
            return Response::badRequest('probe failed', ['source' => $meta['source']]);
        }
        $audioIndex = self::parseAudioIndex($body['audioIndex'] ?? 0);
        if ($audioIndex === null || $this->matcher->audioTrack($meta['audioTracks'] ?? [], $audioIndex) === null) {
            return Response::badRequest('invalid audioIndex', ['available' => $this->matcher->availableAudioIndexes($meta)]);
        }
        $caps = Capabilities::fromArray(is_array($body['capabilities'] ?? null) ? $body['capabilities'] : []);
        $offer = $this->matcher->match($meta, $caps, $audioIndex, $meta['variants'] ?? []);
        if ($offer === null) {
            return Response::badRequest('no matching recipe');
        }
        return Response::ok($this->statusPayload($id, $meta, $offer['recipe'], $audioIndex, $offer, false));
    }

    /** `force` is admin-only; App checks the admin header before calling this. */
    public function prepare(array $body): Response
    {
        $id = (string) ($body['videoId'] ?? '');
        if (!VideoId::isValid($id)) {
            return Response::badRequest('invalid id');
        }
        if (!$this->sources->exists($id)) {
            return Response::notFound('source missing');
        }
        $meta = $this->meta->load($id);
        if ($meta === null) {
            return Response::notFound('source missing');
        }
        $audioIndex = self::parseAudioIndex($body['audioIndex'] ?? 0);
        if ($audioIndex === null || $this->matcher->audioTrack($meta['audioTracks'] ?? [], $audioIndex) === null) {
            return Response::badRequest('invalid audioIndex', ['available' => $this->matcher->availableAudioIndexes($meta)]);
        }
        $intent = (string) ($body['intent'] ?? 'play');
        if ($intent !== 'play' && $intent !== 'prefetch') {
            return Response::badRequest('invalid intent');
        }
        $force = (bool) ($body['force'] ?? false);

        if (!empty($body['recipe'])) {
            $recipe = (string) $body['recipe'];
            if (!Recipe::isValid($recipe)) {
                return Response::badRequest('invalid recipe');
            }
            $offer = $this->offerFromRecipe($meta, $recipe, $audioIndex);
            if ($offer === null) {
                return Response::badRequest('no matching recipe');
            }
        } else {
            $caps = Capabilities::fromArray(is_array($body['capabilities'] ?? null) ? $body['capabilities'] : []);
            $offer = $this->matcher->match($meta, $caps, $audioIndex, $meta['variants'] ?? []);
            if ($offer === null) {
                return Response::badRequest('no matching recipe');
            }
            $recipe = $offer['recipe'];
        }

        if ($force) {
            if ($this->worker->hasLock($id, $recipe, $audioIndex)) {
                // Deleting files under a running FFmpeg corrupts both; cancel via DELETE first.
                return Response::conflict('busy', $this->statusPayload($id, $meta, $recipe, $audioIndex, $offer, false));
            }
            $this->hls->deleteVariant($id, $recipe, $audioIndex);
            $meta = $this->meta->update($id, function (array $m) use ($recipe, $audioIndex) {
                $variant = $this->meta->findVariant($m, $recipe, $audioIndex);
                if ($variant) {
                    $variant['failCount'] = 0;
                    $variant['error'] = null;
                    $variant['state'] = 'starting';
                    $variant['lastFailAt'] = null;
                    $m = $this->meta->upsertVariant($m, $variant);
                }
                return $m;
            });
        }

        $variant = $this->meta->findVariant($meta, $recipe, $audioIndex);
        if ($variant && ($variant['state'] ?? '') === 'failed') {
            $fails = (int) ($variant['failCount'] ?? 0);
            if ($intent === 'prefetch' || $fails >= $this->config->maxVariantFails) {
                $payload = $this->statusPayload($id, $meta, $recipe, $audioIndex, $offer, false);
                $this->logPrepare($intent, $id, $recipe, $audioIndex, 'failed', $payload);
                return Response::conflict('failed', $payload);
            }
        }

        if ($variant && $this->servable($id, $variant, $recipe, $audioIndex)) {
            $payload = $this->statusPayload($id, $meta, $recipe, $audioIndex, $offer, $intent === 'play');
            $this->logPrepare($intent, $id, $recipe, $audioIndex, 'ready', $payload);
            return Response::ok($payload);
        }

        if ($this->worker->isActive($id, $recipe, $audioIndex)) {
            if ($intent === 'play') {
                $this->worker->upgradeIntent($id, $recipe, $audioIndex);
            }
            $payload = $this->statusPayload($id, $meta, $recipe, $audioIndex, $offer, $intent === 'play');
            $this->logPrepare($intent, $id, $recipe, $audioIndex, $intent === 'play' ? 'active-play' : 'active', $payload);
            return Response::accepted($payload);
        }

        if ($intent === 'prefetch' && $this->worker->queuedOrRunningCount() >= $this->config->maxConcurrentJobs) {
            $this->logPrepare($intent, $id, $recipe, $audioIndex, 'queue-full');
            return Response::tooMany('queue full', 15);
        }

        $becameReady = false;
        $meta = $this->meta->update($id, function (array $m) use ($recipe, $audioIndex, $offer, &$becameReady) {
            $variant = $this->meta->findVariant($m, $recipe, $audioIndex) ?? [];
            if (($variant['state'] ?? '') === 'ready' && $this->hls->playable($m['id'], $recipe, $audioIndex)) {
                $becameReady = true; // the worker finished it meanwhile; do not re-encode
                return $m;
            }
            $variant['recipe'] = $recipe;
            $variant['audioIndex'] = $audioIndex;
            $variant['state'] = 'starting';
            $variant['mode'] = $offer['mode'];
            $variant['protocol'] = 'hls';
            $variant['video'] = $offer['video'];
            $variant['audio'] = $offer['audio'];
            $variant['playlistPath'] = $this->hls->relativeMaster($m['id'], $recipe, $audioIndex);
            $variant['segmentCount'] = $variant['segmentCount'] ?? 0;
            $variant['durationReadySec'] = $variant['durationReadySec'] ?? 0;
            $variant['lastAccessAt'] = $this->clock->now();
            $variant['readyAt'] = $variant['readyAt'] ?? null;
            $variant['failCount'] = (int) ($variant['failCount'] ?? 0);
            $variant['lastFailAt'] = $variant['lastFailAt'] ?? null;
            $variant['error'] = $variant['error'] ?? null;
            return $this->meta->upsertVariant($m, $variant);
        });
        if ($becameReady) {
            $payload = $this->statusPayload($id, $meta, $recipe, $audioIndex, $offer, $intent === 'play');
            return Response::ok($payload);
        }

        $this->worker->enqueue($id, $recipe, $audioIndex, $intent, $offer);
        $meta = $this->meta->read($id) ?? $meta;
        $payload = $this->statusPayload($id, $meta, $recipe, $audioIndex, $offer, $intent === 'play');
        $this->logPrepare($intent, $id, $recipe, $audioIndex, 'enqueued', $payload);
        return Response::accepted($payload);
    }

    /** @param array<string,mixed> $payload */
    private function logPrepare(string $intent, string $id, string $recipe, int $audio, string $result, array $payload = []): void
    {
        if ($result === 'ready' || $result === 'active') {
            return; // repeated prefetch polls are no-ops; keep the log for state changes
        }
        $state = (string) ($payload['state'] ?? '');
        $ready = $payload['durationReadySec'] ?? '';
        error_log(sprintf(
            '[playback] prepare intent=%s id=%s recipe=%s audio=%d result=%s state=%s readySec=%s queue=%d',
            $intent,
            $id,
            $recipe,
            $audio,
            $result,
            $state,
            is_numeric($ready) ? (string) $ready : '',
            $this->worker->queuedOrRunningCount(),
        ));
    }

    public function status(string $id, string $recipe, string $audioRaw, bool $playIntent = false): Response
    {
        if (!VideoId::isValid($id) || !Recipe::isValid($recipe)) {
            return Response::badRequest('invalid id or recipe');
        }
        $audioIndex = self::parseAudioIndex($audioRaw);
        if ($audioIndex === null) {
            return Response::badRequest('invalid audioIndex');
        }
        if (!$this->sources->exists($id)) {
            return Response::notFound('source missing');
        }
        $meta = $this->meta->load($id);
        if ($meta === null) {
            return Response::notFound('source missing');
        }
        $variant = $this->meta->findVariant($meta, $recipe, $audioIndex);
        $offer = $variant ? [
            'recipe' => $recipe,
            'mode' => $variant['mode'] ?? $recipe,
            'video' => $variant['video'] ?? [],
            'audio' => $variant['audio'] ?? ['index' => $audioIndex, 'codec' => 'aac', 'channels' => 2],
        ] : $this->offerFromRecipe($meta, $recipe, $audioIndex);
        if ($offer === null) {
            return Response::badRequest('unknown variant');
        }
        return Response::ok($this->statusPayload($id, $meta, $recipe, $audioIndex, $offer, $playIntent));
    }

    public function playlist(string $id, string $recipe, string $audioRaw): Response
    {
        $ready = $this->readyVariant($id, $recipe, $audioRaw);
        if ($ready instanceof Response) {
            return $ready;
        }
        [$audioIndex] = $ready;
        $this->touchPlay($id, $recipe, $audioIndex, true);
        return Response::ok([
            'playlistUrl' => $this->hls->publicUrl($id, $recipe, $audioIndex),
            'protocol' => 'hls',
        ]);
    }

    /**
     * Admin-only (App checks the header). Cancels jobs for the affected variants,
     * removes their cache directories and variant records. Never the MKV.
     */
    public function deleteCache(string $id, ?string $recipe, ?string $audioRaw): Response
    {
        if (!VideoId::isValid($id)) {
            return Response::badRequest('invalid id');
        }
        if ($recipe !== null && $recipe !== '' && !Recipe::isValid($recipe)) {
            return Response::badRequest('invalid recipe');
        }
        $recipe = $recipe === '' ? null : $recipe;
        $audioIndex = null;
        if ($audioRaw !== null && $audioRaw !== '') {
            $audioIndex = self::parseAudioIndex($audioRaw);
            if ($audioIndex === null) {
                return Response::badRequest('invalid audioIndex');
            }
        }

        $meta = $this->meta->read($id);
        $targets = [];
        foreach ($recipe === null ? Recipe::ALL : [$recipe] as $r) {
            foreach ($this->hls->audioIndexesOnDisk($id, $r) as $a) {
                $targets[$r . '|' . $a] = [$r, $a];
            }
        }
        foreach ($meta['variants'] ?? [] as $v) {
            if (is_array($v) && Recipe::isValid((string) ($v['recipe'] ?? '')) && ($recipe === null || $v['recipe'] === $recipe)) {
                $targets[$v['recipe'] . '|' . (int) $v['audioIndex']] = [(string) $v['recipe'], (int) $v['audioIndex']];
            }
        }
        foreach ($this->locks->forVideo($id) as $lock) {
            $r = (string) ($lock['recipe'] ?? '');
            if (Recipe::isValid($r) && ($recipe === null || $r === $recipe) && is_int($lock['audioIndex'] ?? null)) {
                $targets[$r . '|' . $lock['audioIndex']] = [$r, $lock['audioIndex']];
            }
        }
        if ($audioIndex !== null) {
            $targets = array_filter($targets, static fn (array $t): bool => $t[1] === $audioIndex);
            if ($recipe !== null) {
                $targets[$recipe . '|' . $audioIndex] = [$recipe, $audioIndex];
            }
        }

        foreach ($targets as [$r, $a]) {
            $this->worker->cancel($id, $r, $a);
            $this->hls->deleteVariant($id, $r, $a);
        }
        if ($recipe === null && $audioIndex === null) {
            $this->hls->deleteAllFor($id);
        }
        if ($meta !== null) {
            $this->meta->update($id, function (array $m) use ($recipe, $audioIndex) {
                $keep = [];
                foreach ($m['variants'] ?? [] as $v) {
                    $matches = ($recipe === null || ($v['recipe'] ?? '') === $recipe)
                        && ($audioIndex === null || (int) ($v['audioIndex'] ?? -1) === $audioIndex);
                    if (!$matches) {
                        $keep[] = $v;
                    }
                }
                $m['variants'] = $keep;
                return $m;
            });
        }
        return Response::ok(['ok' => true, 'deleted' => count($targets)]);
    }

    /**
     * @param array<string,mixed> $meta
     * @param array<string,mixed> $offer
     * @return array<string,mixed>
     */
    public function statusPayload(string $id, array $meta, string $recipe, int $audioIndex, array $offer, bool $playIntent): array
    {
        $variant = $this->meta->findVariant($meta, $recipe, $audioIndex);
        $state = (string) ($variant['state'] ?? 'missing');
        $playable = $variant !== null && $this->servable($id, $variant, $recipe, $audioIndex);
        $info = $this->hls->inspect($id, $recipe, $audioIndex);
        if ($state === 'ready' && !$playable) {
            $state = $info['hasPlaylist'] ? 'running' : 'missing';
        }
        $playlistUrl = $playable ? $this->hls->publicUrl($id, $recipe, $audioIndex) : null;

        if ($playIntent && $playlistUrl) {
            $this->touchPlay($id, $recipe, $audioIndex, true);
            $meta = $this->meta->read($id) ?? $meta;
        } elseif ($variant) {
            $this->touchAccess($id, $recipe, $audioIndex);
        }

        $payload = [
            'videoId' => $id,
            'recipe' => $recipe,
            'audioIndex' => $audioIndex,
            'state' => $state,
            'mode' => $offer['mode'] ?? ($variant['mode'] ?? $recipe),
            'protocol' => 'hls',
            'video' => $offer['video'] ?? ($variant['video'] ?? ['codec' => '', 'width' => 0, 'height' => 0]),
            'audio' => $offer['audio'] ?? ($variant['audio'] ?? ['index' => $audioIndex, 'codec' => 'aac', 'channels' => 2]),
            'playlistUrl' => $playlistUrl,
            'playlistPath' => $this->hls->relativeMaster($id, $recipe, $audioIndex),
            'segmentCount' => $info['segmentCount'],
            'durationReadySec' => $info['durationReadySec'],
            'failCount' => (int) ($variant['failCount'] ?? 0),
            'error' => $variant['error'] ?? null,
        ];
        if (in_array($state, ['running', 'starting'], true)) {
            $payload['progress'] = [
                'segmentCount' => $info['segmentCount'],
                'durationReadySec' => $info['durationReadySec'],
                'durationTotalSec' => $meta['durationSec'] ?? null,
            ];
        }
        return $payload;
    }

    /** Non-negative integer (int or digit string), else null. */
    public static function parseAudioIndex(mixed $raw): ?int
    {
        if (is_int($raw)) {
            return $raw >= 0 ? $raw : null;
        }
        if (is_string($raw) && preg_match('/^\d{1,4}$/', $raw) === 1) {
            return (int) $raw;
        }
        return null;
    }

    /**
     * State ready (worker-verified) and a finished playlist on disk. Finalizing is
     * idempotent; it migrates caches written before segment URLs were versioned.
     *
     * @param array<string,mixed> $variant
     */
    private function servable(string $id, array $variant, string $recipe, int $audioIndex): bool
    {
        if (($variant['state'] ?? '') !== 'ready' || !$this->hls->playable($id, $recipe, $audioIndex)) {
            return false;
        }
        $this->hls->finalizeForVod($id, $recipe, $audioIndex);
        return true;
    }

    /** @return array{0:int}|Response */
    private function readyVariant(string $id, string $recipe, string $audioRaw): array|Response
    {
        if (!VideoId::isValid($id) || !Recipe::isValid($recipe)) {
            return Response::badRequest('invalid id or recipe');
        }
        $audioIndex = self::parseAudioIndex($audioRaw);
        if ($audioIndex === null) {
            return Response::badRequest('invalid audioIndex');
        }
        $meta = $this->meta->load($id);
        if ($meta === null) {
            return Response::notFound('source missing');
        }
        $variant = $this->meta->findVariant($meta, $recipe, $audioIndex);
        if ($variant === null) {
            return Response::conflict('not_prepared');
        }
        if (($variant['state'] ?? '') === 'failed') {
            return Response::conflict('failed');
        }
        if (!$this->servable($id, $variant, $recipe, $audioIndex)) {
            return Response::conflict('not_prepared');
        }
        return [$audioIndex];
    }

    /** @param array<string,mixed> $meta */
    private function offerFromRecipe(array $meta, string $recipe, int $audioIndex): ?array
    {
        $audio = $this->matcher->audioTrack($meta['audioTracks'] ?? [], $audioIndex);
        if ($audio === null) {
            return null;
        }
        $video = is_array($meta['video'] ?? null) ? $meta['video'] : [];
        $caps = new Capabilities(['hls'], ['avc1', 'hvc1'], ['mp4a.40.2'], 7680, 4320, false);
        foreach ($this->matcher->candidates($video, $audio, $caps, (int) ($video['width'] ?? 0), (int) ($video['height'] ?? 0)) as $c) {
            if ($c['recipe'] === $recipe) {
                return $c;
            }
        }
        if ($recipe === Recipe::AVC_1080) {
            $scaled = $this->matcher->scale1080((int) ($video['width'] ?? 1920), (int) ($video['height'] ?? 1080));
            return [
                'recipe' => Recipe::AVC_1080,
                'mode' => 'avc_1080',
                'video' => ['codec' => 'avc1', 'width' => $scaled[0], 'height' => $scaled[1]],
                'audio' => ['index' => $audioIndex, 'codec' => 'aac', 'channels' => 2],
            ];
        }
        return null;
    }

    private function touchPlay(string $id, string $recipe, int $audioIndex, bool $asPlay): void
    {
        $this->meta->update($id, function (array $m) use ($recipe, $audioIndex, $asPlay) {
            if ($asPlay) {
                $m['lastPlayedAt'] = $this->clock->now();
            }
            $variant = $this->meta->findVariant($m, $recipe, $audioIndex);
            if ($variant) {
                $variant['lastAccessAt'] = $this->clock->now();
                $m = $this->meta->upsertVariant($m, $variant);
            }
            return $m;
        });
    }

    /** lastAccessAt only feeds cleanup age; refresh at most once a minute so polls stay read-only. */
    private function touchAccess(string $id, string $recipe, int $audioIndex): void
    {
        $this->meta->update($id, function (array $m) use ($recipe, $audioIndex) {
            $variant = $this->meta->findVariant($m, $recipe, $audioIndex);
            $last = strtotime((string) ($variant['lastAccessAt'] ?? '')) ?: 0;
            if ($variant && $this->clock->unix() - $last >= 60) {
                $variant['lastAccessAt'] = $this->clock->now();
                $m = $this->meta->upsertVariant($m, $variant);
            }
            return $m;
        });
    }
}
