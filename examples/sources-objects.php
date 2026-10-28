<?php

declare(strict_types=1);

use Ghispi\TreeTransform\Accessor\PrivatePropertyAccessor;
use Ghispi\TreeTransform\Tree;

require __DIR__ . '/../vendor/autoload.php';

$printTree = require __DIR__ . '/support/print-tree.php';

// An entity with private tree fields and a getter for the title.
final class Category
{
    public function __construct(
        private string $title,
        private int $lft,
        private int $rgt,
    ) {}

    public function getTitle(): string
    {
        return $this->title;
    }
}

$categories = [
    new Category('Garden', 1, 8),
    new Category('Tools', 2, 5),
    new Category('Shovels', 3, 4),
    new Category('Plants', 6, 7),
];

// The default AutoAccessor finds getters, public properties and, as a last resort, private properties.
echo $printTree(Tree::fromNestedSet($categories), fn(Category $c) => $c->getTitle());

// PrivatePropertyAccessor reads private properties directly, without getters (fast; used for Doctrine entities).
$tree = Tree::fromNestedSet($categories, accessor: new PrivatePropertyAccessor(Category::class));
echo "\n", $tree->count(), " categories\n";
