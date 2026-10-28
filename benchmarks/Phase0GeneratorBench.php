<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Benchmarks;

use Ghispi\TreeTransform\Benchmarks\Prototype\GeneratorPipeline;
use Ghispi\TreeTransform\Benchmarks\Prototype\InlineIntNestedSetCursor;
use Ghispi\TreeTransform\Benchmarks\Prototype\ListNestedSetCursor;
use Ghispi\TreeTransform\Benchmarks\Prototype\PruneClosureCursor;
use Ghispi\TreeTransform\Benchmarks\Prototype\SkipFlag;
use Ghispi\TreeTransform\Tree;
use PhpBench\Attributes as Bench;

/**
 * Phase 0 question (c): cursor classes vs generators (yield $depth => $node) for nested set → Prune (~90 %).
 *
 * Like-for-like: the prototype cursors and generators use the same inline key reads, the same validation and call
 * the predicate closure directly without Ancestors tracking. "x2" variants chain a second, always-true Prune to
 * show the cost per extra stage. The library facade (Tree::fromNestedSet()->filter()->count()) is the reference.
 */
#[Bench\Revs(3)]
#[Bench\Iterations(7)]
#[Bench\Warmup(1)]
#[Bench\OutputTimeUnit('milliseconds', 2)]
#[Bench\Groups(['phase0', 'generator'])]
#[Bench\BeforeMethods('setUpRows')]
#[Bench\ParamProviders('phase0Shapes')]
final class Phase0GeneratorBench extends BenchCase
{
    public function benchLibrary(): int
    {
        return Tree::fromNestedSet($this->rows)->filter(self::visible(...))->count();
    }

    public function benchLibraryX2(): int
    {
        return Tree::fromNestedSet($this->rows)->filter(self::visible(...))->filter(self::always(...))->count();
    }

    public function benchCursorIterator(): int
    {
        return self::drain(new PruneClosureCursor(
            new InlineIntNestedSetCursor(new \ArrayIterator($this->rows), 'lft', 'rgt', null),
            self::visible(...),
        ));
    }

    public function benchCursorList(): int
    {
        return self::drain(new PruneClosureCursor(new ListNestedSetCursor($this->rows, 'lft', 'rgt', null), self::visible(...)));
    }

    public function benchCursorListX2(): int
    {
        return self::drain(new PruneClosureCursor(
            new PruneClosureCursor(new ListNestedSetCursor($this->rows, 'lft', 'rgt', null), self::visible(...)),
            self::always(...),
        ));
    }

    public function benchGeneratorSend(): int
    {
        return self::consume(GeneratorPipeline::pruneSend(GeneratorPipeline::nestedSetSend($this->rows), self::visible(...)));
    }

    public function benchGeneratorSendX2(): int
    {
        return self::consume(GeneratorPipeline::pruneSend(
            GeneratorPipeline::pruneSend(GeneratorPipeline::nestedSetSend($this->rows), self::visible(...)),
            self::always(...),
        ));
    }

    public function benchGeneratorFlag(): int
    {
        $flag = new SkipFlag();

        return self::consume(GeneratorPipeline::pruneFlag(GeneratorPipeline::nestedSetFlag($this->rows, $flag), $flag, self::visible(...)));
    }

    public function benchGeneratorFlagX2(): int
    {
        $flag = new SkipFlag();

        return self::consume(GeneratorPipeline::pruneFlag(
            GeneratorPipeline::pruneFlag(GeneratorPipeline::nestedSetFlag($this->rows, $flag), $flag, self::visible(...)),
            $flag,
            self::always(...),
        ));
    }

    /**
     * Generator equivalent of drain(): reads depth and node of every entry.
     *
     * @param \Generator<int, mixed> $generator
     */
    private static function consume(\Generator $generator): int
    {
        $count = 0;
        foreach ($generator as $depth => $node) {
            ++$count;
        }

        return $count;
    }

    /**
     * @param array<string, mixed> $node
     */
    private static function visible(array $node): bool
    {
        return $node['visible'] === true;
    }

    private static function always(mixed $node): bool
    {
        return true;
    }
}
