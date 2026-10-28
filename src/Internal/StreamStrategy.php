<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Internal;

use Ghispi\TreeTransform\Predicate\Predicate;

/**
 * A built-in FilterStrategy that filters a stream directly (see Stream).
 *
 * @internal
 */
interface StreamStrategy
{
    /**
     * @param \Generator<int, mixed, bool|null, mixed> $input
     *
     * @return \Generator<int, mixed, bool|null, mixed>
     */
    public function stream(\Generator $input, Predicate $predicate): \Generator;
}
