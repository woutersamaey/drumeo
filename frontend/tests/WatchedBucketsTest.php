<?php

declare(strict_types=1);

namespace Drumeo\App\Tests;

use Drumeo\App\Progress;

require __DIR__ . '/helpers.php';

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

// Duration comes from the catalog; the player may only nudge it by the tolerance.
check(Progress::clampDuration(0.0, 300) === 300.0, 'missing client duration uses catalog seconds');
check(Progress::clampDuration(NAN, 300) === 300.0, 'NaN client duration uses catalog seconds');
check(Progress::clampDuration(300.4, 300) === 300.4, 'small real-media difference is kept');
check(Progress::clampDuration(3.0, 300) === 298.0, 'a too short client duration is raised to catalog - tolerance');
check(Progress::clampDuration(9999.0, 300) === 302.0, 'a too long client duration is capped at catalog + tolerance');
check(Progress::clampDuration(120.0, 0) === 120.0, 'without catalog seconds the client duration is used');
check(Progress::clampDuration(1e9, 0) === Progress::MAX_UNKNOWN_DURATION, 'unknown duration has a ceiling');
ok('clampDuration');

check(Progress::clampPosition(-5.0, 300.0) === 0.0, 'negative position becomes 0');
check(Progress::clampPosition(400.0, 300.0) === 300.0, 'position past the end is capped');
check(Progress::clampPosition(INF, 300.0) === 0.0, 'infinite position becomes 0');
ok('clampPosition');

// The cap holds every bucket of the lesson, so long lessons can reach 85% in one sitting.
check(Progress::bucketCap(300.0) === 104, '300 s: 100 buckets + slack, got ' . Progress::bucketCap(300.0));
$long = 3600.0;
$cap = Progress::bucketCap($long);
check($cap >= 1200, 'a 1 h lesson must allow all 1200 buckets, got ' . $cap);
$allBuckets = range(0, Progress::maxBucketIndex($long));
$kept = Progress::mergeBuckets(null, array_slice($allBuckets, 0, $cap), $long);
check(Progress::qualifiesWatched(count($kept), $long), 'a fully watched 1 h lesson must qualify after the cap');
check(Progress::bucketCap(0.0) === Progress::DEFAULT_BUCKET_CAP, 'unknown duration falls back to the default cap');
ok('bucketCap sized from the duration');

check(Progress::playDelta(4.0, 0.0, 300.0) === 4.0, 'client delta counts');
check(Progress::playDelta(1.0, 6.0, 300.0) === 6.0, 'new bucket coverage counts when larger');
check(Progress::playDelta(-3.0, 0.0, 300.0) === 0.0, 'negative delta is 0 (nothing is recorded)');
check(Progress::playDelta(5000.0, 0.0, 300.0) === 300.0, 'delta is capped at the duration');
check(Progress::playDelta(5000.0, 0.0, 0.0) === Progress::MAX_UNKNOWN_DELTA, 'delta is capped without a duration');
check(Progress::playDelta(INF, 0.0, 300.0) === 0.0, 'non-finite delta is ignored');
ok('playDelta');

fwrite(STDOUT, "all watched-bucket tests passed\n");
