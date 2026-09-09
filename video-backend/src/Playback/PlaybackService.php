<?php

declare(strict_types=1);

namespace Drumeo\Video\Playback;

use Drumeo\Video\Clock;
use Drumeo\Video\Config;
use Drumeo\Video\Http\Response;
use Drumeo\Video\Jobs\EncoderPicker;
use Drumeo\Video\Jobs\Worker;
use Drumeo\Video\Recipe\Capabilities;
use Drumeo\Video\Recipe\Matcher;
use Drumeo\Video\Recipe\Recipe;
use Drumeo\Video\Store\HlsCache;
use Drumeo\Video\Store\MetadataStore;
use Drumeo\Video\Store\SourceStore;
use Drumeo\Video\VideoId;

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
        private readonly PlayerRenderer $player,
        private readonly EncoderPicker $encoders,
    ) {
    }

    public function health(): Response
    {
        $ffmpeg = $this->ffmpegOk();
        $choice = $this->encoders->choice();
        return Response::ok([
            'ok' => true,
            'ffmpeg' => $ffmpeg,
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
        $audioIndex = array_key_exists('audioIndex', $body) ? (int) $body['audioIndex'] : 0;
        $caps = Capabilities::fromArray(is_array($body['capabilities'] ?? null) ? $body['capabilities'] : []);
        $available = $this->matcher->availableAudioIndexes($meta);
        if ($this->matcher->audioTrack($meta['audioTracks'] ?? [], $audioIndex) === null) {
            return Response::badRequest('invalid audioIndex', ['available' => $available]);
        }
        $offer = $this->matcher->match($meta, $caps, $audioIndex, $meta['variants'] ?? []);
        if ($offer === null) {
            return Response::badRequest('no matching recipe');
        }
        return Response::ok($this->statusPayload($id, $meta, $offer['recipe'], $audioIndex, $offer, false));
    }

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
        $audioIndex = array_key_exists('audioIndex', $body) ? (int) $body['audioIndex'] : 0;
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
                $available = $this->matcher->availableAudioIndexes($meta);
                if ($this->matcher->audioTrack($meta['audioTracks'] ?? [], $audioIndex) === null) {
                    return Response::badRequest('invalid audioIndex', ['available' => $available]);
                }
                return Response::badRequest('no matching recipe');
            }
        } else {
            $caps = Capabilities::fromArray(is_array($body['capabilities'] ?? null) ? $body['capabilities'] : []);
            $available = $this->matcher->availableAudioIndexes($meta);
            if ($this->matcher->audioTrack($meta['audioTracks'] ?? [], $audioIndex) === null) {
                return Response::badRequest('invalid audioIndex', ['available' => $available]);
            }
            $offer = $this->matcher->match($meta, $caps, $audioIndex, $meta['variants'] ?? []);
            if ($offer === null) {
                return Response::badRequest('no matching recipe');
            }
            $recipe = $offer['recipe'];
        }

        if ($force) {
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
        if ($variant && ($variant['state'] ?? '') === 'failed' && !$force) {
            $fails = (int) ($variant['failCount'] ?? 0);
            $payload = $this->statusPayload($id, $meta, $recipe, $audioIndex, $offer, false);
            if ($intent === 'prefetch' || $fails >= $this->config->maxVariantFails) {
                return Response::conflict('failed', $payload);
            }
        }

        $meta = $this->syncDisk($id, $meta, $recipe, $audioIndex);
        $variant = $this->meta->findVariant($meta, $recipe, $audioIndex);
        if ($variant && ($variant['state'] ?? '') === 'ready' && $this->hls->playable($id, $recipe, $audioIndex)) {
            $payload = $this->statusPayload($id, $meta, $recipe, $audioIndex, $offer, $intent === 'play');
            return Response::ok($payload);
        }

        if ($this->worker->isActive($id, $recipe, $audioIndex)) {
            if ($intent === 'play') {
                $this->worker->upgradeIntent($id, $recipe, $audioIndex);
            }
            $payload = $this->statusPayload($id, $meta, $recipe, $audioIndex, $offer, $intent === 'play');
            return Response::accepted($payload);
        }

        if ($intent === 'prefetch' && $this->worker->queuedOrRunningCount() >= $this->config->maxConcurrentJobs) {
            return Response::tooMany('queue full', 15);
        }

        $this->meta->update($id, function (array $m) use ($recipe, $audioIndex, $offer) {
            $variant = $this->meta->findVariant($m, $recipe, $audioIndex) ?? [];
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

        $this->worker->enqueue($id, $recipe, $audioIndex, $intent, $offer);
        $meta = $this->meta->read($id) ?? $meta;
        $payload = $this->statusPayload($id, $meta, $recipe, $audioIndex, $offer, $intent === 'play');
        return Response::accepted($payload);
    }

    public function status(string $id, string $recipe, string $audioRaw, bool $playIntent = false): Response
    {
        if (!VideoId::isValid($id) || !Recipe::isValid($recipe)) {
            return Response::badRequest('invalid id or recipe');
        }
        if (!preg_match('/^-?\d+$/', $audioRaw)) {
            return Response::badRequest('invalid audioIndex');
        }
        $audioIndex = (int) $audioRaw;
        if (!$this->sources->exists($id)) {
            return Response::notFound('source missing');
        }
        $meta = $this->meta->load($id);
        if ($meta === null) {
            return Response::notFound('source missing');
        }
        $meta = $this->syncDisk($id, $meta, $recipe, $audioIndex);
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
        $payload = $this->statusPayload($id, $meta, $recipe, $audioIndex, $offer, $playIntent);
        return Response::ok($payload);
    }

    public function playlist(string $id, string $recipe, string $audioRaw): Response
    {
        if (!VideoId::isValid($id) || !Recipe::isValid($recipe)) {
            return Response::badRequest('invalid id or recipe');
        }
        $audioIndex = (int) $audioRaw;
        $meta = $this->meta->load($id);
        if ($meta === null) {
            return Response::notFound('source missing');
        }
        $variant = $this->meta->findVariant($meta, $recipe, $audioIndex);
        if (!$variant || ($variant['state'] ?? '') === 'failed') {
            return Response::conflict($variant['state'] ?? 'not_prepared');
        }
        if (!$this->hls->playable($id, $recipe, $audioIndex)) {
            return Response::conflict('not_prepared');
        }
        $this->touchPlay($id, $recipe, $audioIndex, true);
        return Response::ok([
            'playlistUrl' => $this->hls->publicUrl($id, $recipe, $audioIndex),
            'protocol' => 'hls',
        ]);
    }

    public function player(string $id, string $recipe, string $audioRaw): Response
    {
        if (!VideoId::isValid($id) || !Recipe::isValid($recipe)) {
            return Response::badRequest('invalid id or recipe');
        }
        $audioIndex = (int) $audioRaw;
        $meta = $this->meta->load($id);
        if ($meta === null) {
            return Response::notFound('source missing');
        }
        if (!$this->hls->playable($id, $recipe, $audioIndex)) {
            return Response::conflict('not_prepared');
        }
        $this->touchPlay($id, $recipe, $audioIndex, true);
        $url = $this->hls->publicUrl($id, $recipe, $audioIndex);
        return Response::ok($this->player->render($id, $recipe, $audioIndex, $url));
    }

    public function job(string $jobId): Response
    {
        // accessed via worker registry through a thin wrapper in Kernel
        return Response::notFound('not found');
    }

    public function deleteCache(string $id, ?string $recipe, ?string $audioRaw): Response
    {
        if (!VideoId::isValid($id)) {
            return Response::badRequest('invalid id');
        }
        if ($recipe !== null && $recipe !== '') {
            if (!Recipe::isValid($recipe)) {
                return Response::badRequest('invalid recipe');
            }
            $audioIndex = $audioRaw !== null && $audioRaw !== '' ? (int) $audioRaw : null;
            if ($audioIndex === null) {
                foreach (['0', '1', '2', '3', '4', '5', '6', '7'] as $idx) {
                    $this->hls->deleteVariant($id, $recipe, (int) $idx);
                }
                if ($this->meta->read($id)) {
                    $this->meta->update($id, function (array $m) use ($recipe) {
                        $keep = [];
                        foreach ($m['variants'] ?? [] as $v) {
                            if (($v['recipe'] ?? '') !== $recipe) {
                                $keep[] = $v;
                            }
                        }
                        $m['variants'] = $keep;
                        return $m;
                    });
                }
            } else {
                $this->hls->deleteVariant($id, $recipe, $audioIndex);
                if ($this->meta->read($id)) {
                    $this->meta->update($id, fn (array $m) => $this->meta->removeVariant($m, $recipe, $audioIndex));
                }
            }
        } else {
            $this->hls->deleteAllFor($id);
            if ($this->meta->read($id)) {
                $this->meta->update($id, function (array $m) {
                    $m['variants'] = [];
                    return $m;
                });
            }
        }
        return Response::ok(['ok' => true]);
    }

    /**
     * @param array<string,mixed> $meta
     * @param array<string,mixed> $offer
     * @return array<string,mixed>
     */
    public function statusPayload(string $id, array $meta, string $recipe, int $audioIndex, array $offer, bool $playIntent): array
    {
        $info = $this->hls->inspect($id, $recipe, $audioIndex);
        if ($info['hasEndlist']) {
            $this->hls->finalizeForVod($id, $recipe, $audioIndex);
            $info = $this->hls->inspect($id, $recipe, $audioIndex);
        }
        $variant = $this->meta->findVariant($meta, $recipe, $audioIndex);
        $state = $variant['state'] ?? ($info['hasEndlist'] ? 'ready' : ($info['hasPlaylist'] ? 'running' : 'missing'));
        if ($info['hasEndlist']) {
            $state = 'ready';
        } elseif (in_array($state, ['ready'], true)) {
            $state = $info['hasPlaylist'] ? 'running' : 'missing';
        }
        $playable = $info['hasEndlist'] && $info['hasPlaylist'] && $info['segmentCount'] >= 1;
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
            'playerUrl' => '/api/playback/player?videoId=' . rawurlencode($id)
                . '&recipe=' . rawurlencode($recipe)
                . '&audioIndex=' . $audioIndex,
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

    /** @param array<string,mixed> $meta */
    private function offerFromRecipe(array $meta, string $recipe, int $audioIndex): ?array
    {
        $audio = $this->matcher->audioTrack($meta['audioTracks'] ?? [], $audioIndex);
        if ($audio === null) {
            return null;
        }
        $video = is_array($meta['video'] ?? null) ? $meta['video'] : [];
        $caps = new Capabilities(['hls'], ['avc1', 'hvc1'], ['mp4a.40.2'], 7680, 4320, false, true);
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

    /** @param array<string,mixed> $meta @return array<string,mixed> */
    private function syncDisk(string $id, array $meta, string $recipe, int $audioIndex): array
    {
        $info = $this->hls->inspect($id, $recipe, $audioIndex);
        $variant = $this->meta->findVariant($meta, $recipe, $audioIndex);
        if ($variant && ($variant['state'] ?? '') === 'ready' && !$info['hasPlaylist']) {
            $meta = $this->meta->update($id, fn (array $m) => $this->meta->removeVariant($m, $recipe, $audioIndex));
        }
        return $meta;
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

    private function touchAccess(string $id, string $recipe, int $audioIndex): void
    {
        $this->meta->update($id, function (array $m) use ($recipe, $audioIndex) {
            $variant = $this->meta->findVariant($m, $recipe, $audioIndex);
            if ($variant) {
                $variant['lastAccessAt'] = $this->clock->now();
                $m = $this->meta->upsertVariant($m, $variant);
            }
            return $m;
        });
    }

    private static ?bool $ffmpegCached = null;

    private function ffmpegOk(): bool
    {
        if (self::$ffmpegCached !== null) {
            return self::$ffmpegCached;
        }
        $out = [];
        $code = 0;
        exec(escapeshellcmd($this->config->ffmpegBin) . ' -version 2>&1', $out, $code);
        self::$ffmpegCached = $code === 0;
        return self::$ffmpegCached;
    }
}
