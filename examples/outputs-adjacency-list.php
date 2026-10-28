<?php

declare(strict_types=1);

use Ghispi\TreeTransform\Strategy\Promote;
use Ghispi\TreeTransform\Tree;

require __DIR__ . '/../vendor/autoload.php';

$rows = require __DIR__ . '/support/shop.php';

// Only categories with products, moved up to the nearest kept ancestor: parents are rewritten.
$list = Tree::fromNestedSet($rows)
    ->filter(fn(array $c) => $c['products'] > 0, new Promote())
    ->map(fn(array $c) => ['id' => $c['id'], 'title' => $c['title']])
    ->toAdjacencyList(idField: 'id', parentField: 'parent_id', rootParent: 0);

foreach ($list as $row) {
    printf("%-3d %-8s parent_id: %d\n", $row['id'], $row['title'], $row['parent_id']);
}

// Objects cannot get a parent field set; build the row with a factory instead.
$pairs = Tree::fromNestedSet($rows)
    ->maxDepth(1)
    ->toAdjacencyList(nodeFactory: fn(array $c, int|string|null $parentId, int $depth) => "{$c['id']}->" . ($parentId ?? 'null'));
echo "\n", implode(', ', $pairs), "\n";
