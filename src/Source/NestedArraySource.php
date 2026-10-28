<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Source;

use Ghispi\TreeTransform\Accessor\Accessor;
use Ghispi\TreeTransform\Accessor\AutoAccessor;
use Ghispi\TreeTransform\Cursor\Cursor;

/**
 * Reads nested structures: arrays with a children key, or objects with a children property/getter (e.g. Doctrine
 * collections). Children may be any iterable; a missing or null children field means a leaf.
 *
 * Traversal is iterative (no recursion limit). Memory: O(depth). skipSubtree() does not descend.
 */
final readonly class NestedArraySource implements Source
{
    /**
     * @param iterable<mixed> $roots
     */
    public function __construct(
        private iterable $roots,
        private string $children = 'children',
        private Accessor $accessor = new AutoAccessor(),
    ) {}

    public function open(): Cursor
    {
        return new NestedArrayCursor($this->roots, $this->accessor->reader($this->children), $this->children);
    }
}
