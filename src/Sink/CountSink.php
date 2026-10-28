<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Sink;

use Ghispi\TreeTransform\Cursor\Cursor;

/**
 * Counts nodes. Memory: O(1).
 *
 * @implements Sink<int>
 */
final readonly class CountSink implements Sink
{
    public function consume(Cursor $cursor): int
    {
        $count = 0;
        while ($cursor->next()) {
            ++$count;
        }

        return $count;
    }
}
