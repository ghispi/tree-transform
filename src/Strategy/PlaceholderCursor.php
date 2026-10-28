<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Strategy;

use Ghispi\TreeTransform\Cursor\Cursor;
use Ghispi\TreeTransform\Predicate\Ancestors;
use Ghispi\TreeTransform\Predicate\Predicate;

/**
 * @internal created by Placeholder
 */
final class PlaceholderCursor implements Cursor
{
    private readonly Ancestors $ancestors;

    /** @var array<int, int> removed siblings waiting for their placeholder, indexed by depth */
    private array $runs = [];

    /** Deepest depth that may have a pending run. */
    private int $deepest = -1;

    /** @var list<mixed> pending output nodes */
    private array $queueNodes = [];

    /** @var list<int> */
    private array $queueDepths = [];

    /** @var list<bool> whether each pending node is the input's current node */
    private array $queueAtInput = [];

    private mixed $node = null;

    private int $depth = 0;

    private bool $atInput = false;

    private bool $exhausted = false;

    private int $keepBelow = -1;

    public function __construct(
        private readonly Cursor $input,
        private readonly Predicate $predicate,
        private readonly \Closure $factory,
        private readonly OnMatch $onMatch,
    ) {
        $this->ancestors = new Ancestors();
    }

    public function next(): bool
    {
        while ($this->queueNodes === []) {
            if ($this->exhausted) {
                return false;
            }
            if (!$this->input->next()) {
                $this->exhausted = true;
                $this->flushRuns(0);
                continue;
            }
            $this->visit();
        }
        $this->node = array_shift($this->queueNodes);
        $this->depth = (int) array_shift($this->queueDepths);
        $this->atInput = (bool) array_shift($this->queueAtInput);

        return true;
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
        // Placeholders have no subtree; for real nodes the input's subtree is skipped.
        if ($this->atInput) {
            $this->input->skipSubtree();
        }
    }

    private function visit(): void
    {
        $depth = $this->input->depth();
        $node = $this->input->node();

        // Runs below this depth belong to parents that just closed.
        $this->flushRuns($depth + 1);

        if ($this->keepBelow >= 0) {
            if ($depth > $this->keepBelow) {
                $this->push($node, $depth, true);

                return;
            }
            $this->keepBelow = -1;
        }

        $this->ancestors->enter($node, $depth);
        if (!$this->predicate->matches($node, $depth, $this->ancestors)) {
            $this->input->skipSubtree();
            $this->runs[$depth] = ($this->runs[$depth] ?? 0) + 1;
            if ($depth > $this->deepest) {
                $this->deepest = $depth;
            }

            return;
        }
        $this->flushRuns($depth);
        if ($this->onMatch === OnMatch::KeepDescendants) {
            $this->keepBelow = $depth;
        } elseif ($this->onMatch === OnMatch::DropDescendants) {
            $this->input->skipSubtree();
        }
        $this->push($node, $depth, true);
    }

    /**
     * Emits the placeholders of runs at $depth and deeper, deepest first (pre-order).
     */
    private function flushRuns(int $depth): void
    {
        for ($j = $this->deepest; $j >= $depth; --$j) {
            if (($this->runs[$j] ?? 0) > 0) {
                $this->push(($this->factory)($this->runs[$j], $j), $j, false);
                $this->runs[$j] = 0;
            }
        }
        if ($this->deepest >= $depth) {
            $this->deepest = $depth - 1;
        }
    }

    private function push(mixed $node, int $depth, bool $atInput): void
    {
        $this->queueNodes[] = $node;
        $this->queueDepths[] = $depth;
        $this->queueAtInput[] = $atInput;
    }
}
