<?php

declare(strict_types=1);

use Ghispi\TreeTransform\Sink\TreeNode;
use Ghispi\TreeTransform\Tree;

require __DIR__ . '/../vendor/autoload.php';

final readonly class Page
{
    public function __construct(
        public string $title,
        public string $path,
    ) {}
}

$pages = [
    new Page('About', '/about'),
    new Page('Team', '/about/team'),
    new Page('Jobs', '/about/jobs'),
    new Page('Blog', '/blog'),
];

// Objects are never modified: each one is wrapped in a TreeNode with its children.
$roots = Tree::fromMaterializedPath($pages, new Ghispi\TreeTransform\Source\MaterializedPathFields(separator: '/'))
    ->toNestedArray();

foreach ($roots as $root) {
    assert($root instanceof TreeNode);
    echo $root->node->title, ' (', count($root), ' children, leaf: ', var_export($root->isLeaf(), true), ")\n";
    foreach ($root as $child) { // iterates the direct children
        assert($child instanceof TreeNode);
        echo '  ', $child->node->title, "\n";
    }
}
