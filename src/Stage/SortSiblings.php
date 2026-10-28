<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Stage;

use Ghispi\TreeTransform\Cursor\Cursor;
use Ghispi\TreeTransform\Internal\GeneratorCursor;
use Ghispi\TreeTransform\Internal\OffsetTree;
use Ghispi\TreeTransform\Internal\Stream;
use Ghispi\TreeTransform\Internal\StreamStage;

/**
 * Reorders the children of every node (and the roots) with a comparator: fn (mixed $a, mixed $b): int.
 * Subtrees move with their root. Sorting is stable.
 *
 * Reads the whole input first: O(n) memory. Use the source's ordering (ORDER BY) when you can.
 */
final readonly class SortSiblings implements Stage, StreamStage
{
    /**
     * @param \Closure $compare
     */
    public function __construct(private \Closure $compare) {}

    public function apply(Cursor $input): Cursor
    {
        return new GeneratorCursor($this->stream(Stream::of($input)));
    }

    /**
     * @internal
     *
     * @param \Generator<int, mixed, bool|null, mixed> $input
     *
     * @return \Generator<int, mixed, bool|null, void>
     */
    public function stream(\Generator $input): \Generator
    {
        return self::sort($input, $this->compare);
    }

    /**
     * Reads the whole input into an offset index, sorts every sibling list, then streams the index.
     *
     * @param \Generator<int, mixed, bool|null, mixed> $input
     * @param \Closure(mixed, mixed): int              $compare
     *
     * @return \Generator<int, mixed, bool|null, void>
     */
    private static function sort(\Generator $input, \Closure $compare): \Generator
    {
        $nodes = [];
        $roots = [];
        /** @var array<int, list<int>> $children */
        $children = [];
        /** @var array<int, int> $open offsets of open ancestors, indexed by depth */
        $open = [];
        $offset = 0;
        foreach ($input as $depth => $node) {
            $nodes[$offset] = $node;
            if ($depth === 0) {
                $roots[] = $offset;
            } else {
                $children[$open[$depth - 1]][] = $offset;
            }
            $open[$depth] = $offset;
            ++$offset;
        }
        $byNode = static fn(int $a, int $b): int => $compare($nodes[$a], $nodes[$b]);
        usort($roots, $byNode);
        foreach ($children as &$list) {
            usort($list, $byNode);
        }
        unset($list);

        yield from OffsetTree::stream($nodes, $roots, $children);
    }
}
