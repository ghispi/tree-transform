<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Stage;

use Ghispi\TreeTransform\Cursor\Cursor;
use Ghispi\TreeTransform\Internal\Stream;
use Ghispi\TreeTransform\Internal\StreamStage;
use Ghispi\TreeTransform\Predicate\Callback;
use Ghispi\TreeTransform\Predicate\Predicate;
use Ghispi\TreeTransform\Strategy\FilterStrategy;
use Ghispi\TreeTransform\Strategy\Prune;

/**
 * Applies a filter strategy with a predicate (default strategy: Prune).
 */
final readonly class Filter implements Stage, StreamStage
{
    private Predicate $predicate;

    /**
     * @param Predicate|\Closure $predicate
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

    /**
     * @internal
     *
     * @param \Generator<int, mixed, bool|null, mixed> $input
     *
     * @return \Generator<int, mixed, bool|null, mixed>
     */
    public function stream(\Generator $input): \Generator
    {
        return Stream::filter($this->strategy, $input, $this->predicate);
    }
}
