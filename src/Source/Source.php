<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Source;

use Ghispi\TreeTransform\Cursor\Cursor;

/**
 * A tree input that can be traversed in pre-order.
 */
interface Source
{
    /**
     * Starts a traversal. Can be called repeatedly when the input is re-iterable (arrays, IteratorAggregate);
     * one-shot inputs (generators, database iterators) can be opened once.
     */
    public function open(): Cursor;
}
