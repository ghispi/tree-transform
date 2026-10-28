<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Stage;

use Ghispi\TreeTransform\Cursor\Cursor;
use Ghispi\TreeTransform\Exception\InvalidArgumentException;

/**
 * Cuts everything deeper than $max (roots are depth 0, so MaxDepth(0) keeps only roots). The source skips the cut
 * subtrees without reading them further.
 */
final readonly class MaxDepth implements Stage
{
    public function __construct(private int $max)
    {
        if ($max < 0) {
            throw new InvalidArgumentException('Maximum depth cannot be negative.');
        }
    }

    public function apply(Cursor $input): Cursor
    {
        return new DepthWindowCursor($input, 0, $this->max);
    }
}
