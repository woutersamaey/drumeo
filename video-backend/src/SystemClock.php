<?php

declare(strict_types=1);

namespace Drumeo\Video;

final class SystemClock implements Clock
{
    public function now(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }

    public function unix(): int
    {
        return time();
    }
}
