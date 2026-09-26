<?php

declare(strict_types=1);

use Drumeo\App\AdminDashboard;
use Drumeo\App\DirStats;

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

if (AdminDashboard::isAdmin(['slug' => 'wouter']) !== true) {
    fail('wouter is admin');
}
if (AdminDashboard::isAdmin(['slug' => 'vic']) !== false) {
    fail('vic is not admin');
}
if (AdminDashboard::isAdmin(null) !== false) {
    fail('missing profile is not admin');
}
ok('admin slug');

$lessons = [
    ['id' => 1, 'n' => 1, 'vimeoId' => 'aaa', 'title' => 'Hats', 'titleNl' => 'Hi-hats'],
    ['id' => 2, 'n' => 2, 'vimeoId' => 'bbb', 'title' => 'Kick', 'titleNl' => 'Bassdrum'],
    ['id' => 3, 'n' => 3, 'vimeoId' => '', 'title' => 'Leeg', 'titleNl' => ''],
];
$report = AdminDashboard::catalogReport($lessons, ['aaa', 'ccc']);
if ($report['lessonCount'] !== 3 || $report['sourceCount'] !== 2) {
    fail('counts ' . json_encode($report));
}
if ($report['missingCount'] !== 1 || $report['missing'][0]['vimeoId'] !== 'bbb') {
    fail('missing lesson');
}
if ($report['extraCount'] !== 1 || $report['extra'][0] !== 'ccc') {
    fail('extra source');
}
if ($report['withSource'] !== 1) {
    fail('withSource');
}
ok('catalog report');

$video = AdminDashboard::annotateVideo([
    'source' => ['ids' => ['aaa'], 'bytes' => 10],
    'cleanup' => ['items' => [['id' => 'aaa', 'bytes' => 4]]],
    'cache' => ['largest' => [['id' => 'missing']]],
    'queue' => [['videoId' => 'bbb', 'recipe' => 'avc_1080']],
    'jobs' => ['active' => [], 'recent' => [['videoId' => 'aaa']]],
], $lessons);
if (isset($video['source']['ids'])) {
    fail('source ids leaked');
}
if (($video['cleanup']['items'][0]['titleNl'] ?? '') !== 'Hi-hats') {
    fail('cleanup title');
}
if (($video['queue'][0]['title'] ?? '') !== 'Kick') {
    fail('queue title');
}
if (($video['jobs']['recent'][0]['n'] ?? null) !== 1) {
    fail('job lesson number');
}
ok('annotate');

$tmp = sys_get_temp_dir() . '/dirstats-' . bin2hex(random_bytes(4));
mkdir($tmp . '/nested', 0777, true);
file_put_contents($tmp . '/a.txt', '12345');
file_put_contents($tmp . '/nested/b.txt', 'xy');
$stats = DirStats::of($tmp);
if ($stats['exists'] !== true || $stats['files'] !== 2 || $stats['bytes'] !== 7) {
    fail('dir stats ' . json_encode($stats));
}
$missing = DirStats::of($tmp . '/nope');
if ($missing['exists'] !== false || $missing['bytes'] !== 0) {
    fail('missing dir');
}
ok('dir stats');

echo "all ok\n";
