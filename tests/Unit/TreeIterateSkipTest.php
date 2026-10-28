<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Tests\Unit;

use Ghispi\TreeTransform\Strategy\KeepAncestors;
use Ghispi\TreeTransform\Tests\Fixtures\Field;
use Ghispi\TreeTransform\Tests\Fixtures\RunningExample;
use Ghispi\TreeTransform\Tests\Fixtures\User\PassThroughStage;
use Ghispi\TreeTransform\Tests\Fixtures\User\SpyingSource;
use Ghispi\TreeTransform\Tree;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tree::iterate() lets the consumer skip a subtree with send(true), like Cursor::skipSubtree().
 */
#[CoversClass(Tree::class)]
final class TreeIterateSkipTest extends TestCase
{
    /**
     * Walks the generator; ids in $skip have their subtree skipped.
     *
     * @param list<string> $skip
     *
     * @return list<string> "depth:id"
     */
    private static function walk(\Generator $generator, array $skip): array
    {
        $seen = [];
        while ($generator->valid()) {
            $id = (string) Field::id($generator->current());
            $key = $generator->key();
            $seen[] = (\is_int($key) ? $key : -1) . ':' . $id;
            if (\in_array($id, $skip, true)) {
                $generator->send(true);
            } else {
                $generator->next();
            }
        }

        return $seen;
    }

    public function testSendTrueSkipsTheSubtreeAndReturnsTheNextNodeAfterIt(): void
    {
        $generator = Tree::from(RunningExample::source())->iterate();
        self::assertSame('A', Field::id($generator->current()));
        $generator->next();
        self::assertSame('B', Field::id($generator->current()));

        $next = $generator->send(true);

        self::assertSame('F', Field::id($next));
        self::assertSame(1, $generator->key());
    }

    public function testSkippingASubtreeLeavesTheRest(): void
    {
        $seen = self::walk(Tree::from(RunningExample::source())->iterate(), ['B', 'H']);

        self::assertSame(['0:A', '1:B', '1:F', '2:G', '0:H'], $seen);
    }

    public function testSkippingTheLastRootEndsTheIteration(): void
    {
        $generator = Tree::from(RunningExample::source())->iterate();
        $seen = self::walk($generator, ['H']);

        self::assertSame('0:H', $seen[\count($seen) - 1]);
        self::assertFalse($generator->valid());
        self::assertNull($generator->current());
    }

    public function testWithoutSendTheIterationIsUnchanged(): void
    {
        $tree = Tree::from(RunningExample::source());
        $seen = [];
        foreach ($tree->iterate() as $depth => $node) {
            $seen[] = $depth . ':' . Field::id($node);
        }

        self::assertSame(['0:A', '1:B', '2:C', '2:D', '3:E', '1:F', '2:G', '0:H', '1:I'], $seen);
        self::assertSame($seen, self::walk($tree->iterate(), []));
        self::assertSame($seen, self::walk($tree->getIterator(), []));
    }

    public function testSkipWorksThroughStages(): void
    {
        $tree = Tree::from(RunningExample::source())
            ->filter(static fn(): bool => true, new KeepAncestors())
            ->map(static fn(mixed $n): mixed => $n)
            ->maxDepth(2)
            ->limit(8);

        self::assertSame(['0:A', '1:B', '1:F', '2:G', '0:H', '1:I'], self::walk($tree->iterate(), ['B']));
    }

    public function testSkipReachesAUserSourceThroughAUserStage(): void
    {
        $source = new SpyingSource(RunningExample::source());
        $stage = new PassThroughStage();
        $tree = Tree::from($source)->pipe($stage)->maxDepth(3);

        $seen = self::walk($tree->iterate(), ['B']);

        self::assertSame(['0:A', '1:B', '1:F', '2:G', '0:H', '1:I'], $seen);
        self::assertNotNull($source->spy);
        self::assertSame(['A', 'B', 'F', 'G', 'H', 'I'], $source->spy->visited);
        self::assertSame(['B'], $source->spy->skipped);
    }

    public function testIterateTwiceGivesIndependentGenerators(): void
    {
        $tree = Tree::from(RunningExample::source());

        self::assertCount(5, self::walk($tree->iterate(), ['B', 'F']));
        self::assertCount(9, self::walk($tree->iterate(), []));
    }
}
