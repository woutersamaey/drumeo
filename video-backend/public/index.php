<?php

declare(strict_types=1);

use Drumeo\Video\App;
use Drumeo\Video\Http\Request;

require dirname(__DIR__) . '/vendor/autoload.php';

$app = App::build();
$request = Request::fromGlobals();
$app->handle($request)->send();
