<?php

declare(strict_types=1);

namespace Drumeo\Video;

/**
 * Runs a short-lived command (ffprobe, ffmpeg -version, encoder smoke tests)
 * with a wall-clock timeout. No shell is involved: $args is an argv list.
 */
final class Process
{
    /** Signal numbers; the SIG* constants need ext-pcntl, which the image lacks. */
    public const SIGTERM = 15;
    public const SIGKILL = 9;

    /**
     * @param list<string> $args
     * @return array{ok:bool,code:int,stdout:string,stderr:string,timedOut:bool}
     */
    public static function run(array $args, float $timeoutSec): array
    {
        $spec = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = @proc_open($args, $spec, $pipes);
        if (!is_resource($proc)) {
            return ['ok' => false, 'code' => -1, 'stdout' => '', 'stderr' => 'failed to start', 'timedOut' => false];
        }
        $deadline = microtime(true) + $timeoutSec;
        $out = ['', '', ''];
        $open = [1 => $pipes[1], 2 => $pipes[2]];
        foreach ($open as $pipe) {
            stream_set_blocking($pipe, false);
        }

        while ($open !== []) {
            $left = $deadline - microtime(true);
            if ($left <= 0) {
                return self::kill($proc, $open, $out);
            }
            $read = array_values($open);
            $write = null;
            $except = null;
            if (@stream_select($read, $write, $except, 0, (int) min(200_000, $left * 1_000_000)) === false) {
                break;
            }
            foreach ($open as $fd => $pipe) {
                $chunk = fread($pipe, 65536);
                if (is_string($chunk) && $chunk !== '') {
                    $out[$fd] .= $chunk;
                }
                if (feof($pipe)) {
                    fclose($pipe);
                    unset($open[$fd]);
                }
            }
        }

        while (true) {
            $status = proc_get_status($proc);
            if (!$status['running']) {
                $code = (int) $status['exitcode'];
                break;
            }
            if (microtime(true) > $deadline) {
                return self::kill($proc, $open, $out);
            }
            usleep(10_000);
        }
        foreach ($open as $pipe) {
            fclose($pipe);
        }
        proc_close($proc);
        return ['ok' => $code === 0, 'code' => $code, 'stdout' => $out[1], 'stderr' => $out[2], 'timedOut' => false];
    }

    /**
     * @param resource $proc
     * @param array<int,resource> $open
     * @param array<int,string> $out
     * @return array{ok:bool,code:int,stdout:string,stderr:string,timedOut:bool}
     */
    private static function kill($proc, array $open, array $out): array
    {
        proc_terminate($proc, self::SIGKILL);
        foreach ($open as $pipe) {
            fclose($pipe);
        }
        proc_close($proc);
        return ['ok' => false, 'code' => -1, 'stdout' => $out[1], 'stderr' => $out[2] . "\ntimeout", 'timedOut' => true];
    }
}
