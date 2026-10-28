<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Tests\Fixtures\User;

use Ghispi\TreeTransform\Cursor\Cursor;
use Ghispi\TreeTransform\Predicate\Predicate;
use Ghispi\TreeTransform\Strategy\FilterStrategy;
use Ghispi\TreeTransform\Strategy\Prune;

/**
 * A user-written strategy (not a StreamStrategy) that behaves like Prune.
 */
final class DelegatingPrune implements FilterStrategy
{
    public function apply(Cursor $input, Predicate $predicate): Cursor
    {
        return (new Prune())->apply($input, $predicate);
    }
}
