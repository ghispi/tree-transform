<?php

declare(strict_types=1);

use Ghispi\TreeTransform\Tree;

require __DIR__ . '/../vendor/autoload.php';

// 200,000 generated nested set rows (1000 sections × 199 items), produced one at a time like a database cursor.
$rows = (function (): Generator {
    $left = 1;
    for ($section = 1; $section <= 1000; ++$section) {
        yield ['id' => $section, 'visible' => $section % 2 === 0, 'lft' => $left, 'rgt' => $left + 2 * 199 + 1];
        for ($item = 1; $item <= 199; ++$item) {
            yield ['id' => $section * 1000 + $item, 'visible' => true, 'lft' => $left + 2 * $item - 1, 'rgt' => $left + 2 * $item];
        }
        $left += 2 * 199 + 2;
    }
})();

$before = memory_get_usage();
$peak = 0;
$count = 0;
foreach (Tree::fromNestedSet($rows)->filter(fn(array $row) => $row['visible'])->iterate() as $depth => $row) {
    ++$count;
    $peak = max($peak, memory_get_usage() - $before);
}

echo "Streamed {$count} of 200000 nodes\n";
echo 'Extra memory stayed under 1 MB: ', $peak < 1024 * 1024 ? 'yes' : 'no', "\n";
