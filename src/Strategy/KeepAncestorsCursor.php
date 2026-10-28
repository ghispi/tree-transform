<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Strategy;

use Ghispi\TreeTransform\Cursor\Cursor;
use Ghispi\TreeTransform\Internal\CompiledPredicate;
use Ghispi\TreeTransform\Predicate\Ancestors;
use Ghispi\TreeTransform\Predicate\Predicate;

/**
 * @internal created by KeepAncestors
 */
final class KeepAncestorsCursor implements Cursor
{
    private readonly Ancestors $ancestors;

    /** @var \Closure(mixed, int, Ancestors): mixed */
    private readonly \Closure $test;

    private readonly bool $trackAncestors;

    /** @var array<int, mixed> current input path, indexed by depth */
    private array $path = [];

    /** Number of nodes at the top of the path that were already emitted. */
    private int $emitted = 0;

    /** Next path entry to emit while flushing ancestors, and the end of the flush (exclusive). */
    private int $flushAt = 0;

    private int $flushEnd = 0;

    private mixed $node = null;

    private int $depth = 0;

    private int $keepBelow = -1;

    /** Output depth whose subtree the consumer asked to skip, or -1. */
    private int $skipBelow = -1;

    /** Whether the current output node is the input's current node (so skips can be forwarded). */
    private bool $atInput = false;

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
        if ($this->flushAt < $this->flushEnd) {
            return $this->flush();
        }

        while ($this->input->next()) {
            $depth = $this->input->depth();
            if ($this->skipBelow >= 0) {
                if ($depth > $this->skipBelow) {
                    $this->input->skipSubtree();
                    continue;
                }
                $this->skipBelow = -1;
            }
            if ($this->emitted > $depth) {
                $this->emitted = $depth;
            }
            $node = $this->input->node();
            $this->path[$depth] = $node;

            if ($this->keepBelow >= 0) {
                if ($depth > $this->keepBelow) {
                    $this->emitted = $depth + 1;

                    return $this->emit($node, $depth, true);
                }
                $this->keepBelow = -1;
            }

            if ($this->trackAncestors) {
                $this->ancestors->enter($node, $depth);
            }
            if (!(bool) ($this->test)($node, $depth, $this->ancestors)) {
                continue;
            }
            if ($this->onMatch === OnMatch::KeepDescendants) {
                $this->keepBelow = $depth;
            } elseif ($this->onMatch === OnMatch::DropDescendants) {
                $this->input->skipSubtree();
            }
            $this->flushAt = $this->emitted;
            $this->flushEnd = $depth + 1;
            $this->emitted = $depth + 1;

            return $this->flush();
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
        // An ancestor is being flushed: drop the rest of the flush and the input below it.
        $this->skipBelow = $this->depth;
        $this->flushEnd = $this->flushAt;
        $this->emitted = $this->depth + 1;
        $this->input->skipSubtree();
    }

    private function flush(): bool
    {
        $depth = $this->flushAt++;

        return $this->emit($this->path[$depth], $depth, $this->flushAt === $this->flushEnd);
    }

    private function emit(mixed $node, int $depth, bool $atInput): bool
    {
        $this->node = $node;
        $this->depth = $depth;
        $this->atInput = $atInput;

        return true;
    }
}
