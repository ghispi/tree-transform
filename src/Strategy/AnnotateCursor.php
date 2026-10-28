<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Strategy;

use Ghispi\TreeTransform\Cursor\Cursor;
use Ghispi\TreeTransform\Predicate\Ancestors;
use Ghispi\TreeTransform\Predicate\Predicate;

/**
 * @internal created by Annotate
 */
final class AnnotateCursor implements Cursor
{
    private readonly Ancestors $ancestors;

    /** @var array<int, mixed> */
    private array $queueNodes = [];

    /** @var array<int, int> */
    private array $queueDepths = [];

    /** @var array<int, MatchState|null> null while undecided */
    private array $queueStates = [];

    private int $head = 0;

    private int $tail = 0;

    /** @var array<int, int> queue position of each node on the current path, indexed by depth */
    private array $pathEntry = [];

    /** @var array<int, bool> whether each path node matches */
    private array $pathMatches = [];

    /** @var array<int, bool> whether each path node's state is decided */
    private array $pathDecided = [];

    private int $pathLength = 0;

    /** Number of matching nodes on the current path (to tell DescendantOfMatch from None). */
    private int $matchesOnPath = 0;

    private bool $exhausted = false;

    private mixed $node = null;

    private int $depth = 0;

    private int $skipBelow = -1;

    /**
     * @param \Closure(mixed, MatchState): mixed $combine
     */
    public function __construct(
        private readonly Cursor $input,
        private readonly Predicate $predicate,
        private readonly \Closure $combine,
    ) {
        $this->ancestors = new Ancestors();
    }

    public function next(): bool
    {
        while (true) {
            while ($this->head < $this->tail && $this->queueStates[$this->head] !== null) {
                $position = $this->head++;
                $depth = $this->queueDepths[$position];
                $node = $this->queueNodes[$position];
                /** @var MatchState $state checked by the loop condition */
                $state = $this->queueStates[$position];
                unset($this->queueNodes[$position], $this->queueDepths[$position], $this->queueStates[$position]);
                if ($this->skipBelow >= 0) {
                    if ($depth > $this->skipBelow) {
                        continue;
                    }
                    $this->skipBelow = -1;
                }
                $this->node = ($this->combine)($node, $state);
                $this->depth = $depth;

                return true;
            }
            if ($this->head === $this->tail) {
                $this->head = $this->tail = 0;
            }
            if ($this->exhausted) {
                return false;
            }
            if (!$this->input->next()) {
                $this->exhausted = true;
                $this->close(0);
                continue;
            }
            $this->visit($this->input->node(), $this->input->depth());
        }
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
        $this->skipBelow = $this->depth;
    }

    private function visit(mixed $node, int $depth): void
    {
        $this->close($depth);

        $this->ancestors->enter($node, $depth);
        $matches = $this->predicate->matches($node, $depth, $this->ancestors);

        $position = $this->tail++;
        $this->queueNodes[$position] = $node;
        $this->queueDepths[$position] = $depth;
        $this->queueStates[$position] = $matches ? MatchState::Match : null;
        $this->pathEntry[$depth] = $position;
        $this->pathMatches[$depth] = $matches;
        $this->pathDecided[$depth] = $matches;
        $this->pathLength = $depth + 1;

        if ($matches) {
            // Every undecided ancestor now has a matching descendant.
            for ($j = $depth - 1; $j >= 0 && !$this->pathDecided[$j]; --$j) {
                $this->queueStates[$this->pathEntry[$j]] = MatchState::AncestorOfMatch;
                $this->pathDecided[$j] = true;
            }
            ++$this->matchesOnPath;
        }
    }

    /**
     * Closes the path nodes at $depth and deeper: undecided ones had no matching descendant.
     */
    private function close(int $depth): void
    {
        for ($j = $this->pathLength - 1; $j >= $depth; --$j) {
            if ($this->pathMatches[$j]) {
                --$this->matchesOnPath;
                continue;
            }
            if (!$this->pathDecided[$j]) {
                $this->queueStates[$this->pathEntry[$j]] = $this->matchesOnPath > 0
                    ? MatchState::DescendantOfMatch
                    : MatchState::None;
                $this->pathDecided[$j] = true;
            }
        }
        if ($this->pathLength > $depth) {
            $this->pathLength = $depth;
        }
    }
}
