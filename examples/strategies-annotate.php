<?php

declare(strict_types=1);

use Ghispi\TreeTransform\Strategy\Annotate;
use Ghispi\TreeTransform\Strategy\Annotated;
use Ghispi\TreeTransform\Tree;

require __DIR__ . '/../vendor/autoload.php';

$letters = require __DIR__ . '/support/letters.php';
$printTree = require __DIR__ . '/support/print-tree.php';

// Same as ->filter($predicate, new Annotate()). Every node is kept and wrapped in an Annotated object.
$result = Tree::fromNestedArray($letters)
    ->annotate(fn(array $n) => $n['match']);

echo $printTree($result, fn(Annotated $a) => $a->node['name'] . '  ' . $a->state->name);
