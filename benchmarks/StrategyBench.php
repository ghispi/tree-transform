<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Benchmarks;

use Ghispi\TreeTransform\Benchmarks\Support\Fixture;
use Ghispi\TreeTransform\Strategy\KeepAncestors;
use Ghispi\TreeTransform\Strategy\Promote;
use Ghispi\TreeTransform\Tree;
use PhpBench\Attributes as Bench;

/**
 * Every strategy over an array nested set (facade defaults), consumed by count() so the sink costs nothing.
 *
 * - "hit" matches ~10 % of nodes (search-like); "visible" matches ~90 % (hide unpublished — the realistic Prune).
 * - nodes/s in the report is input rows per second.
 */
#[Bench\Revs(2)]
#[Bench\Iterations(5)]
#[Bench\Warmup(1)]
#[Bench\OutputTimeUnit('milliseconds', 2)]
#[Bench\Groups(['strategy'])]
#[Bench\BeforeMethods('setUpRows')]
#[Bench\ParamProviders('allShapes')]
final class StrategyBench extends BenchCase
{
    /** Id of the single active node of benchActiveTrailSingle. */
    private int $active = 0;

    /**
     * @param array{shape: string, nodes: int, reps: int} $params
     */
    public function setUpRows(array $params): void
    {
        $this->active = Fixture::deepNodeId($params['shape']);
        parent::setUpRows($params);
    }

    /** Reference: the source alone through the same count() sink. */
    public function benchNoFilter(): int
    {
        return $this->count(Tree::fromNestedSet($this->rows));
    }

    /** Prune keeping ~90 %: reads every row, emits about half (deep nodes have more ancestors that can fail). */
    public function benchPruneVisible(): int
    {
        return $this->count(Tree::fromNestedSet($this->rows)->filter(static fn(array $n): bool => $n['visible']));
    }

    /** Prune keeping ~10 %: almost everything is skipped right below the roots (cost = skipping rows). */
    public function benchPruneHit(): int
    {
        return $this->count(Tree::fromNestedSet($this->rows)->filter(static fn(array $n): bool => $n['hit']));
    }

    public function benchKeepAncestors(): int
    {
        return $this->count(Tree::fromNestedSet($this->rows)->filter(static fn(array $n): bool => $n['hit'], new KeepAncestors()));
    }

    public function benchPromote(): int
    {
        return $this->count(Tree::fromNestedSet($this->rows)->filter(static fn(array $n): bool => $n['hit'], new Promote()));
    }

    /** Default combine: one Annotated object per node. Buffers a whole root tree. */
    public function benchAnnotate(): int
    {
        return $this->count(Tree::fromNestedSet($this->rows)->annotate(static fn(array $n): bool => $n['hit']));
    }

    /** ~10 % active nodes (union of many trails) — a stress case, menus usually have one. */
    public function benchActiveTrailHit(): int
    {
        return $this->count(Tree::fromNestedSet($this->rows)->activeTrail(static fn(array $n): bool => $n['hit']));
    }

    /** One active node at the deepest level, near the end: the typical menu. */
    public function benchActiveTrailSingle(): int
    {
        $active = $this->active;

        return $this->count(Tree::fromNestedSet($this->rows)->activeTrail(static fn(array $n): bool => $n['id'] === $active));
    }

    private function count(Tree $tree): int
    {
        $count = 0;
        for ($i = 0; $i < $this->reps; ++$i) {
            $count += $tree->count();
        }

        return $count;
    }
}
