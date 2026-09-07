<?php

declare(strict_types=1);

namespace Drumeo\Video\Tests;

use Drumeo\Video\Clock;

final class FakeClock implements Clock
{
    public int $unix;

    public function __construct(?int $unix = null)
    {
        $this->unix = $unix ?? 1_700_000_000;
    }

    public function now(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', $this->unix);
    }

    public function unix(): int
    {
        return $this->unix;
    }

    public function advance(int $seconds): void
    {
        $this->unix += $seconds;
    }
}
