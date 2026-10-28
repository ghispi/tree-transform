<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Predicate;

/**
 * Negates another predicate.
 */
final readonly class Not implements Predicate
{
    private Predicate $predicate;

    /**
     * @param Predicate|\Closure(mixed, int, Ancestors): bool $predicate
     */
    public function __construct(Predicate|\Closure $predicate)
    {
        $this->predicate = Callback::of($predicate);
    }

    public function matches(mixed $node, int $depth, Ancestors $ancestors): bool
    {
        return !$this->predicate->matches($node, $depth, $ancestors);
    }
}
