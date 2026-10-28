<?php

declare(strict_types=1);

use Ghispi\TreeTransform\Strategy\KeepAncestors;
use Ghispi\TreeTransform\Tree;

require __DIR__ . '/../vendor/autoload.php';

$letters = require __DIR__ . '/support/letters.php';
$printTree = require __DIR__ . '/support/print-tree.php';

$result = Tree::fromNestedArray($letters)
    ->filter(fn(array $n) => $n['match'], new KeepAncestors());

echo $printTree($result, fn(array $n) => $n['name']);
