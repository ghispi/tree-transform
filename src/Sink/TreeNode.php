<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Sink;

/**
 * A node with its children, used by NestedArraySink for object payloads (entities, DTOs), which are never modified.
 *
 * @implements \IteratorAggregate<int, mixed>
 */
final readonly class TreeNode implements \IteratorAggregate, \Countable
{
    /**
     * @param mixed       $node     the original payload
     * @param list<mixed> $children child nodes (TreeNode, or whatever the node factory returned)
     */
    public function __construct(
        public mixed $node,
        public array $children = [],
    ) {}

    public function isLeaf(): bool
    {
        return $this->children === [];
    }

    /**
     * Number of direct children.
     */
    public function count(): int
    {
        return \count($this->children);
    }

    /**
     * Iterates the direct children.
     *
     * @return \ArrayIterator<int, mixed>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->children);
    }
}
