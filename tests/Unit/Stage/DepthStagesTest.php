<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Tests\Unit\Stage;

use Ghispi\TreeTransform\Exception\InvalidArgumentException;
use Ghispi\TreeTransform\Source\DepthListSource;
use Ghispi\TreeTransform\Stage\DepthWindow;
use Ghispi\TreeTransform\Stage\MaxDepth;
use Ghispi\TreeTransform\Tests\Fixtures\Field;
use Ghispi\TreeTransform\Tests\Fixtures\RunningExample;
use Ghispi\TreeTransform\Tests\Fixtures\SpyCursor;
use Ghispi\TreeTransform\Tests\Fixtures\Trees;
use Ghispi\TreeTransform\Tree;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(MaxDepth::class)]
#[CoversClass(DepthWindow::class)]
final class DepthStagesTest extends TestCase
{
    /**
     * @return array<string, array{0: int, 1: string}>
     */
    public static function maxDepths(): array
    {
        return [
            'roots only' => [0, 'A, H'],
            'one level' => [1, 'A[B, F], H[I]'],
            'two levels' => [2, 'A[B[C, D], F[G]], H[I]'],
            'deeper than the tree' => [10, RunningExample::WHOLE],
        ];
    }

    #[DataProvider('maxDepths')]
    public function testMaxDepth(int $max, string $expected): void
    {
        self::assertSame($expected, Trees::compactOf(Tree::from(RunningExample::source())->maxDepth($max)));
    }

    public function testMaxDepthSkipsCutSubtreesInTheSource(): void
    {
        $spy = SpyCursor::on(RunningExample::source());
        Trees::pairs(new MaxDepth(1)->apply($spy));

        self::assertSame(['B', 'F', 'I'], $spy->skipped);
        self::assertSame(['A', 'B', 'F', 'H', 'I'], $spy->visited);
    }

    public function testNegativeMaxDepthIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new MaxDepth(-1);
    }

    /**
     * @return array<string, array{0: int, 1: int, 2: string}>
     */
    public static function windows(): array
    {
        return [
            'everything' => [0, \PHP_INT_MAX, RunningExample::WHOLE],
            'without roots' => [1, \PHP_INT_MAX, 'B[C, D[E]], F[G], I'],
            'one level' => [1, 1, 'B, F, I'],
            'levels 1-2' => [1, 2, 'B[C, D], F[G], I'],
            'deepest only' => [3, 3, 'E'],
            'below the tree' => [4, 9, ''],
        ];
    }

    #[DataProvider('windows')]
    public function testDepthWindow(int $min, int $max, string $expected): void
    {
        self::assertSame($expected, Trees::compactOf(Tree::from(RunningExample::source())->depthWindow($min, $max)));
    }

    public function testSpecExampleWindow(): void
    {
        $rows = [[0, ['id' => 'Shop']], [1, ['id' => 'Clothing']], [2, ['id' => 'Men']], [3, ['id' => 'Shirts']]];

        self::assertSame('Clothing[Men]', Trees::compactOf(Tree::from(DepthListSource::pairs($rows))->depthWindow(1, 2)));
    }

    /**
     * @return array<string, array{0: int, 1: int}>
     */
    public static function invalidWindows(): array
    {
        return ['negative min' => [-1, 2], 'max below min' => [2, 1]];
    }

    #[DataProvider('invalidWindows')]
    public function testInvalidWindowIsRejected(int $min, int $max): void
    {
        $this->expectException(InvalidArgumentException::class);

        new DepthWindow($min, $max);
    }

    public function testConsumerSkipIsForwarded(): void
    {
        $spy = SpyCursor::on(RunningExample::source());
        $cursor = new DepthWindow(1)->apply($spy);
        $seen = [];
        while ($cursor->next()) {
            $seen[] = [$cursor->depth(), $cursor->node()];
            if (Field::id($cursor->node()) === 'B') {
                $cursor->skipSubtree();
            }
        }

        self::assertSame('B, F[G], I', Trees::compact($seen));
        self::assertSame(['B'], $spy->skipped);
    }

    public function testDepthWindowDefaultsKeepTheWholeTree(): void
    {
        self::assertSame(RunningExample::WHOLE, Trees::compactOf(Tree::from(RunningExample::source())->pipe(new DepthWindow())));
    }
}
