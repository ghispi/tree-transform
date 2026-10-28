<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Source;

use Ghispi\TreeTransform\Cursor\Cursor;
use Ghispi\TreeTransform\Exception\InvalidTreeException;
use Ghispi\TreeTransform\Internal\GeneratorCursor;
use Ghispi\TreeTransform\Internal\Rows;
use Ghispi\TreeTransform\Internal\StreamSource;

/**
 * Reads a pre-order list of (depth, node) entries: the interchange format of this library.
 *
 * - DepthListSource::pairs([[0, $a], [1, $b]]) reads [depth, node] pairs;
 * - DepthListSource::keyed($generator) reads depth => node keys, e.g. the output of Tree::iterate().
 *
 * Depths are validated (first 0, then at most previous + 1). Memory: O(1).
 */
final readonly class DepthListSource implements Source, StreamSource
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
        return new GeneratorCursor($this->stream());
    }

    /**
     * @internal
     *
     * @return \Generator<int, mixed, bool|null, void>
     */
    public function stream(): \Generator
    {
        return self::read(Rows::each($this->entries), $this->keyed);
    }

    /**
     * @param iterable<mixed> $entries
     *
     * @return \Generator<int, mixed, bool|null, void>
     */
    private static function read(iterable $entries, bool $keyed): \Generator
    {
        $previous = -1;
        $skipBelow = -1;
        foreach ($entries as $key => $entry) {
            if ($keyed) {
                $depth = $key;
                $node = $entry;
            } else {
                if (!\is_array($entry) || !\array_key_exists(0, $entry) || !\array_key_exists(1, $entry)) {
                    throw InvalidTreeException::at(null, 'entries must be [depth, node] pairs.');
                }
                [$depth, $node] = $entry;
            }
            if (!\is_int($depth) || $depth < 0 || $depth > $previous + 1) {
                throw InvalidTreeException::at(null, \sprintf(
                    'depth %s cannot follow depth %d (expected 0..%d).',
                    var_export($depth, true),
                    $previous,
                    $previous + 1,
                ));
            }
            $previous = $depth;
            if ($skipBelow >= 0) {
                if ($depth > $skipBelow) {
                    continue;
                }
                $skipBelow = -1;
            }
            if ((yield $depth => $node) === true) {
                $skipBelow = $depth;
            }
        }
    }
}
