<?php

declare(strict_types=1);

namespace Drumeo\App\Tests;

use Drumeo\App\Config;

require_once dirname(__DIR__) . '/vendor/autoload.php';

function fail(string $msg): never
{
    fwrite(STDERR, "FAIL: $msg\n");
    exit(1);
}

function ok(string $msg): void
{
    fwrite(STDOUT, "ok: $msg\n");
}

function check(bool $condition, string $msg): void
{
    if (!$condition) {
        fail($msg);
    }
}

/** A fresh temp directory, removed again when the test exits. */
function tempDir(string $prefix): string
{
    $dir = sys_get_temp_dir() . '/' . $prefix . '-' . bin2hex(random_bytes(4));
    mkdir($dir, 0777, true);
    register_shutdown_function(static fn () => rmTree($dir));
    return $dir;
}

function rmTree(string $path): void
{
    if (is_link($path) || is_file($path)) {
        @unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            rmTree($path . '/' . $entry);
        }
    }
    @rmdir($path);
}

/** Config whose Redis cannot be reached (the cache then degrades to a no-op). */
function offlineConfig(string $catalogPath = '', string $mediaDir = '', string $adminPassword = ''): Config
{
    return new Config(
        dbHost: '127.0.0.1',
        dbPort: 1,
        dbName: 'none',
        dbUser: 'none',
        dbPass: 'none',
        redisHost: '127.0.0.1',
        redisPort: 1,
        catalogPath: $catalogPath,
        thumbsDir: '',
        mediaDir: $mediaDir,
        imageCacheDir: '',
        notationPath: '',
        adminPassword: $adminPassword,
    );
}
