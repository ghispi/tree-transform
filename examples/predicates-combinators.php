<?php

declare(strict_types=1);

use Ghispi\TreeTransform\Predicate\AllOf;
use Ghispi\TreeTransform\Predicate\AnyOf;
use Ghispi\TreeTransform\Predicate\DepthRange;
use Ghispi\TreeTransform\Predicate\FieldEquals;
use Ghispi\TreeTransform\Predicate\FieldIn;
use Ghispi\TreeTransform\Predicate\Not;
use Ghispi\TreeTransform\Strategy\KeepAncestors;
use Ghispi\TreeTransform\Tree;

require __DIR__ . '/../vendor/autoload.php';

$printTree = require __DIR__ . '/support/print-tree.php';

$pages = [
    ['id' => 1, 'title' => 'Home', 'status' => 'published', 'children' => [
        ['id' => 2, 'title' => 'Products', 'status' => 'published', 'children' => [
            ['id' => 3, 'title' => 'Laptops', 'status' => 'draft'],
            ['id' => 4, 'title' => 'Phones', 'status' => 'published'],
        ]],
        ['id' => 5, 'title' => 'Blog', 'status' => 'published', 'children' => [
            ['id' => 6, 'title' => 'Old news', 'status' => 'archived'],
        ]],
        ['id' => 7, 'title' => 'Contact', 'status' => 'published'],
    ]],
];
$tree = Tree::fromNestedArray($pages);
$title = fn(array $page) => $page['title'];

echo "FieldEquals('status', 'published'):\n";
echo $printTree($tree->filter(new FieldEquals('status', 'published')), $title);

echo "\nFieldIn('id', [4, 6]) with KeepAncestors:\n";
echo $printTree($tree->filter(new FieldIn('id', [4, 6]), new KeepAncestors()), $title);

echo "\nAllOf(published, DepthRange(0, 1)):\n";
echo $printTree($tree->filter(new AllOf(new FieldEquals('status', 'published'), new DepthRange(0, 1))), $title);

echo "\nNot(FieldEquals('status', 'archived')):\n";
echo $printTree($tree->filter(new Not(new FieldEquals('status', 'archived'))), $title);

echo "\nAnyOf(closure, FieldEquals) with KeepAncestors:\n";
echo $printTree($tree->filter(
    new AnyOf(fn(array $page) => str_starts_with($page['title'], 'Lap'), new FieldEquals('title', 'Contact')),
    new KeepAncestors(),
), $title);
