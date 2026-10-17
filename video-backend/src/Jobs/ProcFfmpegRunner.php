<?php

declare(strict_types=1);

namespace Drumeo\Video\Jobs;

use Drumeo\Video\Process;

final class ProcFfmpegRunner implements FfmpegRunner
{
    /** @var array<int,resource> */
    private array $procs = [];

    /** @var array<int,int> exit codes of processes that ended, until the pid is reused */
    private array $exited = [];

    public function start(array $args, string $logFile): int
    {
        $dir = dirname($logFile);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create log directory: ' . $dir);
        }
        $spec = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $logFile, 'w'],
            2 => ['file', $logFile, 'a'],
        ];
        $proc = proc_open($args, $spec, $pipes);
        if (!is_resource($proc)) {
            throw new \RuntimeException('Failed to start ffmpeg');
        }
        $status = proc_get_status($proc);
        $pid = (int) ($status['pid'] ?? 0);
        if ($pid <= 0) {
            proc_close($proc);
            throw new \RuntimeException('ffmpeg started without pid');
        }
        $this->procs[$pid] = $proc;
        unset($this->exited[$pid]);
        return $pid;
    }

    public function poll(int $pid): array
    {
        if (isset($this->exited[$pid])) {
            return ['running' => false, 'exitCode' => $this->exited[$pid]];
        }
        if (!isset($this->procs[$pid])) {
            return ['running' => false, 'exitCode' => null];
        }
        $status = proc_get_status($this->procs[$pid]);
        if ($status['running']) {
            return ['running' => true, 'exitCode' => null];
        }
        // proc_get_status reports the real exit code only once; keep it.
        $exit = is_int($status['exitcode']) && $status['exitcode'] >= 0 ? $status['exitcode'] : 1;
        if ($status['signaled'] ?? false) {
            $exit = 128 + (int) ($status['termsig'] ?? 0);
        }
        proc_close($this->procs[$pid]);
        unset($this->procs[$pid]);
        $this->exited[$pid] = $exit;
        return ['running' => false, 'exitCode' => $exit];
    }

    public function stop(int $pid): void
    {
        if (!isset($this->procs[$pid])) {
            return;
        }
        $proc = $this->procs[$pid];
        proc_terminate($proc, Process::SIGTERM);
        for ($i = 0; $i < 30; $i++) {
            usleep(100_000);
            if (!proc_get_status($proc)['running']) {
                break;
            }
        }
        if (proc_get_status($proc)['running']) {
            proc_terminate($proc, Process::SIGKILL);
            usleep(100_000);
        }
        $this->poll($pid);
    }
}
