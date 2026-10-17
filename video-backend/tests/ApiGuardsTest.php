<?php

declare(strict_types=1);

namespace Drumeo\Video\Tests;

use Drumeo\Video\Http\Request;
use PHPUnit\Framework\TestCase;

/** Admin-only destructive operations, audioIndex validation, no CORS headers. */
final class ApiGuardsTest extends TestCase
{
    private TestHarness $h;

    protected function setUp(): void
    {
        $this->h = new TestHarness(adminToken: 'adm');
        $this->h->seedProbed('film1', 24.0, [TestHarness::variant('remux', 0, 'ready')]);
        $this->h->plantHls('film1', 'remux', 0, 4, true);
    }

    protected function tearDown(): void
    {
        $this->h->destroy();
    }

    public function testForcePrepareRequiresAdmin(): void
    {
        $body = ['videoId' => 'film1', 'recipe' => 'remux', 'audioIndex' => 0, 'intent' => 'play', 'force' => true];
        $this->assertSame(401, $this->h->app->handle($this->h->post('/api/playback/prepare', $body))->status);
        $this->assertSame(401, $this->h->app->handle($this->h->post('/api/playback/prepare', $body, ['x-drumeo-admin' => 'nope']))->status);
        $this->assertFileExists($this->h->root . '/cache/film1/remux/0/index.m3u8', 'rejected force must not delete');

        $ok = $this->h->app->handle($this->h->post('/api/playback/prepare', $body, ['x-drumeo-admin' => 'adm']));
        $this->assertSame(202, $ok->status);
    }

    public function testForceWhileJobActiveIsBusy(): void
    {
        $body = ['videoId' => 'film1', 'recipe' => 'avc_1080', 'audioIndex' => 0, 'intent' => 'play'];
        $this->h->app->handle($this->h->post('/api/playback/prepare', $body));
        $this->h->app->worker->tick();
        $res = $this->h->app->handle($this->h->post('/api/playback/prepare', $body + ['force' => true], ['x-drumeo-admin' => 'adm']));
        $this->assertSame(409, $res->status);
        $this->assertSame('busy', $res->body['error']);
    }

    public function testDeleteCacheRequiresAdmin(): void
    {
        $req = new Request('DELETE', '/api/videos/film1/cache', [], [], [], '');
        $this->assertSame(401, $this->h->app->handle($req)->status);
        $this->assertDirectoryExists($this->h->root . '/cache/film1/remux/0');

        $req = new Request('DELETE', '/api/videos/film1/cache', [], ['x-drumeo-admin' => 'adm'], [], '');
        $res = $this->h->app->handle($req);
        $this->assertSame(200, $res->status);
        $this->assertDirectoryDoesNotExist($this->h->root . '/cache/film1');
        $this->assertSame([], $this->h->app->meta->read('film1')['variants']);
        $this->assertFileExists($this->h->root . '/bron/film1.mkv');
    }

    public function testDestructiveRoutesClosedWhenAdminTokenUnset(): void
    {
        $h = new TestHarness();
        try {
            $h->seedProbed('film1');
            $req = new Request('DELETE', '/api/videos/film1/cache', [], ['x-drumeo-admin' => ''], [], '');
            $res = $h->app->handle($req);
            $this->assertSame(401, $res->status);
            $this->assertSame('admin disabled', $res->body['error']);
        } finally {
            $h->destroy();
        }
    }

    public function testNegativeOrJunkAudioIndexIs400(): void
    {
        foreach (['/api/playback/status', '/api/playback/playlist'] as $path) {
            foreach (['-1', 'abc', ''] as $bad) {
                $res = $this->h->app->handle($this->h->get($path, ['videoId' => 'film1', 'recipe' => 'remux', 'audioIndex' => $bad]));
                $this->assertSame(400, $res->status, "$path audioIndex=$bad");
                $this->assertSame('invalid audioIndex', $res->body['error']);
            }
        }
        foreach (['/api/playback/prepare', '/api/playback/query'] as $path) {
            $res = $this->h->app->handle($this->h->post($path, ['videoId' => 'film1', 'recipe' => 'remux', 'audioIndex' => -1]));
            $this->assertSame(400, $res->status, $path);
            $this->assertSame([0], $res->body['available']);
        }
        $req = new Request('DELETE', '/api/videos/film1/cache', ['recipe' => 'remux', 'audioIndex' => '-1'], ['x-drumeo-admin' => 'adm'], [], '');
        $this->assertSame(400, $this->h->app->handle($req)->status);
        $this->assertDirectoryExists($this->h->root . '/cache/film1/remux/0');
    }

    public function testNoCorsHeadersAndOptionsIs204(): void
    {
        $res = $this->h->app->handle($this->h->get('/api/videos'));
        $this->assertSame(200, $res->status);
        $this->assertSame([], array_filter(array_keys($res->headers), fn ($k) => stripos($k, 'access-control') === 0));
        $opt = $this->h->app->handle(new Request('OPTIONS', '/api/videos', [], [], [], ''));
        $this->assertSame(204, $opt->status);
        $this->assertSame([], $opt->headers);
    }
}
