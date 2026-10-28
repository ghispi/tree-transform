<?php

declare(strict_types=1);

use Ghispi\TreeTransform\Tree;

require __DIR__ . '/../vendor/autoload.php';

$letters = require __DIR__ . '/support/letters.php';
$printTree = require __DIR__ . '/support/print-tree.php';

$tree = Tree::fromNestedArray($letters);
$isActive = fn(array $n) => $n['name'] === 'E';

echo "Defaults (a menu):\n";
echo $printTree($tree->activeTrail($isActive), fn(array $n) => $n['name']);

echo "\nsiblings: false (a breadcrumb):\n";
echo $printTree($tree->activeTrail($isActive, siblings: false), fn(array $n) => $n['name']);

echo "\nActive = B, activeChildrenDepth: 1 (default):\n";
echo $printTree($tree->activeTrail(fn(array $n) => $n['name'] === 'B'), fn(array $n) => $n['name']);

echo "\nActive = B, activeChildrenDepth: 0:\n";
echo $printTree(
    $tree->activeTrail(fn(array $n) => $n['name'] === 'B', activeChildrenDepth: 0),
    fn(array $n) => $n['name'],
);

echo "\nActive = B, activeChildrenDepth: PHP_INT_MAX:\n";
echo $printTree(
    $tree->activeTrail(fn(array $n) => $n['name'] === 'B', activeChildrenDepth: PHP_INT_MAX),
    fn(array $n) => $n['name'],
);

echo "\nActive = C or I (multiple: true, default):\n";
echo $printTree(
    $tree->activeTrail(fn(array $n) => in_array($n['name'], ['C', 'I'], true)),
    fn(array $n) => $n['name'],
);

echo "\nActive = C or I, multiple: false (first match wins):\n";
echo $printTree(
    $tree->activeTrail(fn(array $n) => in_array($n['name'], ['C', 'I'], true), multiple: false),
    fn(array $n) => $n['name'],
);
