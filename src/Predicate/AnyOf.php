<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Predicate;

/**
 * Matches when at least one inner predicate matches (logical OR, short-circuits). An empty list never matches.
 */
final readonly class AnyOf implements Predicate
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

    /**
     * The inner predicates.
     *
     * @return list<Predicate>
     */
    public function predicates(): array
    {
        return $this->predicates;
    }

    public function matches(mixed $node, int $depth, Ancestors $ancestors): bool
    {
        foreach ($this->predicates as $predicate) {
            if ($predicate->matches($node, $depth, $ancestors)) {
                return true;
            }
        }

        return false;
    }
}
