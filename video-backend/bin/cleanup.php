<?php

declare(strict_types=1);

use Drumeo\Video\App;

require dirname(__DIR__) . '/vendor/autoload.php';

$dry = in_array('--dry-run', $argv, true);
$app = App::build();
$result = $app->cleaner->run($dry);
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
