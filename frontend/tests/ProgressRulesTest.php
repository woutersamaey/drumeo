<?php

declare(strict_types=1);

namespace Drumeo\App\Tests;

use Drumeo\App\Progress;

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($autoload)) {
    require $autoload;
} else {
    require dirname(__DIR__) . '/src/Progress.php';
}

function fail(string $msg): never
{
    fwrite(STDERR, "FAIL: $msg\n");
    exit(1);
}

function ok(string $msg): void
{
    fwrite(STDOUT, "ok: $msg\n");
}

if (Progress::HISTORY_RATIO !== 0.33) {
    fail('HISTORY_RATIO should be 0.33, got ' . (string) Progress::HISTORY_RATIO);
}
ok('HISTORY_RATIO');

if (Progress::isFollowed(null)) {
    fail('null progress must not be followed');
}
if (Progress::isFollowed([])) {
    fail('empty progress must not be followed');
}
ok('isFollowed null/empty');

if (!Progress::isFollowed(['watched' => true, 'position' => 0, 'duration' => 0])) {
    fail('watched progress must be followed');
}
ok('isFollowed watched');

if (Progress::isFollowed(['watched' => false, 'position' => 32, 'duration' => 100])) {
    fail('32% must not be followed');
}
ok('isFollowed 32%');

if (!Progress::isFollowed(['watched' => false, 'position' => 33, 'duration' => 100])) {
    fail('33% must be followed');
}
ok('isFollowed 33%');

if (Progress::isFollowed(['watched' => false, 'position' => 50, 'duration' => 0])) {
    fail('zero duration must not be followed');
}
ok('isFollowed 0 duration');

$order = [
    ['id' => 10, 'n' => 1],
    ['id' => 20, 'n' => 2],
    ['id' => 30, 'n' => 3],
];

$start = Progress::resumeFromOrder($order, ['progress' => []]);
if ($start === null || $start['reason'] !== 'start' || $start['lessonId'] !== 10 || $start['position'] !== 0) {
    fail('empty progress should start at first lesson from 0, got ' . json_encode($start));
}
if (($start['reason'] ?? '') === 'continue') {
    fail('start must not use continue');
}
ok('resumeFromOrder start');

$mid = Progress::resumeFromOrder($order, [
    'lastLessonId' => 30,
    'progress' => [
        '10' => ['watched' => true, 'position' => 90, 'duration' => 100],
        '30' => ['watched' => false, 'position' => 40, 'duration' => 80],
    ],
]);
if ($mid === null || $mid['lessonId'] !== 20 || $mid['reason'] !== 'next' || $mid['position'] !== 0) {
    fail('resume should pick first unwatched, not last-clicked, got ' . json_encode($mid));
}
if (($mid['reason'] ?? '') === 'continue') {
    fail('in-progress last click must not continue');
}
ok('resumeFromOrder next, not last-clicked');

$inProgressFirst = Progress::resumeFromOrder($order, [
    'lastLessonId' => 10,
    'progress' => [
        '10' => ['watched' => false, 'position' => 55, 'duration' => 100],
    ],
]);
if ($inProgressFirst === null || $inProgressFirst['reason'] !== 'start' || $inProgressFirst['position'] !== 0 || $inProgressFirst['lessonId'] !== 10) {
    fail('unfinished first lesson should start from 0, got ' . json_encode($inProgressFirst));
}
if (($inProgressFirst['reason'] ?? '') === 'continue') {
    fail('unfinished lesson must not continue');
}
ok('resumeFromOrder never continue, position 0');

$done = Progress::resumeFromOrder($order, [
    'progress' => [
        '10' => ['watched' => true],
        '20' => ['watched' => true],
        '30' => ['watched' => true, 'position' => 12, 'duration' => 40],
    ],
]);
if ($done === null || $done['reason'] !== 'complete' || $done['lessonId'] !== 30 || $done['position'] !== 0) {
    fail('all watched should complete last lesson from 0, got ' . json_encode($done));
}
if (($done['reason'] ?? '') === 'continue') {
    fail('complete must not use continue');
}
ok('resumeFromOrder complete');

if (Progress::firstUnwatchedId($order, ['10' => ['watched' => true]]) !== 20) {
    fail('firstUnwatchedId should skip watched lessons');
}
ok('firstUnwatchedId');

$catalog = [
    'intro' => ['id' => 1, 'n' => 1, 'vimeoId' => 'v1', 'title' => 'Intro'],
    'order' => [
        ['id' => 1, 'n' => 1, 'vimeoId' => 'v1'],
        ['id' => 2, 'n' => 2, 'vimeoId' => 'v2'],
        ['id' => 3, 'n' => 3, 'vimeoId' => 'v3'],
        ['id' => 4, 'n' => 4, 'vimeoId' => 'v4'],
    ],
    'lessons' => [
        '1' => ['id' => 1, 'n' => 1, 'vimeoId' => 'v1'],
        '2' => ['id' => 2, 'n' => 2, 'vimeoId' => 'v2'],
        '3' => ['id' => 3, 'n' => 3, 'vimeoId' => 'v3'],
        '4' => ['id' => 4, 'n' => 4, 'vimeoId' => 'v4'],
    ],
    'paths' => [
        [
            'id' => 10,
            'n' => 1,
            'slug' => 'ch1',
            'videoCount' => 2,
            'posterVimeoId' => 'v2',
            'lessons' => [
                ['id' => 2, 'n' => 2, 'vimeoId' => 'v2'],
                ['id' => 3, 'n' => 3, 'vimeoId' => 'v3'],
            ],
            'skillPacks' => [
                ['id' => 1, 'title' => 'Now', 'lessons' => [['id' => 2, 'n' => 2, 'vimeoId' => 'v2']]],
                ['id' => 2, 'title' => 'Later', 'lessons' => [['id' => 3, 'n' => 3, 'vimeoId' => 'v3']]],
            ],
        ],
        [
            'id' => 11,
            'n' => 2,
            'slug' => 'ch2',
            'videoCount' => 1,
            'posterVimeoId' => 'v4',
            'lessons' => [
                ['id' => 4, 'n' => 4, 'vimeoId' => 'v4'],
            ],
            'skillPacks' => [
                ['id' => 3, 'title' => 'Future', 'lessons' => [['id' => 4, 'n' => 4, 'vimeoId' => 'v4']]],
            ],
        ],
    ],
];
$originalPathCount = count($catalog['paths']);
$originalCh2Count = $catalog['paths'][1]['videoCount'];

$progress = [
    '1' => ['watched' => true, 'position' => 10, 'duration' => 10],
];
$visible = Progress::visibleIds($catalog['order'], $progress, true);
if ($visible !== [1, 2]) {
    fail('visibleIds should include next unwatched and stop, got ' . json_encode($visible));
}
ok('visibleIds includes next unwatched');

if (Progress::visibleIds($catalog['order'], $progress, false) !== [1, 2, 3, 4]) {
    fail('hideFuture false should return all order ids');
}
ok('visibleIds hideFuture off');

$allWatched = [
    '1' => ['watched' => true],
    '2' => ['watched' => true],
    '3' => ['watched' => true],
    '4' => ['watched' => true],
];
if (Progress::visibleIds($catalog['order'], $allWatched, true) !== [1, 2, 3, 4]) {
    fail('all watched should expose every id');
}
ok('visibleIds all watched');

$filtered = Progress::filterCatalogData($catalog, $visible);
$orderIds = array_map(static fn(array $row): int => (int) $row['id'], $filtered['order']);
if ($orderIds !== [1, 2]) {
    fail('filtered order should drop later lessons, got ' . json_encode($orderIds));
}
if ($filtered['intro'] === null || (int) $filtered['intro']['id'] !== 1) {
    fail('intro should stay when visible');
}
if (count($filtered['paths']) !== 1 || $filtered['paths'][0]['slug'] !== 'ch1') {
    fail('future chapter with no visible lessons should be dropped');
}
if ($filtered['paths'][0]['n'] !== 1) {
    fail('path n must stay original');
}
$pathLessons = array_map(static fn(array $row): int => (int) $row['id'], $filtered['paths'][0]['lessons']);
if ($pathLessons !== [2]) {
    fail('kept path should only contain the next lesson, got ' . json_encode($pathLessons));
}
if ((int) $filtered['paths'][0]['lessons'][0]['n'] !== 2) {
    fail('lesson n must stay original');
}
if ((int) $filtered['paths'][0]['videoCount'] !== 1 || ($filtered['paths'][0]['posterVimeoId'] ?? null) !== 'v2') {
    fail('videoCount/posterVimeoId should match remaining lessons');
}
if (count($filtered['paths'][0]['skillPacks']) !== 1 || $filtered['paths'][0]['skillPacks'][0]['title'] !== 'Now') {
    fail('empty future skill packs should be dropped');
}
if (isset($filtered['lessons']['3']) || isset($filtered['lessons']['4'])) {
    fail('filtered lessons map should drop hidden ids');
}
ok('filterCatalogData next lesson, drop future chapter');

if (count($catalog['paths']) !== $originalPathCount || $catalog['paths'][1]['videoCount'] !== $originalCh2Count) {
    fail('filterCatalogData must not mutate the source catalog');
}
ok('filterCatalogData copies');

$noIntro = Progress::filterCatalogData($catalog, [2, 3]);
if ($noIntro['intro'] !== null) {
    fail('intro should be hidden when not visible');
}
$noIntroIds = array_map(static fn(array $row): int => (int) $row['id'], $noIntro['order']);
if ($noIntroIds !== [2, 3]) {
    fail('order without intro should keep visible lessons, got ' . json_encode($noIntroIds));
}
if (count($noIntro['paths']) !== 1 || $noIntro['paths'][0]['n'] !== 1) {
    fail('path with visible lessons should remain with original n');
}
ok('intro hidden only if not visible');

fwrite(STDOUT, "all progress-rules tests passed\n");
