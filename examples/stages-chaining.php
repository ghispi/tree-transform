<?php

declare(strict_types=1);

use Ghispi\TreeTransform\Strategy\KeepAncestors;
use Ghispi\TreeTransform\Tree;

require __DIR__ . '/../vendor/autoload.php';

$rows = require __DIR__ . '/support/shop.php';
$printTree = require __DIR__ . '/support/print-tree.php';

// Stages run in the order they are added. Each filter() is its own stage with its own strategy.
$base = Tree::fromNestedSet($rows)
    ->filter(fn(array $c) => $c['slug'] !== 'garden'); // hide a whole section (Prune)

$search = $base
    ->filter(fn(array $c) => str_contains(strtolower($c['title']), 'es'), new KeepAncestors())
    ->depthWindow(1);

echo $printTree($search, fn(array $c) => $c['title']);

// Tree is immutable: $base is unchanged and can be reused.
echo "\n", $base->count(), " categories without Garden\n";
