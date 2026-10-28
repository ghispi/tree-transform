<?php

declare(strict_types=1);

use Ghispi\TreeTransform\Tree;

require __DIR__ . '/../vendor/autoload.php';

// Nested set rows, e.g. SELECT id, title, visible, lft, rgt FROM category ORDER BY lft
$rows = [
    ['id' => 1, 'title' => 'Shop',        'visible' => true,  'lft' => 1,  'rgt' => 12],
    ['id' => 2, 'title' => 'Clothing',    'visible' => true,  'lft' => 2,  'rgt' => 7],
    ['id' => 3, 'title' => 'Men',         'visible' => true,  'lft' => 3,  'rgt' => 4],
    ['id' => 4, 'title' => 'Women',       'visible' => false, 'lft' => 5,  'rgt' => 6],
    ['id' => 5, 'title' => 'Electronics', 'visible' => true,  'lft' => 8,  'rgt' => 11],
    ['id' => 6, 'title' => 'Phones',      'visible' => true,  'lft' => 9,  'rgt' => 10],
];

$menu = Tree::fromNestedSet($rows)
    ->filter(fn(array $category) => $category['visible'])
    ->map(fn(array $category) => ['title' => $category['title']])
    ->toNestedArray(omitEmptyChildren: true);

echo json_encode($menu, JSON_PRETTY_PRINT), "\n";
