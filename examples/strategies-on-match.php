<?php

declare(strict_types=1);

use Ghispi\TreeTransform\Strategy\KeepAncestors;
use Ghispi\TreeTransform\Strategy\OnMatch;
use Ghispi\TreeTransform\Strategy\Promote;
use Ghispi\TreeTransform\Strategy\Prune;
use Ghispi\TreeTransform\Tree;

require __DIR__ . '/../vendor/autoload.php';

$letters = require __DIR__ . '/support/letters.php';

// Prints a tree in one line: A[B[C, D[E]]], H[I]
$inline = function (Tree $tree): string {
    $out = '';
    $previous = -1;
    foreach ($tree as $depth => $node) {
        if ($depth > $previous) {
            $out .= $previous >= 0 ? '[' : '';
        } else {
            $out .= str_repeat(']', $previous - $depth) . ', ';
        }
        $out .= $node['name'];
        $previous = $depth;
    }

    return $out . str_repeat(']', max(0, $previous));
};

$tree = Tree::fromNestedArray($letters);
$matches = fn(array $n) => $n['match'];

foreach (['Prune' => Prune::class, 'KeepAncestors' => KeepAncestors::class, 'Promote' => Promote::class] as $name => $class) {
    foreach (OnMatch::cases() as $onMatch) {
        printf("%-14s + %-20s %s\n", $name, $onMatch->name, $inline($tree->filter($matches, new $class($onMatch))));
    }
}
