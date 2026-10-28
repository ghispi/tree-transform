<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Strategy;

use Ghispi\TreeTransform\Cursor\Cursor;
use Ghispi\TreeTransform\Internal\CompiledPredicate;
use Ghispi\TreeTransform\Predicate\Ancestors;
use Ghispi\TreeTransform\Predicate\Predicate;

/**
 * @internal created by Prune
 */
final class PruneCursor implements Cursor
{
    private readonly Ancestors $ancestors;

    /** @var \Closure(mixed, int, Ancestors): mixed */
    private readonly \Closure $test;

    private readonly bool $trackAncestors;

    /** Depth of a match whose subtree is kept untested (OnMatch::KeepDescendants), or -1. */
    private int $keepBelow = -1;

    private mixed $node = null;

    private int $depth = 0;

    public function __construct(
        private readonly Cursor $input,
        Predicate $predicate,
        private readonly OnMatch $onMatch,
    ) {
        $this->ancestors = new Ancestors();
        $compiled = CompiledPredicate::of($predicate);
        $this->test = $compiled->test;
        $this->trackAncestors = $compiled->needsAncestors;
    }

    public function next(): bool
    {
        while ($this->input->next()) {
            $depth = $this->input->depth();
            if ($this->keepBelow >= 0) {
                if ($depth > $this->keepBelow) {
                    $this->node = $this->input->node();
                    $this->depth = $depth;

                    return true;
                }
                $this->keepBelow = -1;
            }
            $node = $this->input->node();
            if ($this->trackAncestors) {
                $this->ancestors->enter($node, $depth);
            }
            if (!(bool) ($this->test)($node, $depth, $this->ancestors)) {
                $this->input->skipSubtree();
                continue;
            }
            if ($this->onMatch === OnMatch::KeepDescendants) {
                $this->keepBelow = $depth;
            } elseif ($this->onMatch === OnMatch::DropDescendants) {
                $this->input->skipSubtree();
            }
            $this->node = $node;
            $this->depth = $depth;

            return true;
        }
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
        $this->input->skipSubtree();
    }
}
