<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Strategy;

use Ghispi\TreeTransform\Cursor\Cursor;
use Ghispi\TreeTransform\Predicate\Predicate;

/**
 * Decides what happens to nodes, their subtrees and their ancestors depending on a predicate.
 */
interface FilterStrategy
{
    /**
     * Returns a cursor over the filtered tree. Implementations must call the predicate at most once per node and
     * should call skipSubtree() on the input whenever the outcome of a whole subtree is already known.
     */
    public function apply(Cursor $input, Predicate $predicate): Cursor;
}
