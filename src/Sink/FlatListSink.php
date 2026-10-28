<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Sink;

use Ghispi\TreeTransform\Cursor\Cursor;

/**
 * Collects nodes in pre-order with their depth: [['depth' => 0, 'node' => …], …] — e.g. for an indented <select>.
 * For streaming output use Tree::iterate() instead.
 *
 * @implements Sink<list<array{depth: int, node: mixed}>>
 */
final readonly class FlatListSink implements Sink
{
    /**
     * @return list<array{depth: int, node: mixed}>
     */
    public function consume(Cursor $cursor): array
    {
        $out = [];
        while ($cursor->next()) {
            $out[] = ['depth' => $cursor->depth(), 'node' => $cursor->node()];
        }

        return $out;
    }
}
