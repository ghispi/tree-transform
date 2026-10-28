<?php

declare(strict_types=1);

use Ghispi\TreeTransform\Accessor\Accessor;
use Ghispi\TreeTransform\Source\NestedSetFields;
use Ghispi\TreeTransform\Tree;

require __DIR__ . '/../vendor/autoload.php';

$printTree = require __DIR__ . '/support/print-tree.php';

/** A model that keeps its columns in an attributes array, as many active-record libraries do. */
final readonly class Model
{
    /** @param array<string, mixed> $attributes */
    public function __construct(private array $attributes) {}

    public function getAttribute(string $name): mixed
    {
        return $this->attributes[$name] ?? null;
    }
}

/**
 * Reads fields with $model->getAttribute($field). reader() runs once per field; the returned closure runs per node.
 */
final readonly class AttributeAccessor implements Accessor
{
    public function reader(string $field): Closure
    {
        return static fn(mixed $node): mixed => $node instanceof Model ? $node->getAttribute($field) : null;
    }
}

$categories = [
    new Model(['title' => 'Books',   '_lft' => 1, '_rgt' => 6]),
    new Model(['title' => 'Fiction', '_lft' => 2, '_rgt' => 3]),
    new Model(['title' => 'Travel',  '_lft' => 4, '_rgt' => 5]),
];

$tree = Tree::fromNestedSet($categories, new NestedSetFields(left: '_lft', right: '_rgt'), new AttributeAccessor());
echo $printTree($tree, fn(Model $m) => $m->getAttribute('title'));
