<?php

declare(strict_types=1);

use Ghispi\TreeTransform\Tree;

require __DIR__ . '/../vendor/autoload.php';

$letters = require __DIR__ . '/support/letters.php';
$printTree = require __DIR__ . '/support/print-tree.php';

// Draw the input, marking which nodes match the predicate fn (array $n) => $n['match'].
echo $printTree(
    Tree::fromNestedArray($letters),
    fn(array $n) => $n['name'] . ($n['match'] ? ' ✓' : ' ✗'),
);
