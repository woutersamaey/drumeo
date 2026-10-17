<?php

declare(strict_types=1);

namespace Drumeo\Video\Jobs;

interface FfmpegRunner
{
    /**
     * Start FFmpeg. Returns pid.
     *
     * @param list<string> $args
     */
    public function start(array $args, string $logFile): int;

    /**
     * Status of a process started by this runner. A pid this runner did not start
     * (for example from before a worker restart) reports running=false, exitCode=null.
     *
     * @return array{running:bool,exitCode:?int}
     */
    public function poll(int $pid): array;

    public function stop(int $pid): void;
}
