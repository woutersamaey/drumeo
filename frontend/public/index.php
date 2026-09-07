<?php

declare(strict_types=1);

use Drumeo\App\Http;

require dirname(__DIR__) . '/vendor/autoload.php';

Http::boot()->run();
