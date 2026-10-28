<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Stage;

use Ghispi\TreeTransform\Cursor\Cursor;

/**
 * Transforms each node's payload: fn (mixed $node, int $depth): mixed.
 *
 * Put it after filters so it only runs for nodes that are kept. The structure is unchanged; children are attached
 * by the output, so the callback returns the node without children.
 */
final readonly class Map implements Stage
{
    /**
     * @param \Closure(mixed, int): mixed $transform
     */
    public function __construct(private \Closure $transform) {}

    public function apply(Cursor $input): Cursor
    {
        return new MapCursor($input, $this->transform);
    }
}
