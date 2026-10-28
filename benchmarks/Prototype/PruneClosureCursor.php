<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Benchmarks\Prototype;

use Ghispi\TreeTransform\Cursor\Cursor;
use Ghispi\TreeTransform\Predicate\Ancestors;
use Ghispi\TreeTransform\Strategy\OnMatch;

/**
 * Phase 0 prototype (b), step 2: PruneNoAncestorsCursor calling the user's closure directly instead of through
 * Callback::matches() — one userland call per node instead of two. A real implementation would unwrap
 * Callback in the strategy (e.g. Callback::closure()).
 */
final class PruneClosureCursor implements Cursor
{
    private readonly Ancestors $ancestors;

    private int $keepBelow = -1;

    /**
     * @param \Closure(mixed, int, Ancestors): bool $test
     */
    public function __construct(
        private readonly Cursor $input,
        private readonly \Closure $test,
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
            if (!($this->test)($this->input->node(), $depth, $this->ancestors)) {
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
