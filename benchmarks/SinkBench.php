<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Benchmarks;

use Ghispi\TreeTransform\Strategy\KeepAncestors;
use Ghispi\TreeTransform\Tree;
use PhpBench\Attributes as Bench;

/**
 * Sinks over an unfiltered array nested set (every node is output), plus one realistic search pipeline.
 * Subtract SourceBench/StrategyBench "no filter" for the sink's own cost. Memory is O(output) by design.
 */
#[Bench\Revs(2)]
#[Bench\Iterations(5)]
#[Bench\Warmup(1)]
#[Bench\OutputTimeUnit('milliseconds', 2)]
#[Bench\Groups(['sink'])]
#[Bench\BeforeMethods('setUpRows')]
#[Bench\ParamProviders('allShapes')]
final class SinkBench extends BenchCase
{
    public function benchCount(): int
    {
        $count = 0;
        for ($i = 0; $i < $this->reps; ++$i) {
            $count += Tree::fromNestedSet($this->rows)->count();
        }

        return $count;
    }

    public function benchIterate(): int
    {
        return $this->iterate(Tree::fromNestedSet($this->rows));
    }

    public function benchToNestedArray(): int
    {
        $count = 0;
        for ($i = 0; $i < $this->reps; ++$i) {
            $count += \count(Tree::fromNestedSet($this->rows)->toNestedArray());
        }

        return $count;
    }

    public function benchToNestedSet(): int
    {
        $count = 0;
        for ($i = 0; $i < $this->reps; ++$i) {
            $count += \count(Tree::fromNestedSet($this->rows)->toNestedSet());
        }

        return $count;
    }

    public function benchToAdjacencyList(): int
    {
        $count = 0;
        for ($i = 0; $i < $this->reps; ++$i) {
            $count += \count(Tree::fromNestedSet($this->rows)->toAdjacencyList());
        }

        return $count;
    }

    public function benchToFlatList(): int
    {
        $count = 0;
        for ($i = 0; $i < $this->reps; ++$i) {
            $count += \count(Tree::fromNestedSet($this->rows)->toFlatList());
        }

        return $count;
    }

    /** Search result: KeepAncestors (~10 % hits) into nested arrays. */
    public function benchKeepAncestorsToNestedArray(): int
    {
        $count = 0;
        for ($i = 0; $i < $this->reps; ++$i) {
            $count += \count(Tree::fromNestedSet($this->rows)
                ->filter(static fn(array $n): bool => $n['hit'], new KeepAncestors())
                ->toNestedArray());
        }

        return $count;
    }
}
