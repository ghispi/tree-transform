<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Source;

use Ghispi\TreeTransform\Cursor\Cursor;
use Ghispi\TreeTransform\Exception\InvalidTreeException;
use Ghispi\TreeTransform\Internal\Rows;

/**
 * @internal created by NestedArraySource
 */
final class NestedArrayCursor implements Cursor
{
    /** @var array<int, \Iterator<mixed>> iterators positioned at the next sibling to visit, indexed by depth */
    private array $stack = [];

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
            $roots = Rows::iterator($this->roots);
            $roots->rewind();
            $this->stack[0] = $roots;
            $this->size = 1;
        } elseif ($this->hasCurrent && !$this->skip) {
            $children = ($this->children)($this->node);
            if ($children !== null && $children !== []) {
                if (!is_iterable($children)) {
                    throw InvalidTreeException::at(null, \sprintf('field "%s" must be iterable or null, got %s.', $this->childrenField, get_debug_type($children)));
                }
                $iterator = Rows::iterator($children);
                $iterator->rewind();
                $this->stack[$this->size++] = $iterator;
            }
        }
        $this->skip = false;

        while ($this->size > 0) {
            $iterator = $this->stack[$this->size - 1];
            if ($iterator->valid()) {
                $this->node = $iterator->current();
                $iterator->next();
                $this->depth = $this->size - 1;
                $this->hasCurrent = true;

                return true;
            }
            unset($this->stack[--$this->size]);
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
}
