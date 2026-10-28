<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Benchmarks\Prototype;

use Ghispi\TreeTransform\Cursor\Cursor;
use Ghispi\TreeTransform\Exception\InvalidTreeException;
use Ghispi\TreeTransform\Internal\Rows;

/**
 * Prototype: src/Source/NestedArrayCursor keeping (list, position) pairs on its stack for list children instead of
 * one ArrayIterator per open level. Other iterables (non-list arrays, collections, generators) still use an
 * Iterator, exactly as before.
 *
 * Why: every live ArrayIterator registers a HashTable iterator and zend_hash_iterator_add() scans the global
 * iterator table linearly, so with one iterator per level each descent costs O(depth) — O(n * depth) on a chain.
 * ArrayIterator also duplicates each children array it wraps (shallow copy).
 */
final class IndexedNestedArrayCursor implements Cursor
{
    /** @var array<int, list<mixed>|\Iterator<mixed>> sibling lists (or iterators) being walked, by depth */
    private array $stack = [];

    /** @var array<int, int> next position in each list (unused for iterators) */
    private array $positions = [];

    private int $size = 0;

    private bool $started = false;

    private bool $hasCurrent = false;

    private bool $skip = false;

    private mixed $node = null;

    private int $depth = 0;

    /**
     * @param iterable<mixed>        $roots
     * @param \Closure(mixed): mixed $children
     */
    public function __construct(
        private readonly iterable $roots,
        private readonly \Closure $children,
        private readonly string $childrenField,
    ) {}

    public function next(): bool
    {
        if (!$this->started) {
            $this->started = true;
            $this->push($this->roots);
        } elseif ($this->hasCurrent && !$this->skip) {
            $children = ($this->children)($this->node);
            if ($children !== null && $children !== []) {
                if (!is_iterable($children)) {
                    throw InvalidTreeException::at(null, \sprintf('field "%s" must be iterable or null, got %s.', $this->childrenField, get_debug_type($children)));
                }
                $this->push($children);
            }
        }
        $this->skip = false;

        while ($this->size > 0) {
            $level = $this->size - 1;
            $top = $this->stack[$level];
            if (\is_array($top)) {
                $position = $this->positions[$level];
                if ($position < \count($top)) {
                    $this->node = $top[$position];
                    $this->positions[$level] = $position + 1;
                    $this->depth = $level;
                    $this->hasCurrent = true;

                    return true;
                }
            } elseif ($top->valid()) {
                $this->node = $top->current();
                $top->next();
                $this->depth = $level;
                $this->hasCurrent = true;

                return true;
            }
            unset($this->stack[$level]);
            --$this->size;
        }
        $this->hasCurrent = false;
        $this->node = null;

        return false;
    }

    public function node(): mixed
    {
        return $this->node;
    }

    public function depth(): int
    {
        return $this->depth;
    }

    public function skipSubtree(): void
    {
        $this->skip = true;
    }

    /**
     * @param iterable<mixed> $children
     */
    private function push(iterable $children): void
    {
        if (\is_array($children) && array_is_list($children)) {
            $this->stack[$this->size] = $children;
            $this->positions[$this->size] = 0;
        } else {
            $iterator = Rows::iterator($children);
            $iterator->rewind();
            $this->stack[$this->size] = $iterator;
        }
        ++$this->size;
    }
}
