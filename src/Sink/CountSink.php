<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Sink;

use Ghispi\TreeTransform\Cursor\Cursor;
use Ghispi\TreeTransform\Internal\Stream;
use Ghispi\TreeTransform\Internal\StreamSink;

/**
 * Counts nodes. Memory: O(1).
 *
 * @implements Sink<int>
 * @implements StreamSink<int>
 */
final readonly class CountSink implements Sink, StreamSink
{
    public function consume(Cursor $cursor): int
    {
        return $this->drain(Stream::of($cursor));
    }

    /**
     * @internal
     *
     * @param \Generator<int, mixed, bool|null, mixed> $input
     */
    public function drain(\Generator $input): int
    {
        return iterator_count($input);
    }
}
