<?php

declare(strict_types=1);

use Ghispi\TreeTransform\Strategy\OnMatch;
use Ghispi\TreeTransform\Strategy\Placeholder;
use Ghispi\TreeTransform\Tree;

require __DIR__ . '/../vendor/autoload.php';

$letters = require __DIR__ . '/support/letters.php';
$printTree = require __DIR__ . '/support/print-tree.php';

$tree = Tree::fromNestedArray($letters);
$matches = fn(array $n) => $n['match'];

// The factory creates the stub node for each run of removed siblings.
$stub = fn(int $hidden, int $depth) => ['name' => "({$hidden} hidden)"];

echo $printTree($tree->filter($matches, new Placeholder($stub)), fn(array $n) => $n['name']);

echo "\nWith OnMatch::KeepDescendants:\n";
echo $printTree($tree->filter($matches, new Placeholder($stub, OnMatch::KeepDescendants)), fn(array $n) => $n['name']);
