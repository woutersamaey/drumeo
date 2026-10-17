<?php

declare(strict_types=1);

namespace Drumeo\Video\Tests;

use Drumeo\Video\Jobs\ProcFfmpegRunner;
use PHPUnit\Framework\TestCase;

/**
 * Real ffmpeg/ffprobe + ProcFfmpegRunner. A truncated MKV makes FFmpeg exit 0
 * with ENDLIST; only the duration check catches it.
 */
final class FfmpegIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        if (!MkvFactory::ffmpegAvailable()) {
            $this->markTestSkipped('ffmpeg not installed');
        }
    }

    public function testFullSourceIsReadyAndTruncatedSourceFails(): void
    {
        $h = new TestHarness(new ProcFfmpegRunner());
        try {
            $full = $h->root . '/bron/full.mkv';
            MkvFactory::make($full, 'libx264', 'aac', 'yuv420p', '320x180', 12.0);
            $bytes = (string) file_get_contents($full);
            file_put_contents($h->root . '/bron/half.mkv', substr($bytes, 0, intdiv(strlen($bytes), 2)));
            $h->app->sources->refresh();

            foreach (['full', 'half'] as $id) {
                $res = $h->app->handle($h->post('/api/playback/prepare', ['videoId' => $id, 'recipe' => 'remux', 'audioIndex' => 0, 'intent' => 'play']));
                $this->assertSame(202, $res->status, $id . ': ' . json_encode($res->body));
            }
            $deadline = microtime(true) + 30;
            while ($h->app->locks->all() !== [] && microtime(true) < $deadline) {
                $h->app->worker->tick();
                usleep(100_000);
            }
            $this->assertSame([], $h->app->locks->all(), 'jobs finished');

            $full = $h->app->meta->findVariant($h->app->meta->read('full'), 'remux', 0);
            $this->assertSame('ready', $full['state'], (string) ($full['error'] ?? ''));
            $half = $h->app->meta->findVariant($h->app->meta->read('half'), 'remux', 0);
            $this->assertSame('failed', $half['state']);
            $this->assertStringStartsWith('incomplete output', (string) $half['error']);
            $this->assertStringContainsString('#EXT-X-ENDLIST', (string) file_get_contents($h->root . '/cache/half/remux/0/index.m3u8'));
        } finally {
            $h->destroy();
        }
    }

    public function testStopTerminatesWithoutPcntlConstants(): void
    {
        $runner = new ProcFfmpegRunner();
        $log = sys_get_temp_dir() . '/vbstop-' . bin2hex(random_bytes(4)) . '.log';
        $pid = $runner->start(['ffmpeg', '-hide_banner', '-loglevel', 'error', '-re', '-f', 'lavfi', '-i', 'testsrc=size=64x64:rate=10', '-t', '60', '-f', 'null', '-'], $log);
        $this->assertTrue($runner->poll($pid)['running']);
        $runner->stop($pid);
        $poll = $runner->poll($pid);
        $this->assertFalse($poll['running']);
        $this->assertNotSame(0, $poll['exitCode']);
        @unlink($log);
    }
}
