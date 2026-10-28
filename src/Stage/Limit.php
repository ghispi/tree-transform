<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Stage;

use Ghispi\TreeTransform\Cursor\Cursor;
use Ghispi\TreeTransform\Exception\InvalidArgumentException;

/**
 * Stops after $count nodes (in pre-order, so the result is still a valid tree). Stops reading the input too —
 * with a database iterator, the remaining rows are never fetched.
 */
final readonly class Limit implements Stage
{
    public function __construct(private int $count)
    {
        if ($count < 0) {
            throw new InvalidArgumentException('Limit cannot be negative.');
        }
    }

    public function apply(Cursor $input): Cursor
    {
        return new LimitCursor($input, $this->count);
    }
}
