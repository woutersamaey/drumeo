<?php

declare(strict_types=1);

namespace Drumeo\Video\Probe;

use Drumeo\Video\Config;

final class Probe
{
    public function __construct(private readonly Config $config)
    {
    }

    /**
     * @return array{
     *   durationSec:float,
     *   video:array{index:int,codec:string,width:int,height:int,pixFmt:string,bits:int,hdr:bool,fps:float},
     *   audioTracks:list<array{index:int,codec:string,channels:int}>
     * }
     */
    public function inspect(string $path): array
    {
        $cmd = [
            $this->config->ffprobeBin,
            '-v', 'error',
            '-show_entries', 'format=duration:stream=index,codec_type,codec_name,width,height,pix_fmt,channels,avg_frame_rate,r_frame_rate,bits_per_raw_sample,color_transfer,color_primaries,color_space',
            '-of', 'json',
            $path,
        ];
        $proc = proc_open($cmd, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        if (!is_resource($proc)) {
            throw new \RuntimeException('ffprobe failed to start');
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        if ($code !== 0) {
            throw new \RuntimeException('ffprobe exit ' . $code . ': ' . trim($stderr));
        }
        $data = json_decode($stdout, true);
        if (!is_array($data)) {
            throw new \RuntimeException('ffprobe returned invalid JSON');
        }
        return $this->parse($data);
    }

    public function ffmpegAvailable(): bool
    {
        $bin = $this->config->ffmpegBin;
        $out = [];
        $code = 0;
        exec(escapeshellcmd($bin) . ' -version 2>&1', $out, $code);
        return $code === 0;
    }

    /** @param array<string,mixed> $data */
    public function parse(array $data): array
    {
        $duration = (float) ($data['format']['duration'] ?? 0);
        $video = [
            'index' => 0,
            'codec' => '',
            'width' => 0,
            'height' => 0,
            'pixFmt' => '',
            'bits' => 8,
            'hdr' => false,
            'fps' => 24.0,
        ];
        $audio = [];
        $audioOrdinal = 0;
        foreach ($data['streams'] ?? [] as $stream) {
            if (!is_array($stream)) {
                continue;
            }
            $type = (string) ($stream['codec_type'] ?? '');
            if ($type === 'video' && $video['codec'] === '') {
                $pix = (string) ($stream['pix_fmt'] ?? '');
                $bits = (int) ($stream['bits_per_raw_sample'] ?? 0);
                if ($bits === 0) {
                    $bits = str_contains($pix, 'p10') ? 10 : (str_contains($pix, 'p12') ? 12 : 8);
                }
                $transfer = strtolower((string) ($stream['color_transfer'] ?? ''));
                $hdr = in_array($transfer, ['smpte2084', 'arib-std-b67', 'smpte428'], true);
                $video = [
                    'index' => (int) ($stream['index'] ?? 0),
                    'codec' => (string) ($stream['codec_name'] ?? ''),
                    'width' => (int) ($stream['width'] ?? 0),
                    'height' => (int) ($stream['height'] ?? 0),
                    'pixFmt' => $pix,
                    'bits' => $bits,
                    'hdr' => $hdr,
                    'fps' => $this->fps($stream),
                ];
            } elseif ($type === 'audio') {
                $audio[] = [
                    'index' => $audioOrdinal,
                    'codec' => (string) ($stream['codec_name'] ?? ''),
                    'channels' => (int) ($stream['channels'] ?? 2),
                    'streamIndex' => (int) ($stream['index'] ?? 0),
                ];
                $audioOrdinal++;
            }
        }
        return [
            'durationSec' => $duration,
            'video' => $video,
            'audioTracks' => $audio,
        ];
    }

    /** @param array<string,mixed> $stream */
    private function fps(array $stream): float
    {
        foreach (['avg_frame_rate', 'r_frame_rate'] as $key) {
            $raw = (string) ($stream[$key] ?? '');
            if ($raw === '' || $raw === '0/0') {
                continue;
            }
            if (str_contains($raw, '/')) {
                [$a, $b] = array_map('floatval', explode('/', $raw, 2));
                if ($b > 0) {
                    return $a / $b;
                }
            } elseif (is_numeric($raw)) {
                return (float) $raw;
            }
        }
        return 24.0;
    }
}
