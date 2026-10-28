<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Source;

use Ghispi\TreeTransform\Cursor\Cursor;
use Ghispi\TreeTransform\Exception\InvalidTreeException;
use Ghispi\TreeTransform\Internal\Rows;

/**
 * @internal created by PreorderedAdjacencySource
 */
final class PreorderedAdjacencyCursor implements Cursor
{
    /** @var array<int, int|string> ids of open ancestors, indexed by depth */
    private array $ids = [];

    private int $open = 0;

    private bool $started = false;

    private bool $done = false;

    private mixed $node = null;

    private int $depth = 0;

    private int $skipBelow = -1;

    /**
     * @param \Iterator<mixed>       $rows
     * @param \Closure(mixed): mixed $id
     * @param \Closure(mixed): mixed $parent
     */
    public function __construct(
        private readonly \Iterator $rows,
        private readonly \Closure $id,
        private readonly \Closure $parent,
        private readonly int|string|null $rootParent,
    ) {}

    public function next(): bool
    {
        if ($this->done) {
            return false;
        }
        if ($this->started) {
            $this->rows->next();
        } else {
            $this->rows->rewind();
            $this->started = true;
        }

        for (; $this->rows->valid(); $this->rows->next()) {
            $row = $this->rows->current();
            $id = Rows::key(($this->id)($row));
            $parent = ($this->parent)($row);

            if ($parent === null || ($this->rootParent !== null && Rows::key($parent) === $this->rootParent)) {
                $this->open = 0;
            } else {
                $parentKey = Rows::key($parent);
                while ($this->open > 0 && $this->ids[$this->open - 1] !== $parentKey) {
                    --$this->open;
                }
                if ($this->open === 0) {
                    throw InvalidTreeException::at($id, \sprintf(
                        'parent %s is not an open ancestor. Rows must be in pre-order with parents before children.',
                        var_export($parentKey, true),
                    ));
                }
            }
            $depth = $this->open;
            $this->ids[$this->open++] = $id;

            if ($this->skipBelow >= 0) {
                if ($depth > $this->skipBelow) {
                    continue;
                }
                $this->skipBelow = -1;
            }

            $this->depth = $depth;
            $this->node = $row;

            return true;
        }

        $this->done = true;
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
        $this->skipBelow = $this->depth;
    }
}
