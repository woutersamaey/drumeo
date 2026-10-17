<?php

declare(strict_types=1);

namespace Drumeo\Video\Tests;

use PHPUnit\Framework\TestCase;

/** A job is ready only with FFmpeg exit 0 and a playlist covering the source. */
final class WorkerVerificationTest extends TestCase
{
    private TestHarness $h;

    protected function setUp(): void
    {
        $this->h = new TestHarness();
    }

    protected function tearDown(): void
    {
        $this->h->destroy();
    }

    public function testExitZeroWithFullDurationIsReady(): void
    {
        $pid = $this->startRemux(24.0);
        $this->runner()->finishWith($pid, 0, 4, true);
        $this->h->app->worker->tick();

        $v = $this->variant();
        $this->assertSame('ready', $v['state']);
        $this->assertSame(0, $v['failCount']);
        $this->assertNull($this->h->app->locks->read('film1', 'remux', 0));
        $index = (string) file_get_contents($this->h->root . '/cache/film1/remux/0/index.m3u8');
        $this->assertStringContainsString('#EXT-X-PLAYLIST-TYPE:VOD', $index);
        $this->assertMatchesRegularExpression('/seg_000\.ts\?v=\d+/', $index);

        $status = $this->statusResponse();
        $this->assertSame('ready', $status->body['state']);
        $this->assertIsString($status->body['playlistUrl']);
        $this->assertSame(200, $this->h->app->handle($this->h->get('/api/playback/playlist', $this->q()))->status);
    }

    public function testNonzeroExitWithEndlistIsFailed(): void
    {
        $pid = $this->startRemux(24.0);
        $this->runner()->finishWith($pid, 1, 4, true); // FFmpeg writes ENDLIST even when it fails
        $this->h->app->worker->tick();

        $v = $this->variant();
        $this->assertSame('failed', $v['state']);
        $this->assertSame('ffmpeg exit 1', $v['error']);
        $this->assertSame(1, $v['failCount']);
        $this->assertNull($this->h->app->locks->read('film1', 'remux', 0));
        $this->assertNotServed();
    }

    public function testTruncatedOutputWithExitZeroIsFailed(): void
    {
        $pid = $this->startRemux(300.0);
        $this->runner()->finishWith($pid, 0, 4, true); // 24s of a 300s source
        $this->h->app->worker->tick();

        $v = $this->variant();
        $this->assertSame('failed', $v['state']);
        $this->assertStringStartsWith('incomplete output: 24.0s of 300.0s', (string) $v['error']);
        $this->assertNotServed();
    }

    public function testMissingEndlistWithExitZeroIsFailed(): void
    {
        $pid = $this->startRemux(24.0);
        $this->runner()->finishWith($pid, 0, 4, false);
        $this->h->app->worker->tick();
        $this->assertSame('playlist has no ENDLIST', $this->variant()['error']);
    }

    public function testEndlistIsNotReadyUntilTheWorkerVerifiedTheExitCode(): void
    {
        $pid = $this->startRemux(24.0);
        $this->runner()->finishWith($pid, 1, 4, true);
        // no tick: the api must not promote it on its own
        $status = $this->statusResponse();
        $this->assertSame('running', $status->body['state']);
        $this->assertNull($status->body['playlistUrl']);
        $this->assertSame(409, $this->h->app->handle($this->h->get('/api/playback/playlist', $this->q()))->status);
    }

    public function testExistingFailedVariantWithEndlistIsNotServed(): void
    {
        $this->h->seedProbed('film1', 24.0, [TestHarness::variant('remux', 0, 'failed', ['failCount' => 1, 'error' => 'timeout'])]);
        $this->h->plantHls('film1', 'remux', 0, 4, true);
        $status = $this->statusResponse();
        $this->assertSame('failed', $status->body['state']);
        $this->assertNotServed();
    }

    public function testExistingTruncatedReadyVariantIsDemotedToFailed(): void
    {
        $this->h->seedProbed('film1', 300.0, [TestHarness::variant('remux', 0, 'ready')]);
        $this->h->plantHls('film1', 'remux', 0, 4, true);
        $res = $this->h->app->handle($this->h->get('/api/videos/film1'));
        $this->assertSame('failed', $res->body['variants'][0]['state']);
        $this->assertStringStartsWith('incomplete output', $res->body['variants'][0]['error']);
        $this->assertNotServed();
    }

    public function testExistingCompleteReadyVariantStaysReady(): void
    {
        $this->h->seedProbed('film1', 23.8, [TestHarness::variant('remux', 0, 'ready')]);
        $this->h->plantHls('film1', 'remux', 0, 4, true);
        $status = $this->statusResponse();
        $this->assertSame('ready', $status->body['state']);
        $this->assertIsString($status->body['playlistUrl']);
    }

    public function testTimeoutStopsFfmpegAndFails(): void
    {
        $pid = $this->startRemux(24.0);
        $this->h->clock->advance($this->h->config->jobTimeoutSeconds + 1);
        $this->h->app->worker->tick();
        $this->assertSame([$pid], $this->runner()->stopped);
        $this->assertStringStartsWith('timeout', (string) $this->variant()['error']);
        $this->assertSame('failed', $this->variant()['state']);
    }

    public function testHardwareEncoderFailureRetriesWithLibx264(): void
    {
        $this->h->seedProbed('film1', 24.0);
        $this->h->app->handle($this->h->post('/api/playback/prepare', ['videoId' => 'film1', 'recipe' => 'avc_1080', 'audioIndex' => 0, 'intent' => 'play']));
        $this->h->app->locks->update('film1', 'avc_1080', 0, function (?array $l) {
            $l['encoder'] = 'h264_nvenc'; // as if EncoderPicker had chosen the GPU
            return $l;
        });
        $this->h->app->worker->tick();
        $this->assertContains('h264_nvenc', $this->runner()->commands[0]);

        $this->runner()->finishWith($this->runner()->lastPid(), 1, 0, false);
        $this->h->app->worker->tick(); // reap → requeue with libx264 → start again

        $this->assertSame(2, $this->runner()->starts);
        $this->assertContains('libx264', $this->runner()->commands[1]);
        $this->assertNotContains('h264_nvenc', $this->runner()->commands[1]);
        $v = $this->h->app->meta->findVariant($this->h->app->meta->read('film1'), 'avc_1080', 0);
        $this->assertSame(0, (int) ($v['failCount'] ?? 0), 'a hardware failure retried on CPU is not a fail');
        $lock = $this->h->app->locks->read('film1', 'avc_1080', 0);
        $this->assertSame('libx264', $lock['encoder']);
        $this->assertSame('h264_nvenc', $lock['fallback']['from']);

        // A libx264 failure after the fallback counts and is not retried again.
        $this->runner()->finishWith($this->runner()->lastPid(), 1, 0, false);
        $this->h->app->worker->tick();
        $this->assertSame(2, $this->runner()->starts);
        $v = $this->h->app->meta->findVariant($this->h->app->meta->read('film1'), 'avc_1080', 0);
        $this->assertSame('failed', $v['state']);
        $this->assertSame(1, $v['failCount']);
    }

    public function testPlayUpgradeAfterStartKeepsPidAndDoesNotRestart(): void
    {
        $this->h->seedProbed('film1', 24.0);
        $body = ['videoId' => 'film1', 'recipe' => 'remux', 'audioIndex' => 0, 'intent' => 'prefetch'];
        $this->h->app->handle($this->h->post('/api/playback/prepare', $body));
        $this->h->app->worker->tick();
        $pid = $this->runner()->lastPid();

        $body['intent'] = 'play';
        $this->h->app->handle($this->h->post('/api/playback/prepare', $body));
        $lock = $this->h->app->locks->read('film1', 'remux', 0);
        $this->assertSame($pid, $lock['pid']);
        $this->assertSame('play', $lock['intent']);
        $this->h->app->worker->tick();
        $this->assertSame(1, $this->runner()->starts);
    }

    private function startRemux(float $durationSec): int
    {
        $this->h->seedProbed('film1', $durationSec);
        $res = $this->h->app->handle($this->h->post('/api/playback/prepare', ['videoId' => 'film1', 'recipe' => 'remux', 'audioIndex' => 0, 'intent' => 'play']));
        $this->assertSame(202, $res->status);
        $this->h->app->worker->tick();
        $this->assertSame(1, $this->runner()->starts);
        return $this->runner()->lastPid();
    }

    private function assertNotServed(): void
    {
        $status = $this->statusResponse();
        $this->assertNotSame('ready', $status->body['state']);
        $this->assertNull($status->body['playlistUrl']);
        $this->assertSame(409, $this->h->app->handle($this->h->get('/api/playback/playlist', $this->q()))->status);
    }

    private function statusResponse(): \Drumeo\Video\Http\Response
    {
        return $this->h->app->handle($this->h->get('/api/playback/status', $this->q() + ['intent' => 'play']));
    }

    /** @return array<string,string> */
    private function q(): array
    {
        return ['videoId' => 'film1', 'recipe' => 'remux', 'audioIndex' => '0'];
    }

    /** @return array<string,mixed> */
    private function variant(): array
    {
        return $this->h->app->meta->findVariant($this->h->app->meta->read('film1'), 'remux', 0) ?? [];
    }

    private function runner(): FakeFfmpegRunner
    {
        $runner = $this->h->runner;
        $this->assertInstanceOf(FakeFfmpegRunner::class, $runner);
        return $runner;
    }
}
