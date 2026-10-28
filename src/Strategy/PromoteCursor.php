<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Strategy;

use Ghispi\TreeTransform\Cursor\Cursor;
use Ghispi\TreeTransform\Predicate\Ancestors;
use Ghispi\TreeTransform\Predicate\Predicate;

/**
 * @internal created by Promote
 */
final class PromoteCursor implements Cursor
{
    private readonly Ancestors $ancestors;

    /** @var array<int, int> input depths of kept ancestors; their count is the output depth */
    private array $kept = [];

    private int $keptCount = 0;

    private int $depth = 0;

    private int $keepBelow = -1;

    public function __construct(
        private readonly Cursor $input,
        private readonly Predicate $predicate,
        private readonly OnMatch $onMatch,
    ) {
        $this->ancestors = new Ancestors();
    }

    public function next(): bool
    {
        while ($this->input->next()) {
            $depth = $this->input->depth();
            while ($this->keptCount > 0 && $this->kept[$this->keptCount - 1] >= $depth) {
                --$this->keptCount;
            }
            if ($this->keepBelow >= 0) {
                if ($depth > $this->keepBelow) {
                    return $this->keep($depth);
                }
                $this->keepBelow = -1;
            }
            $node = $this->input->node();
            $this->ancestors->enter($node, $depth);
            if (!$this->predicate->matches($node, $depth, $this->ancestors)) {
                continue;
            }
            if ($this->onMatch === OnMatch::KeepDescendants) {
                $this->keepBelow = $depth;
            } elseif ($this->onMatch === OnMatch::DropDescendants) {
                $this->input->skipSubtree();
            }

            return $this->keep($depth);
        }

        return false;
    }

    public function node(): mixed
    {
        return $this->input->node();
    }

    public function depth(): int
    {
        return $this->depth;
    }

    public function skipSubtree(): void
    {
        $this->input->skipSubtree();
    }

    private function keep(int $inputDepth): bool
    {
        $this->depth = $this->keptCount;
        $this->kept[$this->keptCount++] = $inputDepth;

        return true;
    }
}
