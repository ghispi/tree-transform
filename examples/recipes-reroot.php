<?php

declare(strict_types=1);

use Ghispi\TreeTransform\Tree;

require __DIR__ . '/../vendor/autoload.php';

$rows = require __DIR__ . '/support/shop.php';
$printTree = require __DIR__ . '/support/print-tree.php';

// A top navigation bar without the "Shop" root: levels 1 and 2 only.
echo "Top navigation:\n";
echo $printTree(Tree::fromNestedSet($rows)->depthWindow(1, 2), fn(array $c) => $c['title']);

// A sidebar for one section: select the branch (here with a filter on the root path), then drop the levels above.
// With a database, select the branch with WHERE lft >= ? AND rgt <= ? instead: the source reads any subset.
$section = 'Electronics';
$sidebar = Tree::fromNestedSet($rows)
    ->filter(fn(array $c, int $depth) => $depth !== 1 || $c['title'] === $section)
    ->depthWindow(2);

echo "\nSidebar for {$section}:\n";
echo $printTree($sidebar, fn(array $c) => $c['title']);
