<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Stage;

use Ghispi\TreeTransform\Cursor\Cursor;

/**
 * Reorders the children of every node (and the roots) with a comparator: fn (mixed $a, mixed $b): int.
 * Subtrees move with their root. Sorting is stable.
 *
 * Reads the whole input first: O(n) memory. Use the source's ordering (ORDER BY) when you can.
 */
final readonly class SortSiblings implements Stage
{
    /**
     * @param \Closure(mixed, mixed): int $compare
     */
    public function __construct(private \Closure $compare) {}

    public function apply(Cursor $input): Cursor
    {
        return new SortSiblingsCursor($input, $this->compare);
    }
}
