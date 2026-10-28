<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Sink;

use Ghispi\TreeTransform\Cursor\Cursor;
use Ghispi\TreeTransform\Internal\Stream;
use Ghispi\TreeTransform\Internal\StreamSink;

/**
 * Collects nodes in pre-order with their depth: [['depth' => 0, 'node' => …], …] — e.g. for an indented <select>.
 * For streaming output use Tree::iterate() instead.
 *
 * @implements Sink<list<array{depth: int, node: mixed}>>
 * @implements StreamSink<list<array{depth: int, node: mixed}>>
 */
final readonly class FlatListSink implements Sink, StreamSink
{
    /**
     * @return list<array{depth: int, node: mixed}>
     */
    public function consume(Cursor $cursor): array
    {
        return $this->drain(Stream::of($cursor));
    }

    /**
     * @internal
     *
     * @param \Generator<int, mixed, bool|null, mixed> $input
     *
     * @return list<array{depth: int, node: mixed}>
     */
    public function drain(\Generator $input): array
    {
        $out = [];
        foreach ($input as $depth => $node) {
            $out[] = ['depth' => $depth, 'node' => $node];
        }

        return $out;
    }
}
