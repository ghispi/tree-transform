<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Benchmarks;

use Ghispi\TreeTransform\Accessor\ArrayKeyAccessor;
use Ghispi\TreeTransform\Benchmarks\Prototype\ListNestedSetCursor;
use Ghispi\TreeTransform\Benchmarks\Prototype\PruneClosureCursor;
use Ghispi\TreeTransform\Benchmarks\Prototype\PruneNoAncestorsCursor;
use Ghispi\TreeTransform\Cursor\Cursor;
use Ghispi\TreeTransform\Predicate\Callback;
use Ghispi\TreeTransform\Source\NestedSetFields;
use Ghispi\TreeTransform\Source\NestedSetSource;
use Ghispi\TreeTransform\Strategy\Prune;
use PhpBench\Attributes as Bench;

/**
 * Phase 0 question (b): cost of Ancestors tracking (Ancestors::enter() per node) in PruneCursor, and of the
 * Callback wrapper. Prune with the ~90 % "visible" predicate (every row is read, most are tested).
 *
 * Measured over two sources: the library's NestedSetSource (ArrayKeyAccessor) and the fastest prototype source
 * (ListNestedSetCursor), where the strategy's share of the total is larger.
 */
#[Bench\Revs(3)]
#[Bench\Iterations(7)]
#[Bench\Warmup(1)]
#[Bench\OutputTimeUnit('milliseconds', 2)]
#[Bench\Groups(['phase0', 'ancestors'])]
#[Bench\BeforeMethods('setUpRows')]
#[Bench\ParamProviders('phase0Shapes')]
final class Phase0AncestorsBench extends BenchCase
{
    public function benchLibraryPrune(): int
    {
        return self::drain((new Prune())->apply($this->source(), Callback::of(self::visible(...))));
    }

    public function benchNoAncestors(): int
    {
        return self::drain(new PruneNoAncestorsCursor($this->source(), Callback::of(self::visible(...))));
    }

    public function benchNoAncestorsDirectClosure(): int
    {
        return self::drain(new PruneClosureCursor($this->source(), self::visible(...)));
    }

    public function benchListSourceLibraryPrune(): int
    {
        return self::drain((new Prune())->apply($this->listSource(), Callback::of(self::visible(...))));
    }

    public function benchListSourceNoAncestors(): int
    {
        return self::drain(new PruneNoAncestorsCursor($this->listSource(), Callback::of(self::visible(...))));
    }

    public function benchListSourceNoAncestorsDirectClosure(): int
    {
        return self::drain(new PruneClosureCursor($this->listSource(), self::visible(...)));
    }

    /**
     * @param array<string, mixed> $node
     */
    private static function visible(array $node): bool
    {
        return $node['visible'] === true;
    }

    private function source(): Cursor
    {
        return (new NestedSetSource($this->rows, new NestedSetFields(), new ArrayKeyAccessor()))->open();
    }

    private function listSource(): Cursor
    {
        return new ListNestedSetCursor($this->rows, 'lft', 'rgt', null);
    }
}
