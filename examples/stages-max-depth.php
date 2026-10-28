<?php

declare(strict_types=1);

use Ghispi\TreeTransform\Tree;

require __DIR__ . '/../vendor/autoload.php';

$rows = require __DIR__ . '/support/shop.php';
$printTree = require __DIR__ . '/support/print-tree.php';

// Roots are depth 0: maxDepth(1) keeps the root and its children.
echo $printTree(Tree::fromNestedSet($rows)->maxDepth(1), fn(array $c) => $c['title']);

echo "\nmaxDepth(0):\n";
echo $printTree(Tree::fromNestedSet($rows)->maxDepth(0), fn(array $c) => $c['title']);
