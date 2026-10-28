<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Benchmarks;

use Ghispi\TreeTransform\Benchmarks\Prototype\FastKeepAncestorsCursor;
use Ghispi\TreeTransform\Benchmarks\Prototype\FastNestedArraySink;
use Ghispi\TreeTransform\Benchmarks\Prototype\GeneratorPipeline;
use Ghispi\TreeTransform\Benchmarks\Prototype\ListNestedSetCursor;
use Ghispi\TreeTransform\Benchmarks\Prototype\PruneClosureCursor;
use Ghispi\TreeTransform\Benchmarks\Support\Naive;
use Ghispi\TreeTransform\Strategy\KeepAncestors;
use Ghispi\TreeTransform\Tree;
use PhpBench\Attributes as Bench;

/**
 * Whole pipelines, nested set rows → filter → nested array, against the naive baseline (SPEC §10 target: >= 2x
 * faster, lower memory). Shows how far the proposed cursor optimisations and a generator representation get.
 *
 * - Library:          Tree facade as shipped.
 * - LibraryFastSink:  library source and strategy, FastNestedArraySink (attributes the sink's share).
 * - CursorFast:       ListNestedSetCursor + Fast/Closure strategy cursor + FastNestedArraySink.
 * - Generator:        GeneratorPipeline source + strategy + toNestedArray().
 *
 * All variants produce identical output (benchmarks/verify.php).
 */
#[Bench\Revs(2)]
#[Bench\Iterations(7)]
#[Bench\Warmup(1)]
#[Bench\OutputTimeUnit('milliseconds', 2)]
#[Bench\Groups(['phase0', 'pipeline'])]
#[Bench\BeforeMethods('setUpRows')]
#[Bench\ParamProviders('phase0Shapes')]
final class Phase0PipelineBench extends BenchCase
{
    // ---- KeepAncestors (~10 % hits)

    public function benchNaiveKeepAncestors(): int
    {
        return \count(Naive::nestedSetKeepAncestors($this->rows, self::hit(...)));
    }

    public function benchLibraryKeepAncestors(): int
    {
        return \count(Tree::fromNestedSet($this->rows)->filter(self::hit(...), new KeepAncestors())->toNestedArray());
    }

    public function benchLibraryFastSinkKeepAncestors(): int
    {
        return \count((new FastNestedArraySink())->consume(Tree::fromNestedSet($this->rows)->filter(self::hit(...), new KeepAncestors())->cursor()));
    }

    public function benchCursorFastKeepAncestors(): int
    {
        return \count((new FastNestedArraySink())->consume(
            new FastKeepAncestorsCursor(new ListNestedSetCursor($this->rows, 'lft', 'rgt', null), self::hit(...)),
        ));
    }

    public function benchGeneratorKeepAncestors(): int
    {
        return \count(GeneratorPipeline::toNestedArray(
            GeneratorPipeline::keepAncestors(GeneratorPipeline::nestedSetSend($this->rows), self::hit(...)),
        ));
    }

    // ---- Prune (~90 % visible)

    public function benchNaivePrune(): int
    {
        return \count(Naive::nestedSetPrune($this->rows, self::visible(...)));
    }

    public function benchLibraryPrune(): int
    {
        return \count(Tree::fromNestedSet($this->rows)->filter(self::visible(...))->toNestedArray());
    }

    public function benchLibraryFastSinkPrune(): int
    {
        return \count((new FastNestedArraySink())->consume(Tree::fromNestedSet($this->rows)->filter(self::visible(...))->cursor()));
    }

    public function benchCursorFastPrune(): int
    {
        return \count((new FastNestedArraySink())->consume(
            new PruneClosureCursor(new ListNestedSetCursor($this->rows, 'lft', 'rgt', null), self::visible(...)),
        ));
    }

    public function benchGeneratorPrune(): int
    {
        return \count(GeneratorPipeline::toNestedArray(
            GeneratorPipeline::pruneSend(GeneratorPipeline::nestedSetSend($this->rows), self::visible(...)),
        ));
    }

    /**
     * @param array<string, mixed> $node
     */
    private static function hit(array $node): bool
    {
        return $node['hit'] === true;
    }

    /**
     * @param array<string, mixed> $node
     */
    private static function visible(array $node): bool
    {
        return $node['visible'] === true;
    }
}
