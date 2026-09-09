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

$duration = 300.0;
if (Progress::maxBucketIndex($duration) !== 99) {
    fail('max bucket for 300s should be 99, got ' . Progress::maxBucketIndex($duration));
}
ok('full duration buckets');

$merged = Progress::mergeBuckets(null, [0, 1, 99, 100, 999], $duration);
if ($merged !== [0, 1, 99]) {
    fail('merge should drop buckets past duration, got ' . json_encode($merged));
}
ok('out-of-range buckets dropped');

$need = Progress::requiredBucketCount($duration);
if ($need !== 85) {
    fail('85% of 100 buckets should be 85, got ' . $need);
}
if (Progress::qualifiesWatched(2, $duration)) {
    fail('two buckets must not count as watched');
}
if (Progress::qualifiesWatched(84, $duration)) {
    fail('84 buckets must not count as watched');
}
if (!Progress::qualifiesWatched(85, $duration)) {
    fail('85 buckets should count as watched');
}
ok('85% unique coverage of full duration');

$uniq = Progress::mergeBuckets('[3,1]', [1, 2, 2], 300);
if ($uniq !== [1, 2, 3]) {
    fail('merge should unique+sort, got ' . json_encode($uniq));
}
ok('unique sorted merge');

fwrite(STDOUT, "all watched-bucket tests passed\n");
