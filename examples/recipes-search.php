<?php

declare(strict_types=1);

use Ghispi\TreeTransform\Strategy\KeepAncestors;
use Ghispi\TreeTransform\Strategy\OnMatch;
use Ghispi\TreeTransform\Tree;

require __DIR__ . '/../vendor/autoload.php';

$rows = require __DIR__ . '/support/shop.php';
$printTree = require __DIR__ . '/support/print-tree.php';

$query = 'sh';
$matches = fn(array $c) => mb_stripos($c['title'], $query) !== false;
$label = fn(array $c) => $matches($c) ? "[{$c['title']}]" : $c['title'];

// Matches with their path to the root, so the user sees where each hit lives.
echo "Search \"{$query}\":\n";
echo $printTree(Tree::fromNestedSet($rows)->filter($matches, new KeepAncestors()), $label);

// KeepDescendants also shows everything inside a matching category, without testing it.
echo "\nSearch \"phones\", with the contents of matches:\n";
echo $printTree(
    Tree::fromNestedSet($rows)->filter(
        fn(array $c) => mb_stripos($c['title'], 'phones') !== false,
        new KeepAncestors(OnMatch::KeepDescendants),
    ),
    fn(array $c) => $c['title'],
);
