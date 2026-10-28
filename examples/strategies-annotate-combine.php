<?php

declare(strict_types=1);

use Ghispi\TreeTransform\Strategy\MatchState;
use Ghispi\TreeTransform\Tree;

require __DIR__ . '/../vendor/autoload.php';

$letters = require __DIR__ . '/support/letters.php';

// Merge the state into the array row instead of wrapping it.
$rows = Tree::fromNestedArray($letters)
    ->annotate(
        fn(array $n) => $n['match'],
        fn(array $n, MatchState $state) => ['name' => $n['name'], 'state' => $state->value],
    )
    ->toFlatList();

foreach ($rows as ['depth' => $depth, 'node' => $node]) {
    echo str_repeat('  ', $depth), $node['name'], ' => ', $node['state'], "\n";
}
