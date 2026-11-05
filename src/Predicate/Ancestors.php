<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Predicate;

use Ghispi\TreeTransform\Exception\OutOfRangeException;

/**
 * Lazy, read-only view of the ancestors of the node a predicate is currently testing, root first.
 *
 * The view is reused for every node and is only valid during the predicate call; use toArray() to keep a copy.
 * Ancestors are those of the filter's input, i.e. the tree after any earlier stages.
 *
 * Strategy authors keep it up to date with an {@see AncestorTracker}; tests build one with {@see Ancestors::of()}.
 *
 * @implements \IteratorAggregate<int, mixed>
 */
final class Ancestors implements \Countable, \IteratorAggregate
{
    /** @var array<int, mixed> */
    private array $path = [];

    private int $count = 0;

    private function __construct() {}

    /**
     * A view of the given ancestors, root first — for testing predicates outside a pipeline.
     *
     * @param iterable<mixed> $path
     */
    public static function of(iterable $path = []): self
    {
        $view = new self();
        foreach ($path as $node) {
            $view->path[$view->count++] = $node;
        }

        return $view;
    }

    /**
     * Number of ancestors; equals the depth of the current node.
     */
    public function count(): int
    {
        return max(0, $this->count);
    }

    /**
     * The direct parent, or null for a root node.
     */
    public function parent(): mixed
    {
        return $this->count > 0 ? $this->path[$this->count - 1] : null;
    }

    /**
     * The ancestor at the given depth (0 = root).
     *
     * @throws OutOfRangeException when $depth is not between 0 and count() - 1
     */
    public function at(int $depth): mixed
    {
        if ($depth < 0 || $depth >= $this->count) {
            throw new OutOfRangeException(\sprintf('No ancestor at depth %d (node has %d).', $depth, $this->count));
        }

        return $this->path[$depth];
    }

    /**
     * True if at least one ancestor passes the test. Checks the nearest ancestor first and stops at the first hit.
     *
     * @param callable(mixed): bool $test
     */
    public function some(callable $test): bool
    {
        for ($i = $this->count - 1; $i >= 0; --$i) {
            if ($test($this->path[$i])) {
                return true;
            }
        }

        return false;
    }

    /**
     * True if every ancestor passes the test (also true for a root node). Checks the nearest ancestor first.
     *
     * @param callable(mixed): bool $test
     */
    public function every(callable $test): bool
    {
        for ($i = $this->count - 1; $i >= 0; --$i) {
            if (!$test($this->path[$i])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Copy of the ancestors, root first.
     *
     * @return list<mixed>
     */
    public function toArray(): array
    {
        $out = [];
        for ($i = 0; $i < $this->count; ++$i) {
            $out[] = $this->path[$i];
        }

        return $out;
    }

    /**
     * Iterates root first; keys are depths.
     *
     * @return \Generator<int, mixed>
     */
    public function getIterator(): \Generator
    {
        for ($i = 0; $i < $this->count; ++$i) {
            yield $i => $this->path[$i];
        }
    }

    /**
     * Records the node about to be tested; its ancestors are the nodes recorded at lower depths.
     *
     * @internal strategy authors use {@see AncestorTracker::enter()}
     */
    public function enter(mixed $node, int $depth): void
    {
        $this->path[$depth] = $node;
        $this->count = $depth;
    }
}
