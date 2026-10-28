<?php

declare(strict_types=1);

use Ghispi\TreeTransform\Tree;

require __DIR__ . '/../vendor/autoload.php';

// The same category tree in four storage formats. Hidden: "Women" (and so its child "Dresses").

$nestedSet = [ // ORDER BY lft
    ['id' => 1, 'title' => 'Clothing', 'visible' => true,  'lft' => 1, 'rgt' => 8],
    ['id' => 2, 'title' => 'Men',      'visible' => true,  'lft' => 2, 'rgt' => 3],
    ['id' => 3, 'title' => 'Women',    'visible' => false, 'lft' => 4, 'rgt' => 7],
    ['id' => 4, 'title' => 'Dresses',  'visible' => true,  'lft' => 5, 'rgt' => 6],
];

$adjacencyList = [ // any order
    ['id' => 4, 'parent_id' => 3,    'title' => 'Dresses',  'visible' => true],
    ['id' => 1, 'parent_id' => null, 'title' => 'Clothing', 'visible' => true],
    ['id' => 2, 'parent_id' => 1,    'title' => 'Men',      'visible' => true],
    ['id' => 3, 'parent_id' => 1,    'title' => 'Women',    'visible' => false],
];

$materializedPath = [ // ORDER BY path
    ['id' => 1, 'path' => '1,',     'title' => 'Clothing', 'visible' => true],
    ['id' => 2, 'path' => '1,2,',   'title' => 'Men',      'visible' => true],
    ['id' => 3, 'path' => '1,3,',   'title' => 'Women',    'visible' => false],
    ['id' => 4, 'path' => '1,3,4,', 'title' => 'Dresses',  'visible' => true],
];

$nestedArray = [
    ['id' => 1, 'title' => 'Clothing', 'visible' => true, 'children' => [
        ['id' => 2, 'title' => 'Men', 'visible' => true],
        ['id' => 3, 'title' => 'Women', 'visible' => false, 'children' => [
            ['id' => 4, 'title' => 'Dresses', 'visible' => true],
        ]],
    ]],
];

$visible = fn(array $category) => $category['visible'];
$titles = fn(Tree $tree) => implode(', ', array_map(
    fn(array $entry) => $entry['node']['title'] . ' (depth ' . $entry['depth'] . ')',
    $tree->filter($visible)->toFlatList(),
));

echo 'nested set:        ', $titles(Tree::fromNestedSet($nestedSet)), "\n";
echo 'adjacency list:    ', $titles(Tree::fromAdjacencyList($adjacencyList)), "\n";
echo 'materialized path: ', $titles(Tree::fromMaterializedPath($materializedPath)), "\n";
echo 'nested array:      ', $titles(Tree::fromNestedArray($nestedArray)), "\n";
