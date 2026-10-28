<?php

declare(strict_types=1);

use Ghispi\TreeTransform\Source\DepthListSource;
use Ghispi\TreeTransform\Tree;

require __DIR__ . '/../vendor/autoload.php';

$printTree = require __DIR__ . '/support/print-tree.php';

// [depth, node] pairs, e.g. parsed from an indented outline.
$outline = DepthListSource::pairs([
    [0, 'Docs'],
    [1, 'Getting started'],
    [1, 'Guides'],
    [2, 'Menus'],
    [2, 'Search'],
    [0, 'Changelog'],
]);
echo $printTree(Tree::from($outline), fn(string $title) => $title);

// depth => node entries: the output of iterate(), so one pipeline can feed another.
$guides = Tree::from($outline)->depthWindow(1)->iterate();
echo "\n", $printTree(Tree::fromDepthList($guides), fn(string $title) => $title);
