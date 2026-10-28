<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Benchmarks\Prototype;

use Ghispi\TreeTransform\Cursor\Cursor;
use Ghispi\TreeTransform\Exception\InvalidTreeException;
use Ghispi\TreeTransform\Internal\Rows;

/**
 * Phase 0 prototype (a), step 3: InlineIntNestedSetCursor reading a list by position ($rows[$i]) instead of going
 * through ArrayIterator::valid()/current()/next() — three internal method calls per row fewer.
 *
 * Only valid for array_is_list() input; a real implementation would pick this cursor in NestedSetSource::open()
 * when the rows are a list and the accessor is ArrayKeyAccessor/AutoAccessor.
 */
final class ListNestedSetCursor implements Cursor
{
    private int $position = -1;

    private readonly int $count;

    private mixed $node = null;

    private int $depth = 0;

    private int $currentRight = 0;

    /** @var array<int, int> */
    private array $rights = [];

    private int $open = 0;

    private int $lastLeft = \PHP_INT_MIN;

    private mixed $lastRoot = null;

    private bool $hasRoot = false;

    private int $skipBefore = \PHP_INT_MIN;

    /**
     * @param list<array<string, mixed>> $rows
     */
    public function __construct(
        private readonly array $rows,
        private readonly string $leftKey,
        private readonly string $rightKey,
        private readonly ?string $rootKey,
    ) {
        $this->count = \count($rows);
    }

    public function next(): bool
    {
        while (++$this->position < $this->count) {
            $row = $this->rows[$this->position];

            if ($this->rootKey !== null) {
                $root = $row[$this->rootKey] ?? null;
                if (!$this->hasRoot || $root !== $this->lastRoot) {
                    $this->hasRoot = true;
                    $this->lastRoot = $root;
                    $this->open = 0;
                    $this->lastLeft = \PHP_INT_MIN;
                    $this->skipBefore = \PHP_INT_MIN;
                }
            }

            $left = $row[$this->leftKey] ?? null;
            if (!\is_int($left)) {
                $left = Rows::int($left, $this->leftKey, $row);
            }
            if ($left < $this->skipBefore) {
                continue;
            }
            $right = $row[$this->rightKey] ?? null;
            if (!\is_int($right)) {
                $right = Rows::int($right, $this->rightKey, $row);
            }

            if ($left >= $right) {
                throw InvalidTreeException::at(null, \sprintf('left value %d is not lower than right value %d.', $left, $right));
            }
            if ($left <= $this->lastLeft) {
                throw InvalidTreeException::at(null, \sprintf('left value %d does not increase (previous %d).', $left, $this->lastLeft));
            }
            while ($this->open > 0 && $this->rights[$this->open - 1] < $left) {
                --$this->open;
            }
            if ($this->open > 0 && $right > $this->rights[$this->open - 1]) {
                throw InvalidTreeException::at(null, \sprintf('interval %d..%d overlaps its parent.', $left, $right));
            }

            $this->depth = $this->open;
            $this->rights[$this->open++] = $right;
            $this->lastLeft = $left;
            $this->currentRight = $right;
            $this->node = $row;

            return true;
        }
        $this->position = $this->count;
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
        $this->skipBefore = $this->currentRight;
    }
}
