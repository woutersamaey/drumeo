<?php

declare(strict_types=1);

namespace Drumeo\App\Tests;

use Drumeo\App\ImageScaler;

require dirname(__DIR__) . '/vendor/autoload.php';

function fail(string $msg): never
{
    fwrite(STDERR, "FAIL: $msg\n");
    exit(1);
}

function ok(string $msg): void
{
    fwrite(STDOUT, "ok: $msg\n");
}

if (!extension_loaded('gd')) {
    fail('gd extension required');
}

$tmp = sys_get_temp_dir() . '/imgscale-' . bin2hex(random_bytes(4));
mkdir($tmp . '/src', 0777, true);
mkdir($tmp . '/cache', 0777, true);

$src = $tmp . '/src/shot.jpg';
$im = imagecreatetruecolor(400, 225);
$red = imagecolorallocate($im, 200, 40, 40);
imagefilledrectangle($im, 0, 0, 399, 224, $red);
imagejpeg($im, $src, 90);
unset($im);

$scaler = new ImageScaler($tmp . '/cache', static fn (string $id): ?string => $id === 'abc123' ? $src : null);

if ($scaler->ensure('nope', 160, 'jpg') !== null) {
    fail('unknown id should miss');
}
if ($scaler->ensure('abc123', 111, 'jpg') !== null) {
    fail('illegal width should miss');
}
if ($scaler->ensure('../x', 160, 'jpg') !== null) {
    fail('unsafe id should miss');
}

$jpg = $scaler->ensure('abc123', 160, 'jpg');
if ($jpg === null || !is_file($jpg)) {
    fail('jpg variant not written');
}
$info = getimagesize($jpg);
if ($info === false || $info[0] !== 160) {
    fail('jpg width expected 160, got ' . json_encode($info));
}
ok('jpg 160w');

$again = $scaler->ensure('abc123', 160, 'jpg');
if ($again !== $jpg) {
    fail('cache path should be stable');
}
ok('cache hit path');

if ($scaler->supportsWebp()) {
    $webp = $scaler->ensure('abc123', 320, 'webp');
    if ($webp === null || !is_file($webp)) {
        fail('webp variant not written');
    }
    $winfo = getimagesize($webp);
    if ($winfo === false || $winfo[0] !== 320) {
        fail('webp width expected 320');
    }
    if (filesize($webp) < 32) {
        fail('webp too small');
    }
    ok('webp 320w ' . filesize($webp) . 'b');
} else {
    ok('webp skipped (no support)');
}

$full = $scaler->ensure('abc123', 1920, 'jpg');
if ($full === null) {
    fail('1920 variant');
}
$fin = getimagesize($full);
if ($fin === false || $fin[0] !== 400) {
    fail('must not upscale, expected 400 got ' . json_encode($fin));
}
ok('no upscale');

echo "ImageScaler tests passed\n";
