<?php

declare(strict_types=1);

namespace Drumeo\App\Tests;

use Drumeo\App\Catalog;
use Drumeo\App\RedisCache;

require __DIR__ . '/helpers.php';

$tmp = tempDir('catalog');
$file = $tmp . '/catalog.json';
$media = $tmp . '/media';
mkdir($media);

$catalogData = [
    'intro' => ['id' => 1, 'vimeoId' => 'v1', 'seconds' => 60, 'role' => 'method-intro', 'title' => ['en' => 'Welcome', 'nl' => 'Welkom']],
    'lessons' => [
        '2' => ['id' => 2, 'vimeoId' => 'v2', 'seconds' => 125, 'title' => ['en' => 'Hats', 'nl' => 'Hi-hats']],
        '3' => ['id' => 3, 'vimeoId' => 'v3', 'seconds' => 90, 'title' => ['en' => 'Kick']],
    ],
    'paths' => [[
        'id' => 7,
        'slug' => 'ch1',
        'title' => ['en' => 'Chapter 1', 'nl' => 'Hoofdstuk 1'],
        'order' => [2, 3, 99],
        'packs' => [
            ['title' => ['en' => 'Hands only'], 'lessons' => [2]],
            ['title' => ['en' => 'Feet', 'nl' => 'Voeten'], 'lessons' => [3, 99]],
            ['lessons' => [3]],
        ],
    ]],
    'loose' => [
        ['id' => 900001, 'vimeoId' => 'song1', 'seconds' => 200, 'role' => 'loose', 'title' => ['en' => 'Song']],
        ['id' => 2, 'vimeoId' => 'v2', 'seconds' => 125, 'title' => ['en' => 'Duplicate of an ordered lesson']],
    ],
];
file_put_contents($file, json_encode($catalogData));

$cache = new RedisCache(offlineConfig());
$catalog = new Catalog(offlineConfig($file, $media), $cache);
$all = $catalog->all();

$orderIds = array_map(static fn (array $l): int => $l['id'], $all['order']);
check($orderIds === [1, 2, 3], 'order is intro + path order without unknown ids, got ' . json_encode($orderIds));
check($all['order'][0]['n'] === 1 && $all['order'][2]['n'] === 3, 'lessons are numbered globally');
check($catalog->lesson(3)['prevId'] === 2 && $catalog->lesson(3)['nextId'] === null, 'prev/next follow the order');
check($all['paths'][0]['n'] === 1 && $all['paths'][0]['videoCount'] === 2, 'chapter number and count');
ok('numbering');

$packs = $all['paths'][0]['skillPacks'];
check($packs[0]['title'] === 'Hands only' && $packs[0]['titleNl'] === 'Hands only', 'pack without nl falls back to en, got ' . json_encode($packs[0]['titleNl']));
check($packs[1]['titleNl'] === 'Voeten', 'pack nl title is used');
check(count($packs[1]['lessons']) === 1, 'pack drops ids that are not in the path order');
check($packs[2]['title'] === 'More' && $packs[2]['titleNl'] === 'Meer', 'untitled pack is More / Meer');
ok('pack titles');

check(count($all['loose']) === 1 && $all['loose'][0]['id'] === 900001, 'loose skips lessons already in the order');
check($catalog->lesson(900001)['role'] === 'loose' && $catalog->lesson(900001)['n'] === null, 'loose lesson has no number');
ok('loose lessons');

// The cache key follows the file, so editing catalog.json needs no manual DEL.
$key1 = Catalog::catalogCacheKey($file);
check(str_starts_with($key1, 'catalog:v9:'), 'key prefix, got ' . $key1);
check(Catalog::catalogCacheKey($file) === $key1, 'key is stable');
touch($file, time() - 100);
$key2 = Catalog::catalogCacheKey($file);
check($key2 !== $key1, 'mtime change gives a new key');
file_put_contents($file, json_encode($catalogData) . ' ');
touch($file, time() - 100);
check(Catalog::catalogCacheKey($file) !== $key2, 'size change gives a new key');
check(str_starts_with(Catalog::catalogCacheKey($tmp . '/missing.json'), 'catalog:v9:'), 'missing file still gives a key');
ok('catalog cache key');

check(Catalog::scanMedia($tmp . '/nope') === null, 'missing media dir is a failed scan');
check(Catalog::scanMedia($media) === [], 'empty media dir');
touch($media . '/Lesson one [v2].mkv');
touch($media . '/v3.MKV');
touch($media . '/.smbdelete123 [v9].mkv');
touch($media . '/notes [v4].txt');
$scan = Catalog::scanMedia($media);
ksort($scan);
check(array_keys($scan) === ['v2', 'v3'], 'scan finds [id].mkv and id.mkv, skips dotfiles and other types, got ' . json_encode($scan));
$fresh = new Catalog(offlineConfig($file, $media), $cache);
check(array_keys($fresh->availableVideos()) == ['v2', 'v3'] || array_keys($fresh->availableVideos()) == ['v3', 'v2'], 'availableVideos');
$noNas = new Catalog(offlineConfig($file, $tmp . '/not-mounted'), $cache);
check($noNas->availableVideos() === [], 'unmounted NAS gives no videos');
ok('media index');

$broken = $tmp . '/broken.json';
file_put_contents($broken, '{not json');
$bad = (new Catalog(offlineConfig($broken, $media), $cache))->all();
check($bad['order'] === [] && $bad['lessons'] === [], 'broken catalog builds empty');
ok('broken catalog');

fwrite(STDOUT, "all catalog tests passed\n");
