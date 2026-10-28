<?php

declare(strict_types=1);

use Ghispi\TreeTransform\Source\AdjacencyListFields;
use Ghispi\TreeTransform\Tree;

require __DIR__ . '/../vendor/autoload.php';

$printTree = require __DIR__ . '/support/print-tree.php';

// Gedmo nested set queried with its parent column, ordered by lft: already pre-order, so it streams.
// SELECT id, title, IDENTITY(parent) AS parent FROM category ORDER BY root, lft
$rows = (function (): Generator {
    yield ['id' => 1, 'parent' => null, 'title' => 'Shop'];
    yield ['id' => 2, 'parent' => 1,    'title' => 'Clothing'];
    yield ['id' => 3, 'parent' => 2,    'title' => 'Men'];
    yield ['id' => 4, 'parent' => 2,    'title' => 'Women'];
    yield ['id' => 5, 'parent' => 1,    'title' => 'Electronics'];
})();

echo $printTree(
    Tree::fromPreorderedAdjacencyList($rows, AdjacencyListFields::gedmo()),
    fn(array $row) => $row['title'],
);
