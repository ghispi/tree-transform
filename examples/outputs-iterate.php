<?php

declare(strict_types=1);

use Ghispi\TreeTransform\Tree;

require __DIR__ . '/../vendor/autoload.php';

$rows = require __DIR__ . '/support/shop.php';

$tree = Tree::fromNestedSet($rows)->maxDepth(2);

// Keys are depths and repeat, so loop with foreach. iterator_to_array() would keep only one node per depth.
foreach ($tree->iterate() as $depth => $category) {
    echo str_repeat('    ', $depth), $category['title'], "\n";
}

// A Tree is iterable itself: foreach ($tree as $depth => $node) does the same.
echo "\nNodes: ", $tree->count(), "\n";
