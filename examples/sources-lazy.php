<?php

declare(strict_types=1);

use Ghispi\TreeTransform\Exception\TreeTransformException;
use Ghispi\TreeTransform\Source\NestedSetSource;
use Ghispi\TreeTransform\Tree;

require __DIR__ . '/../vendor/autoload.php';

// Stands in for a database query that streams rows (e.g. Doctrine's $query->toIterable()).
$queries = 0;
$query = function () use (&$queries): Generator {
    ++$queries;
    yield from require __DIR__ . '/support/shop.php';
};

// A generator can be read once: a second output fails.
$once = Tree::fromNestedSet($query())->maxDepth(1);
echo $once->count(), " categories\n";

try {
    $once->toNestedArray();
} catch (TreeTransformException $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

// Tree::lazy() builds the source on every read, so the same Tree can produce several outputs.
$queries = 0;
$menu = Tree::lazy(fn() => new NestedSetSource($query()))->maxDepth(1);
echo "\n", $menu->count(), " categories\n";
echo count($menu->toNestedArray()), " root\n";
echo "Queries run: {$queries}\n";
