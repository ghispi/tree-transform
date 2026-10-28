<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Strategy;

use Ghispi\TreeTransform\Cursor\Cursor;
use Ghispi\TreeTransform\Predicate\Ancestors;
use Ghispi\TreeTransform\Predicate\Predicate;

/**
 * @internal created by Prune
 */
final class PruneCursor implements Cursor
{
    private readonly Ancestors $ancestors;

    /** Depth of a match whose subtree is kept untested (OnMatch::KeepDescendants), or -1. */
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
            if ($this->keepBelow >= 0) {
                if ($depth > $this->keepBelow) {
                    return true;
                }
                $this->keepBelow = -1;
            }
            $node = $this->input->node();
            $this->ancestors->enter($node, $depth);
            if (!$this->predicate->matches($node, $depth, $this->ancestors)) {
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
