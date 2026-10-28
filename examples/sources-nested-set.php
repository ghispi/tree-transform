<?php

declare(strict_types=1);

use Ghispi\TreeTransform\Source\NestedSetFields;
use Ghispi\TreeTransform\Tree;

require __DIR__ . '/../vendor/autoload.php';

$printTree = require __DIR__ . '/support/print-tree.php';

// Two trees in one table, each numbered from 1 and told apart by "tree_id".
// SELECT * FROM category ORDER BY tree_id, lft
$rows = [
    ['id' => 1, 'tree_id' => 1, 'title' => 'Clothing',    'lft' => 1, 'rgt' => 6],
    ['id' => 2, 'tree_id' => 1, 'title' => 'Men',         'lft' => 2, 'rgt' => 3],
    ['id' => 3, 'tree_id' => 1, 'title' => 'Women',       'lft' => 4, 'rgt' => 5],
    ['id' => 4, 'tree_id' => 4, 'title' => 'Electronics', 'lft' => 1, 'rgt' => 6],
    ['id' => 5, 'tree_id' => 4, 'title' => 'Phones',      'lft' => 2, 'rgt' => 5],
    ['id' => 6, 'tree_id' => 4, 'title' => 'Cases',       'lft' => 3, 'rgt' => 4],
];

$tree = Tree::fromNestedSet($rows, new NestedSetFields(left: 'lft', right: 'rgt', root: 'tree_id'));

echo $printTree($tree, fn(array $row) => $row['title']);

// Any subset of a valid nested set works too: depth comes from the intervals, not from a level column.
// SELECT * FROM category WHERE tree_id = 4 AND lft >= 2 AND rgt <= 5 ORDER BY lft
echo "\nOnly the Phones branch:\n";
echo $printTree(Tree::fromNestedSet(array_slice($rows, 4)), fn(array $row) => $row['title']);
