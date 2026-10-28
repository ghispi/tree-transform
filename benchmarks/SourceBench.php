<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Benchmarks;

use Ghispi\TreeTransform\Benchmarks\Support\Fixture;
use Ghispi\TreeTransform\Source\DepthListSource;
use Ghispi\TreeTransform\Tree;
use PhpBench\Attributes as Bench;

/**
 * Raw read cost of every source: Tree::from…($input)->iterate(), no filter. Facade defaults (AutoAccessor).
 */
#[Bench\Revs(2)]
#[Bench\Iterations(5)]
#[Bench\Warmup(1)]
#[Bench\OutputTimeUnit('milliseconds', 2)]
#[Bench\Groups(['source'])]
final class SourceBench extends BenchCase
{
    /** @var list<array<string, mixed>> */
    private array $nested = [];

    /** @var list<array{0: int, 1: array<string, mixed>}> */
    private array $pairs = [];

    /**
     * @param array{shape: string, nodes: int, reps: int} $params
     */
    public function setUpNested(array $params): void
    {
        $this->nested = Fixture::nested($params['shape']);
        $this->reps = $params['reps'];
        memory_reset_peak_usage();
    }

    /**
     * @param array{shape: string, nodes: int, reps: int} $params
     */
    public function setUpPairs(array $params): void
    {
        $this->pairs = Fixture::depthPairs($params['shape']);
        $this->reps = $params['reps'];
        memory_reset_peak_usage();
    }

    #[Bench\BeforeMethods('setUpRows')]
    #[Bench\ParamProviders('allShapes')]
    public function benchNestedSet(): int
    {
        return $this->iterate(Tree::fromNestedSet($this->rows));
    }

    /** Rows in pre-order, but the source does not know it: builds its O(n) index on every open(). */
    #[Bench\BeforeMethods('setUpRows')]
    #[Bench\ParamProviders('allShapes')]
    public function benchAdjacencyList(): int
    {
        return $this->iterate(Tree::fromAdjacencyList($this->rows));
    }

    #[Bench\BeforeMethods('setUpRows')]
    #[Bench\ParamProviders('allShapes')]
    public function benchPreorderedAdjacency(): int
    {
        return $this->iterate(Tree::fromPreorderedAdjacencyList($this->rows));
    }

    #[Bench\BeforeMethods('setUpRows')]
    #[Bench\ParamProviders('pathShapes')]
    public function benchMaterializedPath(): int
    {
        return $this->iterate(Tree::fromMaterializedPath($this->rows));
    }

    #[Bench\BeforeMethods('setUpNested')]
    #[Bench\ParamProviders('allShapes')]
    public function benchNestedArray(): int
    {
        return $this->iterate(Tree::fromNestedArray($this->nested));
    }

    #[Bench\BeforeMethods('setUpPairs')]
    #[Bench\ParamProviders('allShapes')]
    public function benchDepthList(): int
    {
        return $this->iterate(Tree::from(DepthListSource::pairs($this->pairs)));
    }
}
