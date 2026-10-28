<?php

declare(strict_types=1);

use Ghispi\TreeTransform\Tree;

require __DIR__ . '/../vendor/autoload.php';

$printTree = require __DIR__ . '/support/print-tree.php';

// Arrays from a config file or a JSON API. Children can be under any key, and may be missing or null.
$menu = [
    ['label' => 'Home'],
    ['label' => 'Products', 'items' => [
        ['label' => 'Laptops', 'items' => null],
        ['label' => 'Phones', 'items' => [
            ['label' => 'Android'],
            ['label' => 'iPhone'],
        ]],
    ]],
    ['label' => 'Contact', 'items' => []],
];

echo $printTree(Tree::fromNestedArray($menu, childrenField: 'items'), fn(array $item) => $item['label']);
