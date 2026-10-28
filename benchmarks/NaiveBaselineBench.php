<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Benchmarks;

use Ghispi\TreeTransform\Benchmarks\Support\Naive;
use Ghispi\TreeTransform\Strategy\KeepAncestors;
use Ghispi\TreeTransform\Tree;
use PhpBench\Attributes as Bench;

/**
 * Library vs the naive "build the full nested array, then filter it recursively" (SPEC §10: >= 2x faster and
 * lower memory). Each pair produces the same nested array (checked by benchmarks/verify.php).
 */
#[Bench\Revs(2)]
#[Bench\Iterations(5)]
#[Bench\Warmup(1)]
#[Bench\OutputTimeUnit('milliseconds', 2)]
#[Bench\Groups(['naive'])]
#[Bench\BeforeMethods('setUpRows')]
#[Bench\ParamProviders('allShapes')]
final class NaiveBaselineBench extends BenchCase
{
    // ---- adjacency rows, KeepAncestors (search, ~10 % hits)

    public function benchNaiveAdjacencyKeepAncestors(): int
    {
        $count = 0;
        for ($i = 0; $i < $this->reps; ++$i) {
            $count += \count(Naive::adjacencyKeepAncestors($this->rows, static fn(array $n): bool => $n['hit']));
        }

        return $count;
    }

    public function benchLibraryAdjacencyKeepAncestors(): int
    {
        $count = 0;
        for ($i = 0; $i < $this->reps; ++$i) {
            $count += \count(Tree::fromAdjacencyList($this->rows)
                ->filter(static fn(array $n): bool => $n['hit'], new KeepAncestors())
                ->toNestedArray());
        }

        return $count;
    }

    // ---- nested set rows, KeepAncestors

    public function benchNaiveNestedSetKeepAncestors(): int
    {
        $count = 0;
        for ($i = 0; $i < $this->reps; ++$i) {
            $count += \count(Naive::nestedSetKeepAncestors($this->rows, static fn(array $n): bool => $n['hit']));
        }

        return $count;
    }

    public function benchLibraryNestedSetKeepAncestors(): int
    {
        $count = 0;
        for ($i = 0; $i < $this->reps; ++$i) {
            $count += \count(Tree::fromNestedSet($this->rows)
                ->filter(static fn(array $n): bool => $n['hit'], new KeepAncestors())
                ->toNestedArray());
        }

        return $count;
    }

    // ---- adjacency rows, Prune (~90 % visible)

    public function benchNaiveAdjacencyPrune(): int
    {
        $count = 0;
        for ($i = 0; $i < $this->reps; ++$i) {
            $count += \count(Naive::adjacencyPrune($this->rows, static fn(array $n): bool => $n['visible']));
        }

        return $count;
    }

    public function benchLibraryAdjacencyPrune(): int
    {
        $count = 0;
        for ($i = 0; $i < $this->reps; ++$i) {
            $count += \count(Tree::fromAdjacencyList($this->rows)
                ->filter(static fn(array $n): bool => $n['visible'])
                ->toNestedArray());
        }

        return $count;
    }

    public function benchLibraryNestedSetPrune(): int
    {
        $count = 0;
        for ($i = 0; $i < $this->reps; ++$i) {
            $count += \count(Tree::fromNestedSet($this->rows)
                ->filter(static fn(array $n): bool => $n['visible'])
                ->toNestedArray());
        }

        return $count;
    }
}
