<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Source;

use Ghispi\TreeTransform\Cursor\Cursor;

/**
 * @internal created by AdjacencyListSource
 */
final class AdjacencyListCursor implements Cursor
{
    /** @var array<int, list<int>> sibling lists being walked, indexed by depth */
    private array $lists = [];

    /** @var array<int, int> next position in each sibling list, indexed by depth */
    private array $positions = [];

    private int $size = 0;

    private bool $started = false;

    private int $current = -1;

    private bool $skip = false;

    private int $depth = 0;

    /**
     * @param array<int, mixed>     $nodes
     * @param list<int>             $roots
     * @param array<int, list<int>> $children
     */
    public function __construct(
        private readonly array $nodes,
        private readonly array $roots,
        private readonly array $children,
    ) {}

    public function next(): bool
    {
        if (!$this->started) {
            $this->started = true;
            $this->lists[0] = $this->roots;
            $this->positions[0] = 0;
            $this->size = 1;
        } elseif ($this->current >= 0 && !$this->skip && isset($this->children[$this->current])) {
            $this->lists[$this->size] = $this->children[$this->current];
            $this->positions[$this->size] = 0;
            ++$this->size;
        }
        $this->skip = false;

        while ($this->size > 0) {
            $level = $this->size - 1;
            $position = $this->positions[$level];
            if (isset($this->lists[$level][$position])) {
                $this->current = $this->lists[$level][$position];
                $this->positions[$level] = $position + 1;
                $this->depth = $level;

                return true;
            }
            unset($this->lists[$level]);
            --$this->size;
        }
        $this->current = -1;

        return false;
    }

    public function node(): mixed
    {
        return $this->current >= 0 ? $this->nodes[$this->current] : null;
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
