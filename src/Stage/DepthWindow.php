<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Stage;

use Ghispi\TreeTransform\Cursor\Cursor;
use Ghispi\TreeTransform\Exception\InvalidArgumentException;

/**
 * Keeps only depths $min..$max (inclusive). Nodes at $min become roots; everything below $max is cut, and the
 * source skips it without reading further.
 *
 * new DepthWindow(1, 2) on "Shop ─ Clothing ─ Men ─ Shirts" gives "Clothing ─ Men": a menu without the site root.
 */
final readonly class DepthWindow implements Stage
{
    public function __construct(private int $min = 0, private int $max = \PHP_INT_MAX)
    {
        if ($min < 0 || $max < $min) {
            throw new InvalidArgumentException(\sprintf('Invalid depth window %d..%d.', $min, $max));
        }
    }

    public function apply(Cursor $input): Cursor
    {
        return new DepthWindowCursor($input, $this->min, $this->max);
    }
}
