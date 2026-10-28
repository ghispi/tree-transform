<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Predicate;

/**
 * Matches when every inner predicate matches (logical AND, short-circuits). An empty list always matches.
 */
final readonly class AllOf implements Predicate
{
    /** @var list<Predicate> */
    private array $predicates;

    /**
     * @param Predicate|\Closure ...$predicates
     */
    public function __construct(Predicate|\Closure ...$predicates)
    {
        $this->predicates = array_values(array_map(Callback::of(...), $predicates));
    }

    public function matches(mixed $node, int $depth, Ancestors $ancestors): bool
    {
        foreach ($this->predicates as $predicate) {
            if (!$predicate->matches($node, $depth, $ancestors)) {
                return false;
            }
        }

        return true;
    }
}
