<?php

declare(strict_types=1);

use Ghispi\TreeTransform\Strategy\KeepAncestors;
use Ghispi\TreeTransform\Tree;

require __DIR__ . '/../vendor/autoload.php';

$rows = require __DIR__ . '/support/shop.php';
$printTree = require __DIR__ . '/support/print-tree.php';

// Keep a category if it has products itself, or if any category below it does.
// KeepAncestors keeps the path to every category with products; empty branches disappear.
$nonEmpty = Tree::fromNestedSet($rows)
    ->filter(fn(array $c) => $c['products'] > 0, new KeepAncestors());

echo $printTree($nonEmpty, fn(array $c) => $c['title'] . ($c['products'] > 0 ? " ({$c['products']})" : ''));
