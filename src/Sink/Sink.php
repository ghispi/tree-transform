<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Sink;

use Ghispi\TreeTransform\Cursor\Cursor;

/**
 * Consumes a cursor and builds a result.
 *
 * @template-covariant TResult
 */
interface Sink
{
    /**
     * @return TResult
     */
    public function consume(Cursor $cursor): mixed;
}
