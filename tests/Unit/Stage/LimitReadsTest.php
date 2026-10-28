<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Tests\Unit\Stage;

use Ghispi\TreeTransform\Sink\CountSink;
use Ghispi\TreeTransform\Source\PreorderedAdjacencySource;
use Ghispi\TreeTransform\Stage\Limit;
use Ghispi\TreeTransform\Stage\MaxDepth;
use Ghispi\TreeTransform\Tests\Fixtures\Field;
use Ghispi\TreeTransform\Tests\Fixtures\RunningExample;
use Ghispi\TreeTransform\Tests\Fixtures\Trees;
use Ghispi\TreeTransform\Tests\Fixtures\User\ListingSink;
use Ghispi\TreeTransform\Tests\Fixtures\User\PassThroughStage;
use Ghispi\TreeTransform\Tree;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Limit never advances its input past the last node it emits, so a database cursor is not read further.
 */
#[CoversClass(Limit::class)]
final class LimitReadsTest extends TestCase
{
    /**
     * A->B->C, B->D, A->E, F: depths 0 1 2 2 1 0.
     */
    private const DEPTHS = ['A' => 0, 'B' => 1, 'C' => 2, 'D' => 2, 'E' => 1, 'F' => 0];

    /**
     * @return \Generator<int, array{id: string}>
     */
    private static function counting(int &$reads): \Generator
    {
        foreach (self::DEPTHS as $id => $depth) {
            ++$reads;
            yield $depth => ['id' => $id];
        }
    }

    /**
     * @param list<array{0: int, 1: mixed}> $pairs
     *
     * @return list<string>
     */
    private static function ids(array $pairs): array
    {
        return array_map(static fn(array $pair): string => (string) Field::id($pair[1]), $pairs);
    }

    /**
     * @return iterable<string, array{0: int}>
     */
    public static function counts(): iterable
    {
        foreach ([0, 1, 2, 3, 4, 5, 6, 7, 100] as $n) {
            yield "Limit({$n})" => [$n];
        }
    }

    #[DataProvider('counts')]
    public function testReadsExactlyAsManyRowsAsItEmits(int $n): void
    {
        $reads = 0;
        $tree = Tree::fromDepthList(self::counting($reads))->limit($n);

        $count = $tree->count();

        self::assertSame(min($n, 6), $count);
        // Opening a generator primes it (one row is read at open), also for Limit(0); never more than the limit.
        self::assertSame(max(1, min($n, 6)), $reads);
    }

    public function testLimitZeroEmitsNothingAndReadsOnlyThePrimingRow(): void
    {
        $reads = 0;

        self::assertSame([], Trees::pairs(Tree::fromDepthList(self::counting($reads))->limit(0)));
        self::assertSame(1, $reads);
    }

    public function testSkippingTheLastEmittedNodeReadsNothingMore(): void
    {
        // Limit(2) emits A, B; the consumer skips B's subtree, which Limit must not chase into the input.
        $reads = 0;
        $pairs = Tree::fromDepthList(self::counting($reads))->limit(2)->into(new ListingSink(skipBelow: 1));

        self::assertSame(['A', 'B'], self::ids($pairs));
        self::assertSame(2, $reads);
    }

    public function testDownstreamMaxDepthSkippingTheLastNodeReadsNothingMore(): void
    {
        $reads = 0;
        $tree = Tree::fromDepthList(self::counting($reads))->limit(2)->maxDepth(1);

        self::assertSame(2, $tree->count());
        self::assertSame(2, $reads);
    }

    public function testSkipOfTheOnlyEmittedNodeReadsNothingMore(): void
    {
        $reads = 0;
        $tree = Tree::fromDepthList(self::counting($reads))->limit(1)->maxDepth(0);

        self::assertSame(1, $tree->count());
        self::assertSame(1, $reads);
    }

    public function testSkipsBeforeTheLastNodeStillSkipInTheInput(): void
    {
        // Limit(4): A, B (skipped, so C and D are never emitted), E, F.
        $reads = 0;
        $pairs = Tree::fromDepthList(self::counting($reads))->limit(4)->into(new ListingSink(skipBelow: 1));

        self::assertSame(['A', 'B', 'E', 'F'], self::ids($pairs));
        self::assertSame(6, $reads); // C and D are read by the source (a plain generator cannot seek) but never emitted
    }

    public function testLimitAfterAUserStageReadsNothingExtraEither(): void
    {
        $reads = 0;
        $tree = Tree::fromDepthList(self::counting($reads))->pipe(new PassThroughStage())->limit(3);

        self::assertSame(3, $tree->into(new CountSink()));
        self::assertSame(3, $reads);
    }

    public function testPreorderedAdjacencyIsReadOnlyAsFarAsNeeded(): void
    {
        $reads = 0;
        $rows = (static function () use (&$reads): \Generator {
            foreach (RunningExample::rows() as $row) {
                ++$reads;
                yield $row;
            }
        })();

        $count = Tree::from(new PreorderedAdjacencySource($rows))->pipe(new Limit(3))->pipe(new MaxDepth(5))->count();

        self::assertSame(3, $count);
        self::assertLessThanOrEqual(4, $reads); // a source may look one row ahead, Limit adds nothing
    }
}
