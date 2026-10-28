<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Tests\Fixtures\User;

use Ghispi\TreeTransform\Cursor\Cursor;
use Ghispi\TreeTransform\Sink\Sink;

/**
 * A user-written sink (not a StreamSink): returns [depth, node] pairs, optionally skipping subtrees below a depth.
 *
 * @implements Sink<list<array{0: int, 1: mixed}>>
 */
final class ListingSink implements Sink
{
    public function __construct(private readonly ?int $skipBelow = null) {}

    public function consume(Cursor $cursor): array
    {
        $out = [];
        while ($cursor->next()) {
            $out[] = [$cursor->depth(), $cursor->node()];
            if ($this->skipBelow !== null && $cursor->depth() >= $this->skipBelow) {
                $cursor->skipSubtree();
            }
        }

        return $out;
    }
}
