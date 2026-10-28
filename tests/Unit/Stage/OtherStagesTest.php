<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Tests\Unit\Stage;

use Ghispi\TreeTransform\Exception\InvalidArgumentException;
use Ghispi\TreeTransform\Stage\Filter;
use Ghispi\TreeTransform\Stage\Limit;
use Ghispi\TreeTransform\Stage\Map;
use Ghispi\TreeTransform\Stage\SortSiblings;
use Ghispi\TreeTransform\Strategy\KeepAncestors;
use Ghispi\TreeTransform\Tests\Fixtures\Field;
use Ghispi\TreeTransform\Tests\Fixtures\RunningExample;
use Ghispi\TreeTransform\Tests\Fixtures\SpyCursor;
use Ghispi\TreeTransform\Tests\Fixtures\Trees;
use Ghispi\TreeTransform\Tree;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Limit::class)]
#[CoversClass(Map::class)]
#[CoversClass(SortSiblings::class)]
#[CoversClass(Filter::class)]
final class OtherStagesTest extends TestCase
{
    public function testLimitKeepsAPreOrderPrefix(): void
    {
        $tree = Tree::from(RunningExample::source());

        self::assertSame('', Trees::compactOf($tree->limit(0)));
        self::assertSame('A[B[C, D]]', Trees::compactOf($tree->limit(4)));
        self::assertSame(RunningExample::WHOLE, Trees::compactOf($tree->limit(100)));
    }

    public function testLimitStopsReadingTheInput(): void
    {
        $spy = SpyCursor::on(RunningExample::source());
        $cursor = new Limit(2)->apply($spy);
        Trees::pairs($cursor);

        self::assertFalse($cursor->next());
        self::assertSame(['A', 'B'], $spy->visited);
    }

    public function testLimitZeroDoesNotReadAtAll(): void
    {
        $spy = SpyCursor::on(RunningExample::source());
        Trees::pairs(new Limit(0)->apply($spy));

        self::assertSame([], $spy->visited);
    }

    public function testLimitCountsOnlyEmittedNodesAndForwardsSkips(): void
    {
        $cursor = new Limit(3)->apply(RunningExample::source()->open());
        $seen = [];
        while ($cursor->next()) {
            $seen[] = [$cursor->depth(), $cursor->node()];
            $cursor->skipSubtree();
        }

        self::assertSame('A, H', Trees::compact($seen));
    }

    public function testNegativeLimitIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Limit(-1);
    }

    public function testMapRunsOnlyForSurvivingNodesWithTheirDepth(): void
    {
        $calls = [];
        $tree = Tree::from(RunningExample::source())
            ->filter(RunningExample::isId('A', 'B', 'F', 'H'))
            ->map(static function (mixed $n, int $depth) use (&$calls): string {
                $calls[] = Field::id($n) . $depth;

                return strtolower((string) Field::id($n));
            });

        self::assertSame(
            [['depth' => 0, 'node' => 'a'], ['depth' => 1, 'node' => 'b'], ['depth' => 1, 'node' => 'f'], ['depth' => 0, 'node' => 'h']],
            $tree->toFlatList(),
        );
        self::assertSame(['A0', 'B1', 'F1', 'H0'], $calls);
    }

    public function testMapIsLazyAndForwardsSkips(): void
    {
        $calls = 0;
        $cursor = new Map(static function (mixed $n) use (&$calls): mixed {
            ++$calls;

            return $n;
        })->apply(RunningExample::source()->open());
        self::assertSame(0, $calls);
        $seen = [];
        while ($cursor->next()) {
            $seen[] = [$cursor->depth(), $cursor->node()];
            $cursor->skipSubtree();
        }

        self::assertSame('A, H', Trees::compact($seen));
        self::assertSame(2, $calls);
        self::assertNull($cursor->node());
    }

    public function testSortSiblingsSortsRootsAndChildrenAndMovesSubtrees(): void
    {
        $desc = static fn(mixed $a, mixed $b): int => strcmp((string) Field::id($b), (string) Field::id($a));

        self::assertSame('H[I], A[F[G], B[D[E], C]]', Trees::compactOf(Tree::from(RunningExample::source())->sortSiblings($desc)));
    }

    public function testSortSiblingsIsStable(): void
    {
        $all = static fn(): int => 0;

        self::assertSame(RunningExample::WHOLE, Trees::compactOf(Tree::from(RunningExample::source())->sortSiblings($all)));
    }

    public function testSortSiblingsSupportsSkipAndEmptyInput(): void
    {
        $cursor = new SortSiblings(static fn(): int => 0)->apply(RunningExample::source()->open());
        self::assertNull($cursor->node());
        self::assertSame(0, $cursor->depth());
        $cursor->skipSubtree(); // before the first next(): no effect
        $seen = [];
        while ($cursor->next()) {
            $seen[] = [$cursor->depth(), $cursor->node()];
            $cursor->skipSubtree();
        }

        self::assertSame('A, H', Trees::compact($seen));
        self::assertSame(0, Tree::fromNestedSet([])->sortSiblings(static fn(): int => 0)->count());
    }

    public function testFilterAcceptsClosuresAndStrategies(): void
    {
        $cursor = new Filter(RunningExample::matches(), new KeepAncestors())->apply(RunningExample::source()->open());

        self::assertSame('A[B[C, D[E]]], H[I]', Trees::compact(Trees::pairs($cursor)));
        self::assertSame('A', Trees::compact(Trees::pairs(new Filter(RunningExample::matches())->apply(RunningExample::source()->open()))));
    }

    public function testChainedFiltersSeeTheAncestorsOfTheirInput(): void
    {
        $parents = [];
        Tree::from(RunningExample::source())
            ->filter(RunningExample::isId('A', 'E', 'I'), new \Ghispi\TreeTransform\Strategy\Promote())
            ->filter(static function (mixed $n, int $d, \Ghispi\TreeTransform\Predicate\Ancestors $a) use (&$parents): bool {
                $parents[Field::id($n)] = $a->parent() === null ? null : Field::id($a->parent());

                return true;
            })
            ->count();

        self::assertSame(['A' => null, 'E' => 'A', 'I' => null], $parents);
    }
}
