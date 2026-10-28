<?php

declare(strict_types=1);

use Ghispi\TreeTransform\Tree;

require __DIR__ . '/../vendor/autoload.php';

$rows = require __DIR__ . '/support/shop.php';

// Drop the Electronics branch and renumber: left/right/level are recomputed for the result.
$rows = Tree::fromNestedSet($rows)
    ->filter(fn(array $c) => $c['slug'] !== 'electronics')
    ->maxDepth(1)
    ->map(fn(array $c) => ['id' => $c['id'], 'title' => $c['title']])
    ->toNestedSet(leftField: 'lft', rightField: 'rgt', levelField: 'lvl', levelBase: 0);

foreach ($rows as $row) {
    printf("%-2d %-9s lft %2d  rgt %2d  lvl %d\n", $row['id'], $row['title'], $row['lft'], $row['rgt'], $row['lvl']);
}

// Several roots numbered from 1 each (Gedmo with a TreeRoot column).
$forest = Tree::fromNestedSet(require __DIR__ . '/support/shop.php')
    ->depthWindow(1, 1)
    ->map(fn(array $c) => ['title' => $c['title']])
    ->toNestedSet(levelField: null, restartPerRoot: true);
echo "\n";
foreach ($forest as $row) {
    echo "{$row['title']}: {$row['lft']}..{$row['rgt']}\n";
}
