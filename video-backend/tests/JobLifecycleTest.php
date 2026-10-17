<?php

declare(strict_types=1);

namespace Drumeo\Video\Tests;

use Drumeo\Video\App;
use Drumeo\Video\Http\Request;
use Drumeo\Video\Jobs\JobRegistry;
use Drumeo\Video\Jobs\Lock;
use PHPUnit\Framework\TestCase;

final class JobLifecycleTest extends TestCase
{
    private TestHarness $h;

    protected function setUp(): void
    {
        $this->h = new TestHarness(adminToken: 'adm');
        $this->h->seedProbed('film1', 24.0);
    }

    protected function tearDown(): void
    {
        $this->h->destroy();
    }

    public function testApiSeesRunningJobThroughHeartbeatNotPid(): void
    {
        $worker = $this->h->app->worker;
        $this->prepare('play');
        $this->assertTrue($worker->isActive('film1', 'remux', 0), 'queued counts as active');

        $worker->tick();
        $lock = $this->h->app->locks->read('film1', 'remux', 0);
        $this->assertIsInt($lock['pid']);
        $this->assertSame($this->h->clock->unix(), $lock['heartbeatUnix']);
        $this->assertTrue($worker->isActive('film1', 'remux', 0));

        $this->h->clock->advance(Lock::STALE_AFTER + 1);
        $this->assertFalse($worker->isActive('film1', 'remux', 0), 'no heartbeat for >30s = not active');

        $worker->tick(); // still running: refreshes the heartbeat
        $this->assertSame($this->h->clock->unix(), $this->h->app->locks->read('film1', 'remux', 0)['heartbeatUnix']);
        $this->assertTrue($worker->isActive('film1', 'remux', 0));
    }

    public function testPruneJobRecords(): void
    {
        $jobs = $this->h->app->jobs;
        $now = $this->h->clock->unix();
        $this->prepare('play'); // creates a lock + queued record
        $liveId = (string) $this->h->app->locks->read('film1', 'remux', 0)['jobId'];

        $records = [
            'aa01' => ['done', $now - 8 * 86400],                               // old finished → pruned
            'aa02' => ['failed', $now - 86400],                                 // recent finished → kept
            'aa03' => ['running', $now - JobRegistry::ORPHAN_GRACE_SECONDS - 5], // no lock → pruned
            'aa04' => ['queued', $now - 60],                                     // no lock, within grace → kept
        ];
        foreach ($records as $id => [$state, $mtime]) {
            $jobs->save(['jobId' => $id, 'state' => $state]);
            touch($this->h->config->jobDir() . '/' . $id . '.json', $mtime);
        }
        touch($this->h->config->jobDir() . '/' . $liveId . '.json', $now - 86400); // old but locked → kept

        $this->assertSame(2, $this->h->app->worker->pruneJobs());
        $this->assertNull($jobs->get('aa01'));
        $this->assertNotNull($jobs->get('aa02'));
        $this->assertNull($jobs->get('aa03'));
        $this->assertNotNull($jobs->get('aa04'));
        $this->assertNotNull($jobs->get($liveId));
    }

    public function testOverviewShowsLocklessJobsAsAbandoned(): void
    {
        $this->h->app->jobs->save(['jobId' => 'bb01', 'state' => 'running', 'exitCode' => null]);
        $view = $this->h->app->overview->collect();
        $this->assertSame([], $view['jobs']['active']);
        $this->assertSame('abandoned', $view['jobs']['recent'][0]['state']);
    }

    public function testRestartFailsLocksOfPreviousWorkerAndUpdatesJobRecord(): void
    {
        $this->prepare('play');
        $this->h->app->worker->tick();
        $jobId = (string) $this->h->app->locks->read('film1', 'remux', 0)['jobId'];

        // New worker process: fresh runner, same disk.
        $restarted = App::build($this->h->config, new FakeFfmpegRunner(), $this->h->clock);
        $restarted->worker->recoverAbandoned();

        $this->assertNull($restarted->locks->read('film1', 'remux', 0));
        $v = $restarted->meta->findVariant($restarted->meta->read('film1'), 'remux', 0);
        $this->assertSame('failed', $v['state']);
        $this->assertStringStartsWith('abandoned', $v['error']);
        $this->assertSame('failed', $restarted->jobs->get($jobId)['state']);
    }

    public function testRestartFailsStartingVariantWithoutLock(): void
    {
        $old = gmdate('Y-m-d\TH:i:s\Z', $this->h->clock->unix() - 3600);
        $this->h->seedProbed('film1', 24.0, [TestHarness::variant('remux', 0, 'running', ['lastAccessAt' => $old])]);
        $this->h->app->worker->recoverAbandoned();
        $v = $this->h->app->meta->findVariant($this->h->app->meta->read('film1'), 'remux', 0);
        $this->assertSame('failed', $v['state']);
    }

    public function testDeleteCacheCancelsRunningJob(): void
    {
        $this->prepare('play');
        $this->h->app->worker->tick();
        $runner = $this->h->runner;
        $this->assertInstanceOf(FakeFfmpegRunner::class, $runner);
        $pid = $runner->lastPid();
        $jobId = (string) $this->h->app->locks->read('film1', 'remux', 0)['jobId'];

        $res = $this->h->app->handle(new Request('DELETE', '/api/videos/film1/cache', [], ['x-drumeo-admin' => 'adm'], [], ''));
        $this->assertSame(200, $res->status);
        $this->assertTrue($this->h->app->locks->read('film1', 'remux', 0)['cancel']);

        $this->h->app->worker->tick();
        $this->assertSame([$pid], $runner->stopped);
        $this->assertNull($this->h->app->locks->read('film1', 'remux', 0));
        $this->assertSame('cancelled', $this->h->app->jobs->get($jobId)['state']);
        $this->assertDirectoryDoesNotExist($this->h->root . '/cache/film1/remux/0');
        $this->assertNull($this->h->app->meta->findVariant($this->h->app->meta->read('film1'), 'remux', 0));
    }

    public function testDeleteCacheDropsQueuedJob(): void
    {
        $this->prepare('play');
        $jobId = (string) $this->h->app->locks->read('film1', 'remux', 0)['jobId'];
        $req = new Request('DELETE', '/api/videos/film1/cache', ['recipe' => 'remux', 'audioIndex' => '0'], ['x-drumeo-admin' => 'adm'], [], '');
        $this->assertSame(200, $this->h->app->handle($req)->status);
        $this->assertNull($this->h->app->locks->read('film1', 'remux', 0));
        $this->assertSame('cancelled', $this->h->app->jobs->get($jobId)['state']);
        $this->h->app->worker->tick();
        $this->assertSame(0, $this->h->runner->starts);
    }

    public function testSourceChangeWhileJobRunsDefersCacheInvalidation(): void
    {
        $this->prepare('play');
        $this->h->app->worker->tick();
        file_put_contents($this->h->root . '/bron/film1.mkv', 'replaced while encoding');
        $this->h->app->sources->refresh();

        $this->h->app->handle($this->h->get('/api/videos/film1'));
        $this->assertDirectoryExists($this->h->root . '/cache/film1/remux/0', 'running job keeps its directory');

        $runner = $this->h->runner;
        $this->assertInstanceOf(FakeFfmpegRunner::class, $runner);
        $runner->finishWith($runner->lastPid(), 0, 4, true);
        $this->h->app->worker->tick();
        $this->assertNull($this->h->app->locks->read('film1', 'remux', 0));

        $res = $this->h->app->handle($this->h->get('/api/videos/film1'));
        $this->assertSame([], $res->body['variants']);
        $this->assertDirectoryDoesNotExist($this->h->root . '/cache/film1');
    }

    public function testFfmpegLogIsWrittenOutsideTheCacheDir(): void
    {
        $this->prepare('play');
        $this->h->app->worker->tick();
        $this->assertFileExists($this->h->config->logDir() . '/film1__remux__0.log');
        $this->assertFileDoesNotExist($this->h->root . '/cache/film1/remux/0/ffmpeg.log');
    }

    private function prepare(string $intent): void
    {
        $res = $this->h->app->handle($this->h->post('/api/playback/prepare', [
            'videoId' => 'film1', 'recipe' => 'remux', 'audioIndex' => 0, 'intent' => $intent,
        ]));
        $this->assertSame(202, $res->status);
    }
}
