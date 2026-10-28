<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Benchmarks;

use Ghispi\TreeTransform\Benchmarks\Support\Fixture;
use Ghispi\TreeTransform\Benchmarks\Support\LazyTree;
use Ghispi\TreeTransform\Strategy\KeepAncestors;
use Ghispi\TreeTransform\Strategy\Promote;
use Ghispi\TreeTransform\Tree;
use PhpBench\Attributes as Bench;

/**
 * Streaming: rows come from a generator (stand-in for a DB cursor), so nothing is held in memory but the
 * library's working state. SPEC §10: peak memory must not grow with n. Balanced tree, branching 10, depth 4/5/6.
 *
 * Time includes generating the rows; benchGeneratorOnly measures that part alone.
 */
#[Bench\Revs(1)]
#[Bench\Iterations(5)]
#[Bench\OutputTimeUnit('milliseconds', 1)]
#[Bench\Groups(['streaming'])]
#[Bench\BeforeMethods('setUp')]
#[Bench\ParamProviders('sizes')]
final class StreamingBench
{
    private int $depth = 4;

    /**
     * @return \Generator<string, array{depth: int, nodes: int}>
     */
    public function sizes(): \Generator
    {
        foreach ([4, 5, 6] as $depth) {
            $nodes = Fixture::balancedSize(10, $depth);
            yield number_format($nodes) => ['depth' => $depth, 'nodes' => $nodes];
        }
    }

    /**
     * @param array{depth: int, nodes: int} $params
     */
    public function setUp(array $params): void
    {
        $this->depth = $params['depth'];
        memory_reset_peak_usage();
    }

    public function benchGeneratorOnly(): int
    {
        $count = 0;
        foreach (LazyTree::balanced(10, $this->depth) as $row) {
            ++$count;
        }

        return $count;
    }

    public function benchNestedSet(): int
    {
        return self::drain(Tree::fromNestedSet(LazyTree::balanced(10, $this->depth)));
    }

    public function benchPreorderedAdjacency(): int
    {
        return self::drain(Tree::fromPreorderedAdjacencyList(LazyTree::balanced(10, $this->depth)));
    }

    public function benchMaterializedPath(): int
    {
        return self::drain(Tree::fromMaterializedPath(LazyTree::balanced(10, $this->depth)));
    }

    public function benchNestedSetPrune(): int
    {
        return self::drain(Tree::fromNestedSet(LazyTree::balanced(10, $this->depth))
            ->filter(static fn(array $n): bool => $n['visible']));
    }

    public function benchNestedSetKeepAncestors(): int
    {
        return self::drain(Tree::fromNestedSet(LazyTree::balanced(10, $this->depth))
            ->filter(static fn(array $n): bool => $n['hit'], new KeepAncestors()));
    }

    public function benchNestedSetPromote(): int
    {
        return self::drain(Tree::fromNestedSet(LazyTree::balanced(10, $this->depth))
            ->filter(static fn(array $n): bool => $n['hit'], new Promote()));
    }

    private static function drain(Tree $tree): int
    {
        $count = 0;
        foreach ($tree->iterate() as $node) {
            ++$count;
        }

        return $count;
    }
}
