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
     * @param Predicate|\Closure $predicate
     */
    public function __construct(Predicate|\Closure $predicate)
    {
        $this->predicate = Callback::of($predicate);
    }

    /**
     * @internal used to compile predicates; not covered by the BC promise
     *
     * The negated predicate.
     */
    public function inner(): Predicate
    {
        return $this->predicate;
    }

    public function matches(mixed $node, int $depth, Ancestors $ancestors): bool
    {
        return !$this->predicate->matches($node, $depth, $ancestors);
    }
}
