<?php

declare(strict_types=1);

namespace Drumeo\App\Tests;

use Drumeo\App\Progress;

require __DIR__ . '/helpers.php';

$order = [
    ['id' => 10, 'n' => 1],
    ['id' => 20, 'n' => 2],
    ['id' => 30, 'n' => 3],
    ['id' => 0],
];

$set = Progress::orderIdSet($order);
check($set === [10 => true, 20 => true, 30 => true], 'orderIdSet keeps positive ids, got ' . json_encode($set));
check(Progress::orderIdSet([10, '20', 'x']) === [10 => true, 20 => true], 'orderIdSet accepts plain ids');
ok('orderIdSet');

// Loose lessons (900001…) and lessons no longer in the catalog do not count towards "x / 322".
check(Progress::countInOrder([10, 30, 900001, 900002, 555], $set) === 2, 'only Method lessons count');
check(Progress::countInOrder(['10', 10, 20], $set) === 2, 'duplicates and string ids count once');
check(Progress::countInOrder([], $set) === 0, 'nothing watched');
check(Progress::countInOrder([10], []) === 0, 'empty catalog');
ok('countInOrder');

fwrite(STDOUT, "all stats-rules tests passed\n");
