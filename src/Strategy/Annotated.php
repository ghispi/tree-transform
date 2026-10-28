<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Strategy;

/**
 * Default output of the Annotate strategy: the original payload and its match state.
 */
final readonly class Annotated
{
    public function __construct(
        public mixed $node,
        public MatchState $state,
    ) {}
}
