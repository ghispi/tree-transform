<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Strategy;

use Ghispi\TreeTransform\Cursor\Cursor;
use Ghispi\TreeTransform\Predicate\Predicate;

/**
 * Like Prune, but every run of consecutive removed siblings is replaced by one placeholder node, so readers can
 * see that something was hidden: fn (int $hidden, int $depth): mixed creates it, where $hidden is the number of
 * removed siblings in the run (their descendants are not counted).
 *
 *     A ✓                       A
 *     ├─ B ✗                    └─ "2 hidden"
 *     └─ F ✗                    "1 hidden"
 *     H ✗
 *
 * Streaming, O(depth) memory.
 */
final readonly class Placeholder implements FilterStrategy
{
    /**
     * @param \Closure $factory fn (int $hidden, int $depth): mixed
     */
    public function __construct(
        private \Closure $factory,
        private OnMatch $onMatch = OnMatch::EvaluateDescendants,
    ) {}

    public function apply(Cursor $input, Predicate $predicate): Cursor
    {
        return new PlaceholderCursor($input, $predicate, $this->factory, $this->onMatch);
    }
}
