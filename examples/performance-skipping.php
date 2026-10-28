<?php

declare(strict_types=1);

use Ghispi\TreeTransform\Strategy\KeepAncestors;
use Ghispi\TreeTransform\Strategy\Prune;
use Ghispi\TreeTransform\Tree;

require __DIR__ . '/../vendor/autoload.php';

$rows = require __DIR__ . '/support/shop.php';

// Count how often the predicate runs: Prune never tests nodes inside a removed subtree.
$calls = 0;
$notClothing = function (array $c) use (&$calls): bool {
    ++$calls;

    return $c['slug'] !== 'clothing';
};

foreach (['Prune' => new Prune(), 'KeepAncestors' => new KeepAncestors()] as $name => $strategy) {
    $calls = 0;
    $kept = Tree::fromNestedSet($rows)->filter($notClothing, $strategy)->count();
    printf("%-14s kept %2d of %d nodes, predicate called %2d times\n", $name, $kept, count($rows), $calls);
}

// maxDepth() and filters skip work too: map() only runs for nodes that survive.
$mapped = 0;
Tree::fromNestedSet($rows)->maxDepth(1)->map(function (array $c) use (&$mapped) {
    ++$mapped;

    return $c;
})->count();
echo "map() after maxDepth(1) ran {$mapped} times\n";
