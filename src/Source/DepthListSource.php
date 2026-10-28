<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Source;

use Ghispi\TreeTransform\Cursor\Cursor;
use Ghispi\TreeTransform\Internal\Rows;

/**
 * Reads a pre-order list of (depth, node) entries: the interchange format of this library.
 *
 * - new DepthListSource([[0, $a], [1, $b]]) reads [depth, node] pairs;
 * - DepthListSource::keyed($generator) reads depth => node keys, e.g. the output of Tree::iterate().
 *
 * Depths are validated (first 0, then at most previous + 1). Memory: O(1).
 */
final readonly class DepthListSource implements Source
{
    /**
     * @param iterable<mixed> $entries
     */
    private function __construct(private iterable $entries, private bool $keyed) {}

    /**
     * @param iterable<array{0: int, 1: mixed}> $pairs
     */
    public static function pairs(iterable $pairs): self
    {
        return new self($pairs, false);
    }

    /**
     * @param iterable<int, mixed> $entries depth => node (generators may repeat keys)
     */
    public static function keyed(iterable $entries): self
    {
        return new self($entries, true);
    }

    public function open(): Cursor
    {
        return new DepthListCursor(Rows::iterator($this->entries), $this->keyed);
    }
}
