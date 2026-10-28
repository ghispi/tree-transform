<?php

declare(strict_types=1);

use Ghispi\TreeTransform\Tree;

require __DIR__ . '/../vendor/autoload.php';

$rows = require __DIR__ . '/support/shop.php';
$printTree = require __DIR__ . '/support/print-tree.php';

// Alphabetical within every parent; subtrees move with their root.
$sorted = Tree::fromNestedSet($rows)
    ->sortSiblings(fn(array $a, array $b) => strcmp($a['title'], $b['title']));

echo $printTree($sorted, fn(array $c) => $c['title']);
