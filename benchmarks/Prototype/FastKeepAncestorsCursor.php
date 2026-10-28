<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Benchmarks\Prototype;

use Ghispi\TreeTransform\Cursor\Cursor;
use Ghispi\TreeTransform\Predicate\Ancestors;
use Ghispi\TreeTransform\Strategy\OnMatch;

/**
 * Pipeline prototype: src/Strategy/KeepAncestorsCursor with the two Phase 0 (b) changes applied — no
 * Ancestors::enter() per node, user closure called directly — and the input cursor read once per node into
 * locals. Behaviour (OnMatch modes, skip forwarding) is unchanged.
 */
final class FastKeepAncestorsCursor implements Cursor
{
    private readonly Ancestors $ancestors;

    /** @var array<int, mixed> */
    private array $path = [];

    private int $emitted = 0;

    private int $flushAt = 0;

    private int $flushEnd = 0;

    private mixed $node = null;

    private int $depth = 0;

    private int $keepBelow = -1;

    private int $skipBelow = -1;

    private bool $atInput = false;

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
        if ($this->flushAt < $this->flushEnd) {
            $depth = $this->flushAt++;
            $this->node = $this->path[$depth];
            $this->depth = $depth;
            $this->atInput = $this->flushAt === $this->flushEnd;

            return true;
        }

        $input = $this->input;
        while ($input->next()) {
            $depth = $input->depth();
            if ($this->skipBelow >= 0) {
                if ($depth > $this->skipBelow) {
                    $input->skipSubtree();
                    continue;
                }
                $this->skipBelow = -1;
            }
            if ($this->emitted > $depth) {
                $this->emitted = $depth;
            }
            $node = $input->node();
            $this->path[$depth] = $node;

            if ($this->keepBelow >= 0) {
                if ($depth > $this->keepBelow) {
                    $this->emitted = $depth + 1;
                    $this->node = $node;
                    $this->depth = $depth;
                    $this->atInput = true;

                    return true;
                }
                $this->keepBelow = -1;
            }

            if (!($this->test)($node, $depth, $this->ancestors)) {
                continue;
            }
            if ($this->onMatch === OnMatch::KeepDescendants) {
                $this->keepBelow = $depth;
            } elseif ($this->onMatch === OnMatch::DropDescendants) {
                $input->skipSubtree();
            }
            $first = $this->emitted;
            $this->flushAt = $first + 1;
            $this->flushEnd = $depth + 1;
            $this->emitted = $depth + 1;
            $this->node = $this->path[$first];
            $this->depth = $first;
            $this->atInput = $this->flushAt === $this->flushEnd;

            return true;
        }

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
        if ($this->atInput) {
            $this->input->skipSubtree();

            return;
        }
        $this->skipBelow = $this->depth;
        $this->flushEnd = $this->flushAt;
        $this->emitted = $this->depth + 1;
        $this->input->skipSubtree();
    }
}
