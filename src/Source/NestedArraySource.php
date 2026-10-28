<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Source;

use Ghispi\TreeTransform\Accessor\Accessor;
use Ghispi\TreeTransform\Accessor\AutoAccessor;
use Ghispi\TreeTransform\Cursor\Cursor;
use Ghispi\TreeTransform\Exception\InvalidTreeException;
use Ghispi\TreeTransform\Internal\GeneratorCursor;
use Ghispi\TreeTransform\Internal\Rows;
use Ghispi\TreeTransform\Internal\StreamSource;

/**
 * Reads nested structures: arrays with a children key, or objects with a children property/getter (e.g. Doctrine
 * collections). Children may be any iterable; a missing or null children field means a leaf.
 *
 * Traversal is iterative (no recursion limit). Memory: O(depth). skipSubtree() does not descend.
 */
final readonly class NestedArraySource implements Source, StreamSource
{
    /**
     * @param iterable<mixed> $roots
     */
    public function __construct(
        private iterable $roots,
        private string $children = 'children',
        private Accessor $accessor = new AutoAccessor(),
    ) {}

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
        return self::read(self::level($this->roots), $this->accessor->reader($this->children), $this->children);
    }

    /**
     * List arrays are walked by position; other iterables through an Iterator. (A stack of ArrayIterators would
     * make every push scan PHP's iterator table, which is O(depth) per node.)
     *
     * @param list<mixed>|\Iterator<mixed> $roots
     * @param \Closure(mixed): mixed       $readChildren
     *
     * @return \Generator<int, mixed, bool|null, void>
     */
    private static function read(array|\Iterator $roots, \Closure $readChildren, string $childrenField): \Generator
    {
        /** @var array<int, list<mixed>|\Iterator<mixed>> $stack sibling lists (or iterators) being walked, by depth */
        $stack = [$roots];
        /** @var array<int, int> $positions next position in each list (unused for iterators) */
        $positions = [0];
        $level = 0;
        while ($level >= 0) {
            $top = $stack[$level];
            if (\is_array($top)) {
                $position = $positions[$level];
                if ($position >= \count($top)) {
                    unset($stack[$level]);
                    --$level;
                    continue;
                }
                $node = $top[$position];
                $positions[$level] = $position + 1;
            } elseif ($top->valid()) {
                $node = $top->current();
                $top->next();
            } else {
                unset($stack[$level]);
                --$level;
                continue;
            }

            if ((yield $level => $node) === true) {
                continue;
            }
            $children = $readChildren($node);
            if ($children === null || $children === []) {
                continue;
            }
            if (!is_iterable($children)) {
                throw InvalidTreeException::at(null, \sprintf('field "%s" must be iterable or null, got %s.', $childrenField, get_debug_type($children)));
            }
            $stack[++$level] = self::level($children);
            $positions[$level] = 0;
        }
    }

    /**
     * @param iterable<mixed> $nodes
     *
     * @return list<mixed>|\Iterator<mixed>
     */
    private static function level(iterable $nodes): array|\Iterator
    {
        if (\is_array($nodes) && array_is_list($nodes)) {
            return $nodes;
        }
        $iterator = Rows::iterator($nodes);
        $iterator->rewind();

        return $iterator;
    }
}
