<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Strategy;

use Ghispi\TreeTransform\Cursor\Cursor;
use Ghispi\TreeTransform\Exception\InvalidArgumentException;
use Ghispi\TreeTransform\Predicate\Predicate;

/**
 * Navigation menu expanded down to the active node(s): the predicate identifies the active node.
 *
 * Kept: all roots, every child of an ancestor of an active node (the trail and its siblings), the active node, and
 * $activeChildrenDepth levels below it. Example with active = E:
 *
 *     A                         A
 *     ├─ B                      ├─ B
 *     │  ├─ C                   │  ├─ C
 *     │  └─ D ─ E (active)      │  └─ D ─ E
 *     └─ F ─ G                  └─ F            (F's children collapsed)
 *     H ─ I                     H               (H collapsed)
 *
 * With $siblings = false only the trail itself is kept (a breadcrumb): A ─ B ─ D ─ E.
 *
 * Streaming. While it is unknown whether a node is on the trail, its direct children are buffered:
 * O(sum of child counts along the current path) memory, typically depth × branching.
 */
final readonly class ActiveTrail implements FilterStrategy
{
    /**
     * @param bool $siblings            keep the siblings of trail nodes (a menu); false keeps only the trail
     * @param int  $activeChildrenDepth levels shown below the active node: 0 none, 1 its children, PHP_INT_MAX all
     * @param bool $multiple            allow several active nodes (union of trails); false stops testing after
     *                                  the first match
     */
    public function __construct(
        private bool $siblings = true,
        private int $activeChildrenDepth = 1,
        private bool $multiple = true,
    ) {
        if ($activeChildrenDepth < 0) {
            throw new InvalidArgumentException('activeChildrenDepth cannot be negative.');
        }
    }

    public function apply(Cursor $input, Predicate $predicate): Cursor
    {
        return new ActiveTrailCursor($input, $predicate, $this->siblings, $this->activeChildrenDepth, $this->multiple);
    }
}
