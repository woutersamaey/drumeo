<?php

declare(strict_types=1);

namespace Drumeo\Video\Jobs;

use Drumeo\Video\Config;
use Drumeo\Video\Process;
use Drumeo\Video\Recipe\Matcher;
use Drumeo\Video\Recipe\Recipe;
use Drumeo\Video\Store\AtomicJson;

/**
 * Picks a working H.264 encoder.
 * FFMPEG_VCODEC=auto (default) probes GPU then CPU.
 * An explicit value is used if it works, otherwise we fall back to libx264.
 * Hardware candidates are smoke-tested with the real avc_1080 argv (FfmpegCommand)
 * on a one-second H.264 sample, so "works here" means "works for jobs".
 */
final class EncoderPicker
{
    public const AUTO = 'auto';

    /** Bump when the smoke test or the encoder argv changes, to drop old cache files. */
    private const CACHE_VERSION = 2;

    /** Preferred order when auto-detecting. */
    public const CANDIDATES = [
        'h264_nvenc',
        'h264_qsv',
        'h264_vaapi',
        'h264_videotoolbox',
        'libx264',
    ];

    private ?array $cached = null;

    private static ?bool $ffmpegAvailable = null;

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
                && ($disk['version'] ?? 0) === self::CACHE_VERSION
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
        foreach ($order as $codec) {
            $tried[] = $codec;
            if ($this->works($codec, $codec !== 'libx264' || $requested === self::AUTO)) {
                $picked = $codec;
                break;
            }
        }

        $result = [
            'version' => self::CACHE_VERSION,
            'encoder' => $picked,
            'requested' => $requested,
            'probed' => $requested === self::AUTO,
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

    public function ffmpegAvailable(): bool
    {
        if (self::$ffmpegAvailable === null) {
            self::$ffmpegAvailable = $this->run([$this->config->ffmpegBin, '-version'], 5)['ok'];
        }
        return self::$ffmpegAvailable;
    }

    public function vaapiDevice(): ?string
    {
        foreach (['/dev/dri/renderD128', '/dev/dri/renderD129', '/dev/dri/card0'] as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }
        return null;
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
        $result = $this->run([$this->config->ffmpegBin, '-hide_banner', '-encoders']);
        if (!$result['ok']) {
            return $codec === 'libx264';
        }
        return (bool) preg_match('/\b' . preg_quote($codec, '/') . '\b/', $result['output']);
    }

    /** Encode a one-second sample to HLS with the exact argv a job would use. */
    public function smoke(string $codec): bool
    {
        $base = rtrim($this->config->tmpDir, '/');
        $sample = $base . '/vb-smoke-sample.mkv';
        if (!is_file($sample) && !$this->makeSample($sample)) {
            return false;
        }
        $outDir = $base . '/vb-smoke-' . bin2hex(random_bytes(4));
        if (!@mkdir($outDir, 0775, true) && !is_dir($outDir)) {
            return false;
        }
        try {
            $meta = ['video' => ['codec' => 'h264', 'width' => 640, 'height' => 360, 'fps' => 24.0, 'pixFmt' => 'yuv420p']];
            $command = new FfmpegCommand($this->config, new Matcher(), $this);
            $args = $command->build($sample, $outDir, $meta, Recipe::AVC_1080, 0, $codec);
            return $this->run($args, 20)['ok'];
        } finally {
            foreach (glob($outDir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($outDir);
        }
    }

    private function makeSample(string $path): bool
    {
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.mkv';
        $ok = $this->run([
            $this->config->ffmpegBin, '-hide_banner', '-loglevel', 'error', '-y',
            '-f', 'lavfi', '-i', 'testsrc=size=640x360:rate=24',
            '-f', 'lavfi', '-i', 'sine=frequency=440:sample_rate=48000',
            '-t', '1',
            '-c:v', 'libx264', '-preset', 'ultrafast', '-pix_fmt', 'yuv420p',
            '-c:a', 'aac',
            $tmp,
        ], 20)['ok'];
        if ($this->runner !== null) {
            return $ok; // fake runner in tests: no file is produced
        }
        if (!$ok || !is_file($tmp)) {
            @unlink($tmp);
            return false;
        }
        return rename($tmp, $path);
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
        $result = Process::run($args, $timeoutSec);
        return ['ok' => $result['ok'], 'output' => $result['stdout'] . $result['stderr']];
    }
}
