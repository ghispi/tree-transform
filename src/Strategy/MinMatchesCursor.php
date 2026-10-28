<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Strategy;

use Ghispi\TreeTransform\Cursor\Cursor;
use Ghispi\TreeTransform\Internal\CompiledPredicate;
use Ghispi\TreeTransform\Predicate\Ancestors;
use Ghispi\TreeTransform\Predicate\Predicate;

/**
 * @internal created by MinMatches
 */
final class MinMatchesCursor implements Cursor
{
    private const int UNDECIDED = 0;
    private const int KEEP = 1;
    private const int DROP = 2;

    private readonly Ancestors $ancestors;

    /** @var \Closure(mixed, int, Ancestors): mixed */
    private readonly \Closure $test;

    private readonly bool $trackAncestors;

    /** @var array<int, mixed> */
    private array $queueNodes = [];

    /** @var array<int, int> */
    private array $queueDepths = [];

    /** @var array<int, int> one of the constants above */
    private array $queueStates = [];

    private int $head = 0;

    private int $tail = 0;

    /** @var array<int, int> queue position of each open path node, indexed by depth */
    private array $pathEntry = [];

    /** @var array<int, int> matches counted so far in each open path node's subtree */
    private array $pathCount = [];

    /** @var array<int, bool> whether each open path node is decided (kept) */
    private array $pathKept = [];

    private int $pathLength = 0;

    private bool $exhausted = false;

    private mixed $node = null;

    private int $depth = 0;

    private int $skipBelow = -1;

    /** Queue position of the input's current node, and of the last emitted entry (to forward skips). */
    private int $inputPosition = -1;

    private int $emittedPosition = -2;

    public function __construct(
        private readonly Cursor $input,
        Predicate $predicate,
        private readonly int $min,
    ) {
        $this->ancestors = new Ancestors();
        $compiled = CompiledPredicate::of($predicate);
        $this->test = $compiled->test;
        $this->trackAncestors = $compiled->needsAncestors;
    }

    public function next(): bool
    {
        while (true) {
            while ($this->head < $this->tail && $this->queueStates[$this->head] !== self::UNDECIDED) {
                $position = $this->head++;
                $state = $this->queueStates[$position];
                $depth = $this->queueDepths[$position];
                $node = $this->queueNodes[$position];
                unset($this->queueNodes[$position], $this->queueDepths[$position], $this->queueStates[$position]);
                if ($state === self::DROP) {
                    continue;
                }
                if ($this->skipBelow >= 0) {
                    if ($depth > $this->skipBelow) {
                        continue;
                    }
                    $this->skipBelow = -1;
                }
                $this->node = $node;
                $this->depth = $depth;
                $this->emittedPosition = $position;

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
        // The emitted node is the input's current node: its state and its ancestors' are decided, so the input
        // can skip its subtree too instead of reading and testing every descendant.
        if ($this->emittedPosition === $this->inputPosition && !$this->exhausted) {
            $this->input->skipSubtree();
        }
    }

    private function visit(mixed $node, int $depth): void
    {
        $this->close($depth);

        $position = $this->tail++;
        $this->inputPosition = $position;
        $this->queueNodes[$position] = $node;
        $this->queueDepths[$position] = $depth;
        $this->queueStates[$position] = self::UNDECIDED;
        $this->pathEntry[$depth] = $position;
        $this->pathCount[$depth] = 0;
        $this->pathKept[$depth] = false;
        $this->pathLength = $depth + 1;

        if ($this->trackAncestors) {
            $this->ancestors->enter($node, $depth);
        }
        if (!(bool) ($this->test)($node, $depth, $this->ancestors)) {
            return;
        }
        // Count the match for the node and every undecided ancestor; kept ones are a prefix of the path.
        for ($j = $depth; $j >= 0 && !$this->pathKept[$j]; --$j) {
            if (++$this->pathCount[$j] >= $this->min) {
                $this->pathKept[$j] = true;
                $this->queueStates[$this->pathEntry[$j]] = self::KEEP;
            }
        }
    }

    /**
     * Closes path nodes at $depth and deeper; the undecided ones did not reach the minimum.
     */
    private function close(int $depth): void
    {
        for ($j = $this->pathLength - 1; $j >= $depth; --$j) {
            if (!$this->pathKept[$j]) {
                $this->queueStates[$this->pathEntry[$j]] = self::DROP;
            }
        }
        if ($this->pathLength > $depth) {
            $this->pathLength = $depth;
        }
    }
}
