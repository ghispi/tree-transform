<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Strategy;

use Ghispi\TreeTransform\Cursor\Cursor;
use Ghispi\TreeTransform\Exception\InvalidArgumentException;
use Ghispi\TreeTransform\Predicate\Predicate;

/**
 * Keeps a node when its subtree (the node itself included) contains at least $min matching nodes.
 * "Categories with at least 3 products", "folders with at least one large file".
 *
 * With $min = 1 this is KeepAncestors. Kept nodes always form a valid tree (ancestors of a kept node have at
 * least as many matches), and depths are unchanged. On the running example (C, E, I and A match) with $min = 2:
 *
 *     A (3)                     A
 *     ├─ B (2)                  └─ B
 *     │  ├─ C (1)
 *     │  └─ D (1) ─ E (1)
 *     H (1) ─ I (1)
 *
 * A node is emitted as soon as its count reaches $min; one that never does is known only when its subtree ends,
 * so output waits for it: memory up to the size of one undecided subtree.
 */
final readonly class MinMatches implements FilterStrategy
{
    public function __construct(private int $min)
    {
        if ($min < 1) {
            throw new InvalidArgumentException('MinMatches needs a minimum of at least 1.');
        }
    }

    public function apply(Cursor $input, Predicate $predicate): Cursor
    {
        return new MinMatchesCursor($input, $predicate, $this->min);
    }
}
