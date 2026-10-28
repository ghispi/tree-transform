<?php

declare(strict_types=1);

use Ghispi\TreeTransform\Predicate\Ancestors;
use Ghispi\TreeTransform\Strategy\Promote;
use Ghispi\TreeTransform\Tree;

require __DIR__ . '/../vendor/autoload.php';

$printTree = require __DIR__ . '/support/print-tree.php';

$pages = [
    ['title' => 'Home', 'published' => true, 'children' => [
        ['title' => 'Blog', 'published' => true, 'children' => [
            ['title' => 'Drafts', 'published' => false, 'children' => [
                ['title' => 'Upcoming launch', 'published' => true],
            ]],
            ['title' => 'Hello world', 'published' => true],
        ]],
    ]],
];
$tree = Tree::fromNestedArray($pages);

// Pages under an unpublished section stay hidden, even if they are published themselves. Prune does this anyway;
// with Promote, "Upcoming launch" would otherwise move up under "Blog".
$visible = fn(array $page, int $depth, Ancestors $ancestors) => $page['published']
    && $ancestors->every(fn(array $parent) => $parent['published']);
echo $printTree($tree->filter($visible, new Promote()), fn(array $p) => $p['title']);

// Everything the view offers, for the "Upcoming launch" page.
$tree->filter(function (array $page, int $depth, Ancestors $ancestors): bool {
    if ($page['title'] === 'Upcoming launch') {
        echo "\ncount():  ", count($ancestors), " (= depth {$depth})\n";
        echo 'parent(): ', $ancestors->parent()['title'], "\n";
        echo 'at(0):    ', $ancestors->at(0)['title'], "\n";
        echo 'some():   ', var_export($ancestors->some(fn(array $p) => !$p['published']), true), "\n";
        echo 'every():  ', var_export($ancestors->every(fn(array $p) => $p['published']), true), "\n";
        echo 'toArray() titles: ', implode(' > ', array_column($ancestors->toArray(), 'title')), "\n";
        echo 'foreach:  ';
        foreach ($ancestors as $ancestorDepth => $ancestor) {
            echo "{$ancestorDepth}:{$ancestor['title']} ";
        }
        echo "\n";
    }

    return true;
})->count();
