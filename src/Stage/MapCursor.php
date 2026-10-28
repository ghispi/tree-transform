<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Stage;

use Ghispi\TreeTransform\Cursor\Cursor;

/**
 * @internal created by Map
 */
final class MapCursor implements Cursor
{
    private mixed $node = null;

    /**
     * @param \Closure(mixed, int): mixed $transform
     */
    public function __construct(private readonly Cursor $input, private readonly \Closure $transform) {}

    public function next(): bool
    {
        if (!$this->input->next()) {
            $this->node = null;

            return false;
        }
        $this->node = ($this->transform)($this->input->node(), $this->input->depth());

        return true;
    }

    public function node(): mixed
    {
        return $this->node;
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
