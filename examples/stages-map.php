<?php

declare(strict_types=1);

use Ghispi\TreeTransform\Tree;

require __DIR__ . '/../vendor/autoload.php';

$rows = require __DIR__ . '/support/shop.php';

$calls = 0;
$menu = Tree::fromNestedSet($rows)
    ->maxDepth(1)
    ->map(function (array $category, int $depth) use (&$calls): array {
        ++$calls;

        return ['label' => $category['title'], 'url' => '/c/' . $category['slug'], 'level' => $depth];
    })
    ->toNestedArray(omitEmptyChildren: true);

print_r($menu);
echo "map() called {$calls} times for ", count($rows), " rows\n";
