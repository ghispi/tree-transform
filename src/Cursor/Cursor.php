<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Cursor;

/**
 * Forward-only, pre-order traversal over a forest.
 *
 * Every source, strategy and stage is a cursor, so they compose freely. Typical use:
 *
 *     while ($cursor->next()) {
 *         $cursor->node();  // payload, unchanged
 *         $cursor->depth(); // 0 for roots
 *     }
 *
 * Contract:
 * - Nodes come in pre-order: a node is followed by its descendants, then by its next sibling.
 * - The first node has depth 0, and each next node has a depth of at most the previous depth + 1.
 * - node() and depth() are only meaningful after next() returned true.
 */
interface Cursor
{
    /**
     * Advance to the next node in pre-order.
     *
     * @return bool false when the traversal is exhausted
     */
    public function next(): bool;

    /**
     * Payload of the current node: an array row, an entity, a DTO, … — never modified by the library.
     */
    public function node(): mixed;

    /**
     * Depth of the current node; 0 for roots.
     */
    public function depth(): int;

    /**
     * Do not visit the descendants of the current node: the next call to next() moves past its subtree.
     *
     * Sources use this to avoid work (not descending into children, skipping rows by right value or path
     * prefix without reading them further). Calling it more than once for the same node has no further effect.
     */
    public function skipSubtree(): void;
}
