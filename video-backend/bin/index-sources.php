<?php

declare(strict_types=1);

/**
 * Optional helper: print the SOURCE_DIR id → path map.
 * The API already indexes "title [id].mkv" names on demand.
 */

use Drumeo\Video\App;

require dirname(__DIR__) . '/vendor/autoload.php';

$app = App::build();
$app->sources->refresh();
$map = $app->sources->index();
echo json_encode($map, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
fwrite(STDERR, count($map) . " sources\n");
