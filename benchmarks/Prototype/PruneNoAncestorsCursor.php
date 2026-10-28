<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Benchmarks\Prototype;

use Ghispi\TreeTransform\Cursor\Cursor;
use Ghispi\TreeTransform\Predicate\Ancestors;
use Ghispi\TreeTransform\Predicate\Predicate;
use Ghispi\TreeTransform\Strategy\OnMatch;

/**
 * Phase 0 prototype (b): src/Strategy/PruneCursor without Ancestors tracking (no enter() per node). The predicate
 * still receives an (empty) Ancestors view and is still called through the Predicate interface.
 */
final class PruneNoAncestorsCursor implements Cursor
{
    private readonly Ancestors $ancestors;

    private int $keepBelow = -1;

    public function __construct(
        private readonly Cursor $input,
        private readonly Predicate $predicate,
        private readonly OnMatch $onMatch = OnMatch::EvaluateDescendants,
    ) {
        $this->ancestors = new Ancestors();
    }

    public function next(): bool
    {
        while ($this->input->next()) {
            $depth = $this->input->depth();
            if ($this->keepBelow >= 0) {
                if ($depth > $this->keepBelow) {
                    return true;
                }
                $this->keepBelow = -1;
            }
            if (!$this->predicate->matches($this->input->node(), $depth, $this->ancestors)) {
                $this->input->skipSubtree();
                continue;
            }
            if ($this->onMatch === OnMatch::KeepDescendants) {
                $this->keepBelow = $depth;
            } elseif ($this->onMatch === OnMatch::DropDescendants) {
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
        return $this->input->depth();
    }

    public function skipSubtree(): void
    {
        $this->input->skipSubtree();
    }
}
