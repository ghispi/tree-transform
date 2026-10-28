<?php

declare(strict_types=1);

use Ghispi\TreeTransform\Predicate\Ancestors;
use Ghispi\TreeTransform\Tree;

require __DIR__ . '/../vendor/autoload.php';

$printTree = require __DIR__ . '/support/print-tree.php';

$categories = [
    ['title' => 'Shop', 'active' => true, 'children' => [
        ['title' => 'Clothing', 'active' => true, 'children' => [
            ['title' => 'Men', 'active' => true, 'children' => [
                ['title' => 'Shirts', 'active' => true],
            ]],
            ['title' => 'Sale', 'active' => false],
        ]],
    ]],
];
$tree = Tree::fromNestedArray($categories);
$title = fn(array $c) => $c['title'];

// One parameter: the node.
echo $printTree($tree->filter(fn(array $c) => $c['active']), $title), "\n";

// Two parameters: the node and its depth (0 for roots).
echo $printTree($tree->filter(fn(array $c, int $depth) => $depth <= 2), $title), "\n";

// Three parameters: the node, its depth and a lazy view of its ancestors.
echo $printTree(
    $tree->filter(fn(array $c, int $depth, Ancestors $ancestors) => !$ancestors->some(fn(array $a) => $a['title'] === 'Men')),
    $title,
), "\n";

// The result is cast to bool, so database values such as "1" / "0" work as they are.
$rows = [['title' => 'Shop', 'active' => '1', 'children' => [['title' => 'Sale', 'active' => '0']]]];
echo $printTree(Tree::fromNestedArray($rows)->filter(fn(array $c) => $c['active']), $title);
