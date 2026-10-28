<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Benchmarks;

use Ghispi\TreeTransform\Accessor\AutoAccessor;
use Ghispi\TreeTransform\Benchmarks\Prototype\IndexedNestedArrayCursor;
use Ghispi\TreeTransform\Benchmarks\Support\Fixture;
use Ghispi\TreeTransform\Source\NestedArraySource;
use PhpBench\Attributes as Bench;

/**
 * NestedArraySource: one ArrayIterator per open level (library) vs (list, position) pairs (prototype).
 * Raw cursor loop. The deep chain shows the O(n * depth) cost of many live ArrayIterators.
 */
#[Bench\Revs(3)]
#[Bench\Iterations(7)]
#[Bench\Warmup(1)]
#[Bench\OutputTimeUnit('milliseconds', 2)]
#[Bench\Groups(['phase0', 'nested-array'])]
#[Bench\BeforeMethods('setUpNested')]
#[Bench\ParamProviders('shapes')]
final class Phase0NestedArrayBench extends BenchCase
{
    /** @var list<array<string, mixed>> */
    private array $nested = [];

    /**
     * @return \Generator<string, array{shape: string, nodes: int, reps: int}>
     */
    public function shapes(): \Generator
    {
        foreach ($this->allShapes() as $name => $set) {
            if (\in_array($set['shape'], ['balanced-300k', 'wide-100k', 'deep-10k', 'category-100k'], true)) {
                yield $name => $set;
            }
        }
    }

    /**
     * @param array{shape: string, nodes: int, reps: int} $params
     */
    public function setUpNested(array $params): void
    {
        $this->nested = Fixture::nested($params['shape']);
        $this->reps = $params['reps'];
        memory_reset_peak_usage();
    }

    public function benchLibrary(): int
    {
        return self::drain((new NestedArraySource($this->nested))->open());
    }

    public function benchIndexed(): int
    {
        return self::drain(new IndexedNestedArrayCursor($this->nested, (new AutoAccessor())->reader('children'), 'children'));
    }
}
