<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Predicate;

use Ghispi\TreeTransform\Exception\InvalidArgumentException;

/**
 * Matches nodes whose depth lies between $min and $max (both inclusive; roots have depth 0).
 */
final readonly class DepthRange implements Predicate
{
    public function __construct(private int $min = 0, private int $max = \PHP_INT_MAX)
    {
        if ($min < 0 || $max < $min) {
            throw new InvalidArgumentException(\sprintf('Invalid depth range %d..%d.', $min, $max));
        }
    }

    public function matches(mixed $node, int $depth, Ancestors $ancestors): bool
    {
        return $depth >= $this->min && $depth <= $this->max;
    }
}
