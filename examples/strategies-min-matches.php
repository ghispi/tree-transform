<?php

declare(strict_types=1);

use Ghispi\TreeTransform\Strategy\MinMatches;
use Ghispi\TreeTransform\Tree;

require __DIR__ . '/../vendor/autoload.php';

$letters = require __DIR__ . '/support/letters.php';
$printTree = require __DIR__ . '/support/print-tree.php';

$tree = Tree::fromNestedArray($letters);
$matches = fn(array $n) => $n['match'];

foreach ([1, 2, 3] as $min) {
    echo "MinMatches({$min}):\n";
    echo $printTree($tree->filter($matches, new MinMatches($min)), fn(array $n) => $n['name']), "\n";
}
