<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Strategy;

use Ghispi\TreeTransform\Cursor\Cursor;
use Ghispi\TreeTransform\Predicate\Ancestors;
use Ghispi\TreeTransform\Predicate\Predicate;

/**
 * @internal created by ActiveTrail
 */
final class ActiveTrailCursor implements Cursor
{
    private readonly Ancestors $ancestors;

    /** @var array<int, mixed> current input path, indexed by depth */
    private array $path = [];

    /** @var array<int, bool> whether each path node has been emitted */
    private array $emitted = [];

    /** @var array<int, list<mixed>> buffered children (depth + 1) of path nodes not yet known to be on the trail */
    private array $buffers = [];

    private int $pathLength = 0;

    /** Path nodes at depths 0 .. $trail - 1 are known ancestors of an active node. */
    private int $trail = 0;

    /** @var array<int, int> depths of active nodes on the current path */
    private array $active = [];

    private int $activeCount = 0;

    private bool $found = false;

    /** @var array<int, mixed> */
    private array $queueNodes = [];

    /** @var array<int, int> */
    private array $queueDepths = [];

    private int $head = 0;

    private int $tail = 0;

    private mixed $node = null;

    private int $depth = 0;

    private int $skipBelow = -1;

    /** Queue position of the input's current node, and of the last emitted entry (to forward skips). */
    private int $inputPosition = -1;

    private int $emittedPosition = -2;

    public function __construct(
        private readonly Cursor $input,
        private readonly Predicate $predicate,
        private readonly bool $siblings,
        private readonly int $activeChildrenDepth,
        private readonly bool $multiple,
    ) {
        $this->ancestors = new Ancestors();
    }

    public function next(): bool
    {
        while (true) {
            while ($this->head < $this->tail) {
                $position = $this->head++;
                $depth = $this->queueDepths[$position];
                $node = $this->queueNodes[$position];
                unset($this->queueNodes[$position], $this->queueDepths[$position]);
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
            $this->head = $this->tail = 0;

            if (!$this->input->next()) {
                return false;
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
        // Once the single active node is found, the trail is fixed: skipping the current input node's subtree only
        // removes descendants the consumer does not want. Before that, the subtree could hold the active node.
        if ($this->found && !$this->multiple && $this->emittedPosition === $this->inputPosition) {
            $this->input->skipSubtree();
        }
    }

    private function visit(mixed $node, int $depth): void
    {
        for ($j = $depth; $j < $this->pathLength; ++$j) {
            $this->buffers[$j] = [];
        }
        $this->pathLength = $depth + 1;
        if ($this->trail > $depth) {
            $this->trail = $depth;
        }
        while ($this->activeCount > 0 && $this->active[$this->activeCount - 1] >= $depth) {
            --$this->activeCount;
        }

        $this->inputPosition = -1;
        $nearActive = $this->activeCount > 0
            && $depth - $this->active[$this->activeCount - 1] <= $this->activeChildrenDepth;
        if ($nearActive || ($this->siblings && ($depth === 0 || $depth - 1 < $this->trail))) {
            $this->inputPosition = $this->tail;
            $this->enqueue($node, $depth);
            $this->emitted[$depth] = true;
        } else {
            $this->emitted[$depth] = false;
            if ($this->siblings) {
                $this->buffers[$depth - 1][] = $node;
            }
        }
        $this->path[$depth] = $node;
        $this->buffers[$depth] = [];

        if ($this->found && !$this->multiple) {
            return;
        }
        $this->ancestors->enter($node, $depth);
        if (!$this->predicate->matches($node, $depth, $this->ancestors)) {
            return;
        }
        $this->found = true;

        if ($this->siblings) {
            for ($j = $this->trail; $j < $depth; ++$j) {
                foreach ($this->buffers[$j] as $child) {
                    $this->enqueue($child, $j + 1);
                }
                $this->buffers[$j] = [];
            }
            for ($j = $this->trail; $j <= $depth; ++$j) {
                $this->emitted[$j] = true;
            }
        } else {
            for ($j = 0; $j <= $depth; ++$j) {
                if (!$this->emitted[$j]) {
                    $this->enqueue($this->path[$j], $j);
                    $this->emitted[$j] = true;
                }
            }
        }
        $this->trail = $depth;
        $this->active[$this->activeCount++] = $depth;
    }

    private function enqueue(mixed $node, int $depth): void
    {
        $this->queueNodes[$this->tail] = $node;
        $this->queueDepths[$this->tail] = $depth;
        ++$this->tail;
    }
}
