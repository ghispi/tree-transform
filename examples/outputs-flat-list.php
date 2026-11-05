<?php

declare(strict_types=1);

use Ghispi\TreeTransform\Tree;

require __DIR__ . '/../vendor/autoload.php';

$rows = require __DIR__ . '/support/shop.php';

$list = Tree::fromNestedSet($rows)
    ->maxDepth(1)
    ->map(fn(array $c) => $c['title'])
    ->toFlatList();

var_export($list);
echo "\n";

// Tree::fromFlatList() reads the same shape back.
echo implode(' / ', iterator_to_array(Tree::fromFlatList($list)->depthWindow(1)->iterate(), false)), "\n";
