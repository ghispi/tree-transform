<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Stage;

use Ghispi\TreeTransform\Cursor\Cursor;

/**
 * @internal created by Limit
 */
final class LimitCursor implements Cursor
{
    private int $left;

    public function __construct(private readonly Cursor $input, int $count)
    {
        $this->left = $count;
    }

    public function next(): bool
    {
        if ($this->left <= 0 || !$this->input->next()) {
            $this->left = 0;

            return false;
        }
        --$this->left;

        return true;
    }

    public function node(): mixed
    {
        return $this->input->node();
    }

    public function depth(): int
    {
        return $this->input->depth();
    }

    public function skipSubtree(): void
    {
        $this->input->skipSubtree();
    }
}
