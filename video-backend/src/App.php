<?php

declare(strict_types=1);

namespace Drumeo\Video;

use Drumeo\Video\Cleanup\Cleaner;
use Drumeo\Video\Http\Auth;
use Drumeo\Video\Http\Request;
use Drumeo\Video\Http\Response;
use Drumeo\Video\Http\Router;
use Drumeo\Video\Jobs\EncoderPicker;
use Drumeo\Video\Jobs\FfmpegCommand;
use Drumeo\Video\Jobs\FfmpegRunner;
use Drumeo\Video\Jobs\JobRegistry;
use Drumeo\Video\Jobs\Lock;
use Drumeo\Video\Jobs\ProcFfmpegRunner;
use Drumeo\Video\Jobs\Worker;
use Drumeo\Video\Playback\PlaybackService;
use Drumeo\Video\Playback\PlayerRenderer;
use Drumeo\Video\Probe\Probe;
use Drumeo\Video\Recipe\Matcher;
use Drumeo\Video\Store\HlsCache;
use Drumeo\Video\Store\MetadataStore;
use Drumeo\Video\Store\SourceStore;

final class App
{
    public function __construct(
        public readonly Config $config,
        public readonly SourceStore $sources,
        public readonly MetadataStore $meta,
        public readonly HlsCache $hls,
        public readonly Matcher $matcher,
        public readonly Worker $worker,
        public readonly PlaybackService $playback,
        public readonly JobRegistry $jobs,
        public readonly Cleaner $cleaner,
        public readonly Probe $probe,
        public readonly Clock $clock,
        public readonly EncoderPicker $encoders,
    ) {
    }

    public static function build(?Config $config = null, ?FfmpegRunner $runner = null, ?Clock $clock = null): self
    {
        $config ??= Config::fromEnv();
        $config->ensureDirectories();
        $clock ??= new SystemClock();
        $sources = new SourceStore($config);
        $probe = new Probe($config);
        $hls = new HlsCache($config);
        $meta = new MetadataStore($config, $sources, $probe, $clock, $hls);
        $matcher = new Matcher();
        $locks = new Lock($config);
        $jobs = new JobRegistry($config);
        $encoders = new EncoderPicker($config);
        $commands = new FfmpegCommand($config, $matcher, $encoders);
        $runner ??= new ProcFfmpegRunner();
        $worker = new Worker($config, $meta, $sources, $hls, $locks, $jobs, $commands, $runner, $clock);
        $player = new PlayerRenderer($config);
        $playback = new PlaybackService($config, $sources, $meta, $hls, $matcher, $worker, $clock, $player, $encoders);
        $cleaner = new Cleaner($config, $meta, $hls, $clock);
        return new self($config, $sources, $meta, $hls, $matcher, $worker, $playback, $jobs, $cleaner, $probe, $clock, $encoders);
    }

    public function handle(Request $request): Response
    {
        $auth = new Auth($this->config);
        $denied = $auth->check($request);
        if ($denied && $request->path !== '/api/health' && $request->method !== 'OPTIONS') {
            return $denied;
        }

        $router = new Router();
        $p = $this->playback;

        $router->get('/api/health', fn () => $p->health());
        $router->get('/api/videos', fn () => $p->listVideos());
        $router->get('/api/videos/{id}', function (Request $req, array $params) use ($p) {
            return $p->getVideo($params['id']);
        });
        $router->post('/api/playback/query', fn (Request $req) => $p->query($req->json));
        $router->post('/api/playback/prepare', fn (Request $req) => $p->prepare($req->json));
        $router->get('/api/playback/status', function (Request $req) use ($p) {
            return $p->status(
                (string) $req->query('videoId', ''),
                (string) $req->query('recipe', ''),
                (string) $req->query('audioIndex', ''),
                $req->query('intent') === 'play',
            );
        });
        $router->get('/api/playback/playlist', function (Request $req) use ($p) {
            return $p->playlist(
                (string) $req->query('videoId', ''),
                (string) $req->query('recipe', ''),
                (string) $req->query('audioIndex', ''),
            );
        });
        $router->get('/api/playback/player', function (Request $req) use ($p) {
            return $p->player(
                (string) $req->query('videoId', ''),
                (string) $req->query('recipe', ''),
                (string) $req->query('audioIndex', ''),
            );
        });
        $router->get('/api/jobs/{jobId}', function (Request $req, array $params) {
            $job = $this->jobs->get($params['jobId']);
            if ($job === null) {
                return Response::notFound('job not found');
            }
            return Response::ok([
                'jobId' => $job['jobId'] ?? $params['jobId'],
                'videoId' => $job['videoId'] ?? null,
                'recipe' => $job['recipe'] ?? null,
                'audioIndex' => $job['audioIndex'] ?? null,
                'pid' => $job['pid'] ?? null,
                'mode' => $job['mode'] ?? null,
                'intent' => $job['intent'] ?? null,
                'startedAt' => $job['startedAt'] ?? null,
                'exitCode' => $job['exitCode'] ?? null,
            ]);
        });
        $router->delete('/api/videos/{id}/cache', function (Request $req, array $params) use ($p) {
            return $p->deleteCache(
                $params['id'],
                $req->query('recipe'),
                $req->query('audioIndex'),
            );
        });

        $response = $router->dispatch($request);
        $headers = $response->headers + [
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Headers' => 'Authorization, Content-Type',
            'Access-Control-Allow-Methods' => 'GET, POST, DELETE, OPTIONS',
        ];
        return new Response($response->status, $response->body, $headers);
    }
}
