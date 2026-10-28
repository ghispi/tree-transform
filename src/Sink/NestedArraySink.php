<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Sink;

use Ghispi\TreeTransform\Cursor\Cursor;

/**
 * Builds nested arrays: a list of roots, each with its children.
 *
 * - array payloads get a children key: ['id' => 1, …, 'children' => [...]] (the input itself is not modified);
 * - object payloads are wrapped in TreeNode (entities are never modified);
 * - a $nodeFactory decides the shape itself: fn (mixed $node, list<mixed> $children, int $depth): mixed.
 *
 * Built bottom-up without recursion or references. Memory: the size of the result.
 *
 * @implements Sink<list<mixed>>
 */
final readonly class NestedArraySink implements Sink
{
    /**
     * @param string                                         $childrenKey       key for children of array payloads
     *                                                                          (use "__children" for Gedmo
     *                                                                          buildTree() compatible output)
     * @param bool                                           $omitEmptyChildren leave the children key out of leaves
     * @param \Closure|null $nodeFactory
     */
    public function __construct(
        private string $childrenKey = 'children',
        private bool $omitEmptyChildren = false,
        private ?\Closure $nodeFactory = null,
    ) {}

    /**
     * @return list<mixed>
     */
    public function consume(Cursor $cursor): array
    {
        $roots = [];
        /** @var array<int, mixed> $nodes */
        $nodes = [];
        /** @var array<int, list<mixed>> $children */
        $children = [];
        $top = -1;

        while ($cursor->next()) {
            $depth = $cursor->depth();
            while ($top >= $depth) {
                $built = $this->build($nodes[$top], $children[$top], $top);
                unset($nodes[$top], $children[$top]);
                if (--$top >= 0) {
                    $children[$top][] = $built;
                } else {
                    $roots[] = $built;
                }
            }
            $top = $depth;
            $nodes[$top] = $cursor->node();
            $children[$top] = [];
        }
        while ($top >= 0) {
            $built = $this->build($nodes[$top], $children[$top], $top);
            unset($nodes[$top], $children[$top]);
            if (--$top >= 0) {
                $children[$top][] = $built;
            } else {
                $roots[] = $built;
            }
        }

        return $roots;
    }

    /**
     * @param list<mixed> $children
     */
    private function build(mixed $node, array $children, int $depth): mixed
    {
        if ($this->nodeFactory !== null) {
            return ($this->nodeFactory)($node, $children, $depth);
        }
        if (\is_array($node)) {
            if ($children !== [] || !$this->omitEmptyChildren) {
                $node[$this->childrenKey] = $children;
            }

            return $node;
        }

        return new TreeNode($node, $children);
    }
}
