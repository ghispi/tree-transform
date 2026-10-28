<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Strategy;

use Ghispi\TreeTransform\Cursor\Cursor;
use Ghispi\TreeTransform\Predicate\Predicate;

/**
 * Keeps matching nodes together with their path to the root; non-matching ancestors stay as context.
 * The classic "search in a tree" result.
 *
 *     A ✓                       A
 *     ├─ B ✗                    ├─ B
 *     │  ├─ C ✓                 │  ├─ C
 *     │  └─ D ✗ ─ E ✓           │  └─ D ─ E
 *     └─ F ✗ ─ G ✗              H ─ I
 *     H ✗ ─ I ✓
 *
 * Streaming; buffers the not-yet-emitted ancestors of the current node: O(depth) memory.
 */
final readonly class KeepAncestors implements FilterStrategy
{
    public function __construct(private OnMatch $onMatch = OnMatch::EvaluateDescendants) {}

    public function apply(Cursor $input, Predicate $predicate): Cursor
    {
        return new KeepAncestorsCursor($input, $predicate, $this->onMatch);
    }
}
