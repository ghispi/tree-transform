<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Stage;

use Ghispi\TreeTransform\Cursor\Cursor;
use Ghispi\TreeTransform\Predicate\Ancestors;
use Ghispi\TreeTransform\Predicate\Callback;
use Ghispi\TreeTransform\Predicate\Predicate;
use Ghispi\TreeTransform\Strategy\FilterStrategy;
use Ghispi\TreeTransform\Strategy\Prune;

/**
 * Applies a filter strategy with a predicate (default strategy: Prune).
 */
final readonly class Filter implements Stage
{
    private Predicate $predicate;

    /**
     * @param Predicate|\Closure(mixed, int, Ancestors): bool $predicate
     */
    public function __construct(
        Predicate|\Closure $predicate,
        private FilterStrategy $strategy = new Prune(),
    ) {
        $this->predicate = Callback::of($predicate);
    }

    public function apply(Cursor $input): Cursor
    {
        return $this->strategy->apply($input, $this->predicate);
    }
}
