<?php

declare(strict_types=1);

use Drumeo\Video\App;
use Drumeo\Video\Http\Request;
use Drumeo\Video\Http\Response;

require dirname(__DIR__) . '/vendor/autoload.php';

try {
    $app = App::build();
    $app->handle(Request::fromGlobals())->send();
} catch (\Throwable $e) {
    // App::handle already maps route errors; this catches bootstrap failures
    // (e.g. an unwritable CACHE_DIR) so clients still get JSON.
    error_log('[api] bootstrap: ' . $e);
    if (!headers_sent()) {
        Response::serverError()->send();
    }
}
