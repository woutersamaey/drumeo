<?php

declare(strict_types=1);

namespace Drumeo\Video\Tests;

use Drumeo\Video\Http\Request;
use Drumeo\Video\Store\AtomicJson;
use PHPUnit\Framework\TestCase;

final class AdminOverviewTest extends TestCase
{
    public function testOverviewCountsSourcesScaledAndStale(): void
    {
        $h = new TestHarness(adminToken: 'adm');
        try {
            $this->seed($h);
            $view = $h->app->overview->collect();
            $this->assertSame(3, $view['source']['count']);
            $this->assertSame(1000 + 2500 + 4, $view['source']['bytes']);
            $this->assertSame(2, $view['cache']['scaledCount']);
            $this->assertGreaterThan(0, $view['cache']['scaledBytes']);
            $this->assertSame(1, $view['cache']['byRecipe']['remux']['count']);
            $this->assertSame($view['cache']['byRecipe']['avc_1080']['bytes'], $view['cache']['scaledBytes']);
            $reasons = [];
            foreach ($view['cleanup']['items'] as $item) {
                $reasons[$item['id']] = $item['reason'];
            }
            $this->assertSame('age', $reasons['vid-old']);
            $this->assertSame('failed', $reasons['vid-bad']);
            $this->assertArrayNotHasKey('vid-new', $reasons);
            $this->assertSame(2, $view['cleanup']['count']);
            $this->assertIsBool($view['ffmpeg']);
            $this->assertNotSame('', (string) $view['encoder']['requested']);
        } finally {
            $h->destroy();
        }
    }

    public function testAdminRouteRequiresTokenAndConfirm(): void
    {
        $h = new TestHarness(adminToken: 'adm');
        try {
            $this->seed($h);
            $denied = $h->app->handle(new Request('GET', '/api/admin/overview', [], [], [], ''));
            $this->assertSame(401, $denied->status);

            $wrong = $h->app->handle(new Request('GET', '/api/admin/overview', [], ['x-drumeo-admin' => 'nope'], [], ''));
            $this->assertSame(401, $wrong->status);

            $ok = $h->app->handle(new Request('GET', '/api/admin/overview', [], ['x-drumeo-admin' => 'adm'], [], ''));
            $this->assertSame(200, $ok->status);
            $this->assertSame(3, $ok->body['source']['count']);

            $unconfirmed = $h->app->handle(new Request('POST', '/api/admin/cleanup', [], ['x-drumeo-admin' => 'adm'], [], ''));
            $this->assertSame(400, $unconfirmed->status);
            $this->assertDirectoryExists($h->root . '/cache/vid-old');
        } finally {
            $h->destroy();
        }
    }

    public function testCleanupDeletesStaleCacheAndKeepsSources(): void
    {
        $h = new TestHarness(adminToken: 'adm');
        try {
            $this->seed($h);
            $res = $h->app->handle(new Request(
                'POST',
                '/api/admin/cleanup',
                [],
                ['x-drumeo-admin' => 'adm'],
                ['confirm' => true],
                '',
            ));
            $this->assertSame(200, $res->status);
            $this->assertFalse($res->body['dryRun']);
            $this->assertSame(2, $res->body['count']);
            $this->assertGreaterThan(0, $res->body['bytes']);
            $this->assertDirectoryDoesNotExist($h->root . '/cache/vid-old');
            $this->assertDirectoryDoesNotExist($h->root . '/cache/vid-bad');
            $this->assertDirectoryExists($h->root . '/cache/vid-new');
            $this->assertFileExists($h->root . '/bron/clip [vid-old].mkv');
            $this->assertFileExists($h->root . '/bron/clip [vid-new].mkv');

            $again = $h->app->overview->collect();
            $this->assertSame(0, $again['cleanup']['count']);
            $this->assertSame(3, $again['source']['count']);
        } finally {
            $h->destroy();
        }
    }

    public function testAdminDisabledWithoutToken(): void
    {
        $h = new TestHarness();
        try {
            $res = $h->app->handle(new Request('GET', '/api/admin/overview', [], ['x-drumeo-admin' => 'adm'], [], ''));
            $this->assertSame(401, $res->status);
            $this->assertSame('admin disabled', $res->body['error']);
        } finally {
            $h->destroy();
        }
    }

    public function testApiTokenStillGatesAdmin(): void
    {
        $h = new TestHarness(adminToken: 'adm');
        try {
            $c = $h->config;
            $h->config = new \Drumeo\Video\Config(
                sourceDir: $c->sourceDir,
                cacheDir: $c->cacheDir,
                metadataDir: $c->metadataDir,
                apiToken: 'secret',
                tmpDir: $c->tmpDir,
                playerHlsJsPath: $c->playerHlsJsPath,
                ffmpegVcodec: 'libx264',
                adminToken: 'adm',
            );
            $h->app = \Drumeo\Video\App::build($h->config, $h->runner, $h->clock);
            $noBearer = $h->app->handle(new Request('GET', '/api/admin/overview', [], ['x-drumeo-admin' => 'adm'], [], ''));
            $this->assertSame(401, $noBearer->status);
            $both = $h->app->handle(new Request(
                'GET',
                '/api/admin/overview',
                [],
                ['authorization' => 'Bearer secret', 'x-drumeo-admin' => 'adm'],
                [],
                '',
            ));
            $this->assertSame(200, $both->status);
        } finally {
            $h->destroy();
        }
    }

    private function seed(TestHarness $h): void
    {
        $h->writeNamedSource('vid-old', str_repeat('a', 1000));
        $h->writeNamedSource('vid-new', str_repeat('b', 2500));
        $h->writeNamedSource('vid-bad', 'zzzz');
        $h->plantHls('vid-old', 'avc_1080', 1, 3, true);
        $h->plantHls('vid-new', 'remux', 0, 2, true);
        $h->plantHls('vid-bad', 'avc_1080', 0, 1, false);
        $this->meta($h, 'vid-old', 'avc_1080', 1, 'ready', '2020-01-01T00:00:00Z');
        $this->meta($h, 'vid-new', 'remux', 0, 'ready', $h->clock->now());
        $this->meta($h, 'vid-bad', 'avc_1080', 0, 'failed', $h->clock->now());
    }

    private function meta(TestHarness $h, string $id, string $recipe, int $audio, string $state, string $played): void
    {
        AtomicJson::write($h->config->metadataDir . '/' . $id . '.json', [
            'id' => $id,
            'lastPlayedAt' => $played,
            'source' => ['mtimeMs' => 1, 'size' => 1, 'probeError' => null, 'failCount' => 0],
            'video' => ['codec' => 'hevc', 'width' => 3840, 'height' => 2160],
            'variants' => [[
                'recipe' => $recipe,
                'audioIndex' => $audio,
                'state' => $state,
            ]],
        ]);
    }
}
