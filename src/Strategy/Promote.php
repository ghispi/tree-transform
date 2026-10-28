<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Strategy;

use Ghispi\TreeTransform\Cursor\Cursor;
use Ghispi\TreeTransform\Predicate\Predicate;

/**
 * Removes non-matching nodes but keeps their matching descendants, which move up to the nearest kept ancestor
 * (or become roots). Also known as hoisting or collapsing.
 *
 *     A ✓                       A
 *     ├─ B ✗                    ├─ C
 *     │  ├─ C ✓                 └─ E
 *     │  └─ D ✗ ─ E ✓           I
 *     └─ F ✗ ─ G ✗
 *     H ✗ ─ I ✓
 *
 * Streaming, O(depth) memory. Output depths change; sinks recompute parents and nested set values.
 */
final readonly class Promote implements FilterStrategy
{
    public function __construct(private OnMatch $onMatch = OnMatch::EvaluateDescendants) {}

    public function apply(Cursor $input, Predicate $predicate): Cursor
    {
        return new PromoteCursor($input, $predicate, $this->onMatch);
    }
}
