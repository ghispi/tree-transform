<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Internal;

/**
 * Forward reading of source rows: list arrays by position (no copy, no iterator calls), anything else through an
 * Iterator.
 *
 * @internal
 */
trait RowStream
{
    /** @var list<mixed>|null */
    private ?array $list = null;

    private int $listCount = 0;

    private int $position = -1;

    /** @var \Iterator<mixed>|null */
    private ?\Iterator $iterator = null;

    private bool $iteratorStarted = false;

    private mixed $row = null;

    private mixed $rowKey = null;

    /**
     * @param iterable<mixed> $rows
     */
    private function initRows(iterable $rows): void
    {
        $this->list = Rows::list($rows);
        if ($this->list !== null) {
            $this->listCount = \count($this->list);
        } else {
            $this->iterator = Rows::iterator($rows);
        }
    }

    /**
     * Moves to the next row ($this->row, $this->rowKey); false at the end.
     */
    private function fetchRow(): bool
    {
        if ($this->list !== null) {
            if (++$this->position < $this->listCount) {
                $this->row = $this->list[$this->position];
                $this->rowKey = $this->position;

                return true;
            }
            $this->position = $this->listCount;

            return false;
        }
        \assert($this->iterator !== null);
        if ($this->iteratorStarted) {
            $this->iterator->next();
        } else {
            $this->iterator->rewind();
            $this->iteratorStarted = true;
        }
        if (!$this->iterator->valid()) {
            return false;
        }
        $this->row = $this->iterator->current();
        $this->rowKey = $this->iterator->key();

        return true;
    }
}
