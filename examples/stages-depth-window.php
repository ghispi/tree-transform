<?php

declare(strict_types=1);

use Ghispi\TreeTransform\Tree;

require __DIR__ . '/../vendor/autoload.php';

$rows = require __DIR__ . '/support/shop.php';
$printTree = require __DIR__ . '/support/print-tree.php';

// Levels 1..2: the shop root disappears, its children become roots, grandchildren of them are cut.
echo $printTree(Tree::fromNestedSet($rows)->depthWindow(1, 2), fn(array $c) => $c['title']);

echo "\ndepthWindow(2) (no upper limit):\n";
echo $printTree(Tree::fromNestedSet($rows)->depthWindow(2), fn(array $c) => $c['title']);
