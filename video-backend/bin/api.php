<?php

declare(strict_types=1);

/**
 * Dev server: php bin/api.php
 * Production uses php-fpm + public/index.php.
 */

$host = getenv('API_HOST') ?: '0.0.0.0';
$port = getenv('API_PORT') ?: '8080';
$root = dirname(__DIR__) . '/public';
$router = $root . '/index.php';

$cmd = [
    PHP_BINARY,
    '-S',
    $host . ':' . $port,
    '-t',
    $root,
    $router,
];
fwrite(STDERR, "video-api listening on {$host}:{$port}\n");
passthru(implode(' ', array_map('escapeshellarg', $cmd)), $code);
exit($code);
