<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Benchmarks;

use Ghispi\TreeTransform\Accessor\ArrayKeyAccessor;
use Ghispi\TreeTransform\Accessor\AutoAccessor;
use Ghispi\TreeTransform\Benchmarks\Prototype\InlineIntNestedSetCursor;
use Ghispi\TreeTransform\Benchmarks\Prototype\InlineKeyNestedSetCursor;
use Ghispi\TreeTransform\Benchmarks\Prototype\ListNestedSetCursor;
use Ghispi\TreeTransform\Source\NestedSetFields;
use Ghispi\TreeTransform\Source\NestedSetSource;
use PhpBench\Attributes as Bench;

/**
 * Phase 0 question (a): cost of field access in NestedSetCursor. Raw cursor loop (next/depth/node), no facade.
 *
 * Steps, each including the previous one:
 *   AutoAccessor (default) → ArrayKeyAccessor → inline $row['lft'] → + inline is_int() → + list indexing.
 * AutoAccessorGeneratorRows isolates the ArrayIterator copy made by Rows::iterator() for array input.
 */
#[Bench\Revs(3)]
#[Bench\Iterations(7)]
#[Bench\Warmup(1)]
#[Bench\OutputTimeUnit('milliseconds', 2)]
#[Bench\Groups(['phase0', 'accessor'])]
#[Bench\BeforeMethods('setUpRows')]
#[Bench\ParamProviders('phase0Shapes')]
final class Phase0AccessorBench extends BenchCase
{
    public function benchAutoAccessor(): int
    {
        return self::drain((new NestedSetSource($this->rows, new NestedSetFields(), new AutoAccessor()))->open());
    }

    /** Same as benchAutoAccessor, rows handed over as a generator: Rows::iterator() then skips its ArrayIterator copy. */
    public function benchAutoAccessorGeneratorRows(): int
    {
        return self::drain((new NestedSetSource(self::generate($this->rows), new NestedSetFields(), new AutoAccessor()))->open());
    }

    public function benchArrayKeyAccessor(): int
    {
        return self::drain((new NestedSetSource($this->rows, new NestedSetFields(), new ArrayKeyAccessor()))->open());
    }

    public function benchInlineKey(): int
    {
        return self::drain(new InlineKeyNestedSetCursor(new \ArrayIterator($this->rows), 'lft', 'rgt', null));
    }

    public function benchInlineKeyInt(): int
    {
        return self::drain(new InlineIntNestedSetCursor(new \ArrayIterator($this->rows), 'lft', 'rgt', null));
    }

    public function benchInlineKeyIntList(): int
    {
        return self::drain(new ListNestedSetCursor($this->rows, 'lft', 'rgt', null));
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return \Generator<int, array<string, mixed>>
     */
    private static function generate(array $rows): \Generator
    {
        yield from $rows;
    }
}
