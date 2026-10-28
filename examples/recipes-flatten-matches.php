<?php

declare(strict_types=1);

use Ghispi\TreeTransform\Strategy\Promote;
use Ghispi\TreeTransform\Tree;

require __DIR__ . '/../vendor/autoload.php';

$rows = require __DIR__ . '/support/shop.php';
$printTree = require __DIR__ . '/support/print-tree.php';

// Only categories with products. Empty intermediate levels are removed and their matching
// descendants move up, so a category list has no dead ends.
$flat = Tree::fromNestedSet($rows)
    ->filter(fn(array $c) => $c['products'] > 0, new Promote());

echo $printTree($flat, fn(array $c) => "{$c['title']} ({$c['products']})");
