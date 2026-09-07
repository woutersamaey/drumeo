<?php

declare(strict_types=1);

namespace Drumeo\Video;

interface Clock
{
    public function now(): string;

    public function unix(): int;
}
