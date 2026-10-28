<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Benchmarks\Prototype;

use Ghispi\TreeTransform\Cursor\Cursor;
use Ghispi\TreeTransform\Exception\InvalidTreeException;
use Ghispi\TreeTransform\Internal\Rows;

/**
 * Phase 0 prototype (a), step 2: InlineKeyNestedSetCursor plus an inline is_int() fast path, so Rows::int() (a
 * static call) only runs for numeric strings (PDO) and invalid values.
 */
final class InlineIntNestedSetCursor implements Cursor
{
    private bool $started = false;

    private bool $done = false;

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
     * @param \Iterator<mixed> $rows
     */
    public function __construct(
        private readonly \Iterator $rows,
        private readonly string $leftKey,
        private readonly string $rightKey,
        private readonly ?string $rootKey,
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
            /** @var array<string, mixed> $row */
            $row = $this->rows->current();

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
        $this->skipBefore = $this->currentRight;
    }
}
