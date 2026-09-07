<?php

declare(strict_types=1);

use Drumeo\Video\App;

require dirname(__DIR__) . '/vendor/autoload.php';

$app = App::build();
$choice = $app->encoders->choice();
fwrite(STDERR, 'video-worker started encoder=' . $choice['encoder']
    . ' requested=' . $choice['requested']
    . ' tried=' . implode(',', $choice['tried'] ?? [])
    . "\n");
$app->worker->runLoop();
