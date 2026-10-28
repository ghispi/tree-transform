<?php

declare(strict_types=1);

use Ghispi\TreeTransform\Tree;

require __DIR__ . '/../vendor/autoload.php';

$rows = [
    ['id' => 1, 'title' => 'Clothing', 'lft' => 1, 'rgt' => 6],
    ['id' => 2, 'title' => 'Men',      'lft' => 2, 'rgt' => 3],
    ['id' => 3, 'title' => 'Women',    'lft' => 4, 'rgt' => 5],
];
$tree = Tree::fromNestedSet($rows)->map(fn(array $row) => ['id' => $row['id'], 'title' => $row['title']]);

echo "Default: every node gets a 'children' key\n";
echo json_encode($tree->toNestedArray()), "\n\n";

echo "omitEmptyChildren: true\n";
echo json_encode($tree->toNestedArray(omitEmptyChildren: true)), "\n\n";

echo "childrenKey: '__children' (same shape as Gedmo buildTree())\n";
echo json_encode($tree->toNestedArray(childrenKey: '__children')), "\n\n";

echo "nodeFactory: build any shape\n";
echo json_encode($tree->toNestedArray(nodeFactory: fn(array $node, array $children, int $depth) => [
    'text' => $node['title'],
    'level' => $depth,
    'nodes' => $children,
])), "\n";
