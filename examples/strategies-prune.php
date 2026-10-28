<?php

declare(strict_types=1);

use Ghispi\TreeTransform\Strategy\Prune;
use Ghispi\TreeTransform\Tree;

require __DIR__ . '/../vendor/autoload.php';

$letters = require __DIR__ . '/support/letters.php';
$printTree = require __DIR__ . '/support/print-tree.php';

$tested = [];
$result = Tree::fromNestedArray($letters)
    ->filter(function (array $n) use (&$tested): bool {
        $tested[] = $n['name'];

        return $n['match'];
    }, new Prune()); // Prune is also the default: ->filter($predicate)

echo $printTree($result, fn(array $n) => $n['name']);
echo 'Predicate called for: ', implode(', ', $tested), "\n";
