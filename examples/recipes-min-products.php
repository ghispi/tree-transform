<?php

declare(strict_types=1);

use Ghispi\TreeTransform\Strategy\MinMatches;
use Ghispi\TreeTransform\Tree;

require __DIR__ . '/../vendor/autoload.php';

$rows = require __DIR__ . '/support/shop.php';
$printTree = require __DIR__ . '/support/print-tree.php';

// Show a category only if at least two categories in its subtree (itself included) have products.
echo $printTree(
    Tree::fromNestedSet($rows)->filter(fn(array $c) => $c['products'] > 0, new MinMatches(2)),
    fn(array $c) => $c['title'],
);
