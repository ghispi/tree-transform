<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Strategy;

use Ghispi\TreeTransform\Cursor\Cursor;
use Ghispi\TreeTransform\Predicate\Predicate;

/**
 * A non-matching node is removed together with its whole subtree; its descendants are never tested.
 *
 *     A ✓                       A
 *     ├─ B ✗   (and C, D, E)    (B, F and their subtrees are cut,
 *     └─ F ✗   (and G)           H is cut with I although I matches)
 *     H ✗ ─ I ✓
 *
 * Streaming, O(depth) memory (O(1) with the default OnMatch). The cheapest strategy, and the default.
 */
final readonly class Prune implements FilterStrategy
{
    public function __construct(private OnMatch $onMatch = OnMatch::EvaluateDescendants) {}

    public function apply(Cursor $input, Predicate $predicate): Cursor
    {
        return new PruneCursor($input, $predicate, $this->onMatch);
    }
}
