<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Stage;

use Ghispi\TreeTransform\Cursor\Cursor;

/**
 * @internal created by DepthWindow and MaxDepth
 */
final class DepthWindowCursor implements Cursor
{
    public function __construct(
        private readonly Cursor $input,
        private readonly int $min,
        private readonly int $max,
    ) {}

    public function next(): bool
    {
        while ($this->input->next()) {
            $depth = $this->input->depth();
            if ($depth < $this->min) {
                continue;
            }
            if ($depth >= $this->max) {
                $this->input->skipSubtree();
            }

            return true;
        }

        return false;
    }

    public function node(): mixed
    {
        return $this->input->node();
    }

    public function depth(): int
    {
        return $this->input->depth() - $this->min;
    }

    public function skipSubtree(): void
    {
        $this->input->skipSubtree();
    }
}
