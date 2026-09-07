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

    public function running(int $pid): bool;

    /** @return array{running:bool,exitCode:?int} */
    public function poll(int $pid): array;

    public function stop(int $pid): void;
}
