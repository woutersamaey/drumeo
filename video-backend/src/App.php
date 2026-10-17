<?php

declare(strict_types=1);

namespace Drumeo\Video;

use Drumeo\Video\Admin\Overview;
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
        public readonly Overview $overview,
        public readonly Lock $locks,
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
        $locks = new Lock($config);
        $meta = new MetadataStore($config, $sources, $probe, $clock, $hls, $locks);
        $matcher = new Matcher();
        $jobs = new JobRegistry($config);
        $encoders = new EncoderPicker($config);
        $commands = new FfmpegCommand($config, $matcher, $encoders);
        $runner ??= new ProcFfmpegRunner();
        $cleaner = new Cleaner($config, $meta, $hls, $clock, $locks);
        $worker = new Worker($config, $meta, $sources, $hls, $locks, $jobs, $commands, $runner, $clock, $encoders, $cleaner);
        $playback = new PlaybackService($config, $sources, $meta, $hls, $matcher, $worker, $clock, $encoders, $locks);
        $overview = new Overview($config, $sources, $meta, $hls, $cleaner, $jobs, $locks, $encoders, $clock);
        return new self($config, $sources, $meta, $hls, $matcher, $worker, $playback, $jobs, $cleaner, $probe, $clock, $encoders, $overview, $locks);
    }

    public function handle(Request $request): Response
    {
        $auth = new Auth($this->config);
        $denied = $auth->check($request);
        if ($denied && $request->path !== '/api/health' && $request->method !== 'OPTIONS') {
            return $denied;
        }

        try {
            return $this->router($auth)->dispatch($request);
        } catch (\InvalidArgumentException $e) {
            return Response::badRequest($e->getMessage());
        } catch (\Throwable $e) {
            error_log(sprintf('[api] %s %s: %s', $request->method, $request->path, $e));
            return Response::serverError();
        }
    }

    private function router(Auth $auth): Router
    {
        $router = new Router();
        $p = $this->playback;

        $router->get('/api/health', fn () => $p->health());
        $router->get('/api/videos', fn () => $p->listVideos());
        $router->get('/api/videos/{id}', function (Request $req, array $params) use ($p) {
            return $p->getVideo($params['id']);
        });
        $router->post('/api/playback/query', fn (Request $req) => $p->query($req->json));
        $router->post('/api/playback/prepare', function (Request $req) use ($p, $auth) {
            // force deletes a cache and resets the fail counter: admin only.
            if ((bool) ($req->json['force'] ?? false)) {
                $admin = $auth->requireAdmin($req);
                if ($admin) {
                    return $admin;
                }
            }
            return $p->prepare($req->json);
        });
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
                'state' => $job['state'] ?? null,
                'startedAt' => $job['startedAt'] ?? null,
                'exitCode' => $job['exitCode'] ?? null,
            ]);
        });
        $router->delete('/api/videos/{id}/cache', function (Request $req, array $params) use ($p, $auth) {
            $admin = $auth->requireAdmin($req);
            if ($admin) {
                return $admin;
            }
            return $p->deleteCache(
                $params['id'],
                $req->query('recipe'),
                $req->query('audioIndex'),
            );
        });
        $router->get('/api/admin/overview', function (Request $req) use ($auth) {
            $admin = $auth->requireAdmin($req);
            if ($admin) {
                return $admin;
            }
            return Response::ok($this->overview->collect());
        });
        $router->post('/api/admin/cleanup', function (Request $req) use ($auth) {
            $admin = $auth->requireAdmin($req);
            if ($admin) {
                return $admin;
            }
            if (($req->json['confirm'] ?? false) !== true) {
                return Response::badRequest('confirm required');
            }
            $result = $this->cleaner->run(false);
            $bytes = 0;
            foreach ($result['deleted'] as $row) {
                $bytes += (int) ($row['bytes'] ?? 0);
            }
            return Response::ok($result + [
                'count' => count($result['deleted']),
                'bytes' => $bytes,
            ]);
        });
        return $router;
    }
}
