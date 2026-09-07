<?php

declare(strict_types=1);

namespace Drumeo\Video\Tests;

use Drumeo\Video\Config;
use Drumeo\Video\Jobs\EncoderPicker;
use PHPUnit\Framework\TestCase;

final class EncoderPickerTest extends TestCase
{
    public function testAutoPicksFirstWorkingCandidate(): void
    {
        $tmp = sys_get_temp_dir() . '/enc-' . bin2hex(random_bytes(4));
        mkdir($tmp, 0777, true);
        $config = new Config(
            sourceDir: $tmp,
            cacheDir: $tmp,
            metadataDir: $tmp,
            tmpDir: $tmp,
            ffmpegVcodec: 'auto',
            ffmpegBin: 'ffmpeg',
        );
        $calls = [];
        $picker = new EncoderPicker($config, function (array $args) use (&$calls) {
            $calls[] = $args;
            $flat = implode(' ', $args);
            if (str_contains($flat, '-encoders')) {
                return ['ok' => true, 'output' => " V..... h264_nvenc\n V..... libx264\n"];
            }
            if (str_contains($flat, 'h264_nvenc') && str_contains($flat, 'testsrc')) {
                return ['ok' => true, 'output' => ''];
            }
            return ['ok' => false, 'output' => 'no'];
        });
        $choice = $picker->choice();
        $this->assertSame('h264_nvenc', $choice['encoder']);
        $this->assertSame('auto', $choice['requested']);
        $this->assertContains('h264_nvenc', $choice['tried']);
        $this->assertSame('h264_nvenc', $picker->codec());
        $n = count($calls);
        $picker->choice();
        $this->assertCount($n, $calls, 'second choice should use cache');
        $this->rm($tmp);
    }

    public function testExplicitCodecFallsBackWhenBroken(): void
    {
        $tmp = sys_get_temp_dir() . '/enc-' . bin2hex(random_bytes(4));
        mkdir($tmp, 0777, true);
        $config = new Config(
            sourceDir: $tmp,
            cacheDir: $tmp,
            metadataDir: $tmp,
            tmpDir: $tmp,
            ffmpegVcodec: 'h264_nvenc',
            ffmpegBin: 'ffmpeg',
        );
        $picker = new EncoderPicker($config, function (array $args) {
            $flat = implode(' ', $args);
            if (str_contains($flat, '-encoders')) {
                return ['ok' => true, 'output' => " V..... h264_nvenc\n V..... libx264\n"];
            }
            if (str_contains($flat, 'h264_nvenc')) {
                return ['ok' => false, 'output' => 'no gpu'];
            }
            if (str_contains($flat, 'libx264')) {
                return ['ok' => true, 'output' => ''];
            }
            return ['ok' => false, 'output' => ''];
        });
        $choice = $picker->choice();
        $this->assertSame('libx264', $choice['encoder']);
        $this->assertSame(['h264_nvenc', 'libx264'], $choice['tried']);
        $this->rm($tmp);
    }

    public function testFingerprintChangeReprobes(): void
    {
        $tmp = sys_get_temp_dir() . '/enc-' . bin2hex(random_bytes(4));
        mkdir($tmp, 0777, true);
        $config = new Config(
            sourceDir: $tmp,
            cacheDir: $tmp,
            metadataDir: $tmp,
            tmpDir: $tmp,
            ffmpegVcodec: 'auto',
        );
        $picker = new EncoderPicker($config, function (array $args) {
            $flat = implode(' ', $args);
            if (str_contains($flat, '-encoders')) {
                return ['ok' => true, 'output' => " V..... libx264\n"];
            }
            return ['ok' => true, 'output' => ''];
        });
        $first = $picker->choice();
        $raw = json_decode((string) file_get_contents($picker->cachePath()), true);
        $raw['fingerprint'] = 'other-machine';
        file_put_contents($picker->cachePath(), json_encode($raw));
        $picker2 = new EncoderPicker($config, function (array $args) {
            $flat = implode(' ', $args);
            if (str_contains($flat, '-encoders')) {
                return ['ok' => true, 'output' => " V..... h264_qsv\n V..... libx264\n"];
            }
            if (str_contains($flat, 'h264_qsv')) {
                return ['ok' => true, 'output' => ''];
            }
            return ['ok' => false, 'output' => ''];
        });
        $second = $picker2->choice();
        $this->assertSame('libx264', $first['encoder']);
        $this->assertSame('h264_qsv', $second['encoder']);
        $this->rm($tmp);
    }

    private function rm(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $n) {
            if ($n === '.' || $n === '..') {
                continue;
            }
            $p = $path . '/' . $n;
            is_dir($p) ? $this->rm($p) : @unlink($p);
        }
        @rmdir($path);
    }
}
