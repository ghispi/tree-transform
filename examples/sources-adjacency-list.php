<?php

declare(strict_types=1);

use Ghispi\TreeTransform\Source\AdjacencyListFields;
use Ghispi\TreeTransform\Source\OrphanPolicy;
use Ghispi\TreeTransform\Tree;

require __DIR__ . '/../vendor/autoload.php';

$printTree = require __DIR__ . '/support/print-tree.php';

// Rows in any order; roots have parent 0 here, and "position" orders siblings.
$rows = [
    ['id' => 12, 'parent' => 10, 'title' => 'Shipping', 'position' => 2],
    ['id' => 10, 'parent' => 0,  'title' => 'Help',     'position' => 2],
    ['id' => 11, 'parent' => 10, 'title' => 'Returns',  'position' => 1],
    ['id' => 20, 'parent' => 0,  'title' => 'About us', 'position' => 1],
    ['id' => 21, 'parent' => 20, 'title' => 'Careers',  'position' => 1],
];

$tree = Tree::fromAdjacencyList(
    $rows,
    new AdjacencyListFields(id: 'id', parent: 'parent'),
    rootParent: 0,
    siblingOrder: 'position',
);
echo $printTree($tree, fn(array $row) => $row['title']);

// A branch loaded on its own: its top rows point at a parent that is not in the result.
$branch = [
    ['id' => 11, 'parent_id' => 10, 'title' => 'Returns'],
    ['id' => 12, 'parent_id' => 10, 'title' => 'Shipping'],
    ['id' => 13, 'parent_id' => 12, 'title' => 'Delivery times'],
];

echo "\nOrphanPolicy::AsRoot:\n";
echo $printTree(Tree::fromAdjacencyList($branch, orphans: OrphanPolicy::AsRoot), fn(array $row) => $row['title']);

echo "\nOrphanPolicy::Drop:\n";
echo $printTree(Tree::fromAdjacencyList($branch, orphans: OrphanPolicy::Drop), fn(array $row) => $row['title']);

echo "\nOrphanPolicy::Throw (default):\n";

try {
    Tree::fromAdjacencyList($branch)->count();
} catch (Ghispi\TreeTransform\Exception\OrphanNodeException $e) {
    echo $e->getMessage(), "\n";
}
