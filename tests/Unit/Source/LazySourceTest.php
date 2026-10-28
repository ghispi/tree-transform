<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Tests\Unit\Source;

use Ghispi\TreeTransform\Source\LazySource;
use Ghispi\TreeTransform\Source\NestedSetSource;
use Ghispi\TreeTransform\Source\Source;
use Ghispi\TreeTransform\Tests\Fixtures\Matrix;
use Ghispi\TreeTransform\Tests\Fixtures\RunningExample;
use Ghispi\TreeTransform\Tests\Fixtures\Trees;
use Ghispi\TreeTransform\Tree;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LazySource::class)]
final class LazySourceTest extends TestCase
{
    public function testFactoryRunsOnEveryOpenAndNotBefore(): void
    {
        $calls = 0;
        $source = new LazySource(static function () use (&$calls): Source {
            ++$calls;

            return RunningExample::source();
        });
        self::assertSame(0, $calls);

        $source->open();
        $source->open();

        self::assertSame(2, $calls);
    }

    public function testGeneratorBackedSourceCanBeReadRepeatedly(): void
    {
        $source = new LazySource(static fn(): Source => new NestedSetSource(Matrix::generate(RunningExample::rows())));

        self::assertSame(RunningExample::WHOLE, Trees::compactOf($source));
        self::assertCount(9, Trees::pairs($source), 'second read');
    }

    public function testTreeLazyGivesSeveralOutputsFromOneTree(): void
    {
        $tree = Tree::lazy(static fn(): Source => new NestedSetSource(Matrix::generate(RunningExample::rows())))
            ->filter(RunningExample::matches(), new \Ghispi\TreeTransform\Strategy\KeepAncestors());

        self::assertSame(7, $tree->count());
        self::assertCount(7, $tree->toFlatList());
        self::assertSame('A[B[C, D[E]]], H[I]', Trees::compactOf($tree));
    }
}
