<?php

declare(strict_types=1);

use Ghispi\TreeTransform\Exception\InvalidTreeException;
use Ghispi\TreeTransform\Source\MaterializedPathFields;
use Ghispi\TreeTransform\Tree;

require __DIR__ . '/../vendor/autoload.php';

$printTree = require __DIR__ . '/support/print-tree.php';

// Site pages with slash-separated paths, ordered by path with a binary collation.
// SELECT * FROM page ORDER BY path COLLATE "C"
$pages = [
    ['path' => '/about',          'title' => 'About'],
    ['path' => '/about/team',     'title' => 'Team'],
    ['path' => '/blog',           'title' => 'Blog'],
    ['path' => '/blog/2025',      'title' => '2025'],
    ['path' => '/blog/2025/hello', 'title' => 'Hello world'],
    ['path' => '/blog/2026',      'title' => '2026'],
];

$tree = Tree::fromMaterializedPath($pages, new MaterializedPathFields(path: 'path', separator: '/'));
echo $printTree($tree, fn(array $page) => $page['title']);

// A subtree read on its own: WHERE path LIKE '/blog/%'. A row not below any open row starts a new root.
echo "\nOnly the blog posts:\n";
echo $printTree(
    Tree::fromMaterializedPath(array_slice($pages, 3), new MaterializedPathFields(separator: '/')),
    fn(array $page) => $page['title'],
);

// Below a root, a missing parent row is an error by default ...
$withGap = [
    ['path' => '/blog',            'title' => 'Blog'],
    ['path' => '/blog/2025/hello', 'title' => 'Hello world'], // "/blog/2025" is missing
];

try {
    Tree::fromMaterializedPath($withGap, new MaterializedPathFields(separator: '/'))->count();
} catch (InvalidTreeException $e) {
    echo "\n", $e->getMessage(), "\n";
}

// ... and with allowGaps the row attaches to its nearest present ancestor.
echo "\nallowGaps: true\n";
echo $printTree(
    Tree::fromMaterializedPath($withGap, new MaterializedPathFields(separator: '/'), allowGaps: true),
    fn(array $page) => $page['title'],
);
