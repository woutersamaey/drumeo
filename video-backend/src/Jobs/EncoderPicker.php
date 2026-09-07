<?php

declare(strict_types=1);

namespace Drumeo\Video\Jobs;

use Drumeo\Video\Config;
use Drumeo\Video\Store\AtomicJson;

/**
 * Picks a working H.264 encoder at startup.
 * FFMPEG_VCODEC=auto (default) probes GPU then CPU.
 * An explicit value is used if it works, otherwise we fall back.
 */
final class EncoderPicker
{
    public const AUTO = 'auto';

    /** Preferred order when auto-detecting. */
    public const CANDIDATES = [
        'h264_nvenc',
        'h264_qsv',
        'h264_vaapi',
        'h264_videotoolbox',
        'libx264',
    ];

    private ?array $cached = null;

    /** @var callable|null fn(list<string>): array{ok:bool, output:string} */
    private $runner;

    public function __construct(
        private readonly Config $config,
        ?callable $runner = null,
    ) {
        $this->runner = $runner;
    }

    public function requested(): string
    {
        $raw = strtolower(trim($this->config->ffmpegVcodec));
        return $raw === '' ? self::AUTO : $raw;
    }

    public function codec(): string
    {
        return (string) ($this->choice()['encoder'] ?? 'libx264');
    }

    /** @return array{encoder:string, requested:string, probed:bool, fingerprint:string, tried:list<string>} */
    public function choice(bool $force = false): array
    {
        $fp = $this->fingerprint();
        $requested = $this->requested();
        if (!$force) {
            $disk = $this->readCache();
            if (is_array($disk)
                && ($disk['fingerprint'] ?? '') === $fp
                && ($disk['requested'] ?? '') === $requested
                && is_string($disk['encoder'] ?? null)
            ) {
                return $disk;
            }
        }

        $tried = [];
        $order = $requested === self::AUTO
            ? self::CANDIDATES
            : array_values(array_unique([$requested, 'libx264']));

        $picked = 'libx264';
        $probed = $requested === self::AUTO;
        foreach ($order as $codec) {
            $tried[] = $codec;
            if ($this->works($codec, $codec !== 'libx264' || $requested === self::AUTO)) {
                $picked = $codec;
                break;
            }
        }

        $result = [
            'encoder' => $picked,
            'requested' => $requested,
            'probed' => $probed,
            'fingerprint' => $fp,
            'tried' => $tried,
            'probedAt' => gmdate('c'),
        ];
        $this->writeCache($result);
        $this->cached = $result;
        return $result;
    }

    public function fingerprint(): string
    {
        $dri = is_dir('/dev/dri') ? '1' : '0';
        $nv = (file_exists('/dev/nvidia0') || file_exists('/dev/nvidiactl')) ? '1' : '0';
        $render = $this->vaapiDevice() ?? 'none';
        return php_uname('n') . '|dri=' . $dri . '|nv=' . $nv . '|va=' . $render;
    }

    private function works(string $codec, bool $smokeEncode): bool
    {
        if (!$this->encoderListed($codec)) {
            return false;
        }
        if (!$smokeEncode) {
            return true;
        }
        return $this->smoke($codec);
    }

    public function encoderListed(string $codec): bool
    {
        $bin = $this->config->ffmpegBin;
        $result = $this->run([$bin, '-hide_banner', '-encoders']);
        if (!$result['ok']) {
            return $codec === 'libx264';
        }
        return (bool) preg_match('/\b' . preg_quote($codec, '/') . '\b/', $result['output']);
    }

    public function smoke(string $codec): bool
    {
        $bin = $this->config->ffmpegBin;
        $args = [
            $bin,
            '-hide_banner',
            '-loglevel', 'error',
            '-f', 'lavfi',
            '-i', 'testsrc=size=128x72:rate=24',
            '-t', '0.4',
            '-an',
        ];
        $args = array_merge($args, $this->smokeCodecArgs($codec));
        $args[] = '-f';
        $args[] = 'null';
        $args[] = '-';
        $result = $this->run($args, 6);
        return $result['ok'];
    }

    /** @return list<string> */
    private function smokeCodecArgs(string $codec): array
    {
        return match ($codec) {
            'h264_nvenc' => ['-c:v', 'h264_nvenc', '-preset', 'p4', '-b:v', '200k'],
            'h264_qsv' => ['-c:v', 'h264_qsv', '-preset', 'veryfast', '-b:v', '200k'],
            'h264_vaapi' => array_values(array_filter([
                $this->vaapiDevice() ? '-vaapi_device' : null,
                $this->vaapiDevice(),
                '-vf', 'format=nv12,hwupload',
                '-c:v', 'h264_vaapi',
                '-b:v', '200k',
            ])),
            'h264_videotoolbox' => ['-c:v', 'h264_videotoolbox', '-b:v', '200k'],
            default => ['-c:v', 'libx264', '-preset', 'ultrafast', '-tune', 'zerolatency', '-b:v', '200k'],
        };
    }

    private function vaapiDevice(): ?string
    {
        foreach (['/dev/dri/renderD128', '/dev/dri/renderD129', '/dev/dri/card0'] as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }
        return null;
    }

    /** @return array<string,mixed>|null */
    private function readCache(): ?array
    {
        if (is_array($this->cached)) {
            return $this->cached;
        }
        $data = AtomicJson::read($this->cachePath());
        if (!is_array($data)) {
            return null;
        }
        $this->cached = $data;
        return $data;
    }

    /** @param array<string,mixed> $data */
    private function writeCache(array $data): void
    {
        try {
            AtomicJson::write($this->cachePath(), $data);
        } catch (\Throwable) {
            // cache is optional
        }
    }

    public function cachePath(): string
    {
        return rtrim($this->config->tmpDir, '/') . '/vb-encoder.json';
    }

    /**
     * @param list<string> $args
     * @return array{ok:bool, output:string}
     */
    private function run(array $args, int $timeoutSec = 8): array
    {
        if ($this->runner !== null) {
            return ($this->runner)($args);
        }
        $spec = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = @proc_open($args, $spec, $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($proc)) {
            return ['ok' => false, 'output' => 'failed to start'];
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $out = '';
        $deadline = microtime(true) + $timeoutSec;
        while (true) {
            $status = proc_get_status($proc);
            $out .= (string) stream_get_contents($pipes[1]);
            $out .= (string) stream_get_contents($pipes[2]);
            if (!$status['running']) {
                break;
            }
            if (microtime(true) > $deadline) {
                proc_terminate($proc, SIGKILL);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($proc);
                return ['ok' => false, 'output' => 'timeout'];
            }
            usleep(50000);
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        return ['ok' => $code === 0, 'output' => $out];
    }
}
