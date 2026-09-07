<?php

declare(strict_types=1);

namespace Drumeo\Video\Jobs;

final class ProcFfmpegRunner implements FfmpegRunner
{
    /** @var array<int,array{resource:resource,pipes:array}> */
    private array $procs = [];

    public function start(array $args, string $logFile): int
    {
        $dir = dirname($logFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $spec = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $logFile, 'a'],
            2 => ['file', $logFile, 'a'],
        ];
        $proc = proc_open($args, $spec, $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($proc)) {
            throw new \RuntimeException('Failed to start ffmpeg');
        }
        $status = proc_get_status($proc);
        $pid = (int) ($status['pid'] ?? 0);
        if ($pid <= 0) {
            proc_close($proc);
            throw new \RuntimeException('ffmpeg started without pid');
        }
        $this->procs[$pid] = ['resource' => $proc, 'pipes' => $pipes];
        return $pid;
    }

    public function running(int $pid): bool
    {
        return $this->poll($pid)['running'];
    }

    public function poll(int $pid): array
    {
        if (isset($this->procs[$pid])) {
            $status = proc_get_status($this->procs[$pid]['resource']);
            if ($status['running']) {
                return ['running' => true, 'exitCode' => null];
            }
            $exit = $status['exitcode'];
            proc_close($this->procs[$pid]['resource']);
            unset($this->procs[$pid]);
            return ['running' => false, 'exitCode' => is_int($exit) ? $exit : 1];
        }

        if (function_exists('posix_kill') && @posix_kill($pid, 0)) {
            return ['running' => true, 'exitCode' => null];
        }
        return ['running' => false, 'exitCode' => null];
    }

    public function stop(int $pid): void
    {
        if (isset($this->procs[$pid])) {
            proc_terminate($this->procs[$pid]['resource'], SIGTERM);
            usleep(300000);
            $status = proc_get_status($this->procs[$pid]['resource']);
            if ($status['running']) {
                proc_terminate($this->procs[$pid]['resource'], SIGKILL);
            }
            proc_close($this->procs[$pid]['resource']);
            unset($this->procs[$pid]);
            return;
        }
        if (function_exists('posix_kill')) {
            @posix_kill($pid, SIGTERM);
            usleep(200000);
            if (@posix_kill($pid, 0)) {
                @posix_kill($pid, SIGKILL);
            }
        }
    }
}
