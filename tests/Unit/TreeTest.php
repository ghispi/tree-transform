<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Tests\Unit;

use Ghispi\TreeTransform\Sink\FlatListSink;
use Ghispi\TreeTransform\Source\DepthListSource;
use Ghispi\TreeTransform\Stage\MaxDepth;
use Ghispi\TreeTransform\Strategy\KeepAncestors;
use Ghispi\TreeTransform\Strategy\MatchState;
use Ghispi\TreeTransform\Tests\Fixtures\Field;
use Ghispi\TreeTransform\Tests\Fixtures\RunningExample;
use Ghispi\TreeTransform\Tests\Fixtures\Trees;
use Ghispi\TreeTransform\Tree;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Tree::class)]
final class TreeTest extends TestCase
{
    public function testEveryMethodReturnsANewInstanceAndLeavesTheOriginalUnchanged(): void
    {
        $base = Tree::from(RunningExample::source());
        $variants = [
            $base->filter(RunningExample::matches()),
            $base->activeTrail(RunningExample::isId('E')),
            $base->annotate(RunningExample::matches()),
            $base->map(static fn(mixed $n): mixed => $n),
            $base->maxDepth(1),
            $base->depthWindow(1),
            $base->limit(2),
            $base->sortSiblings(static fn(): int => 0),
            $base->pipe(new MaxDepth(0)),
        ];

        foreach ($variants as $variant) {
            self::assertNotSame($base, $variant);
        }
        self::assertSame(RunningExample::WHOLE, Trees::compactOf($base));
    }

    public function testConfiguredTreeIsAReusableTemplate(): void
    {
        $searched = Tree::from(RunningExample::source())->filter(RunningExample::matches(), new KeepAncestors());

        self::assertSame('A[B]', Trees::compactOf($searched->maxDepth(1)->filter(RunningExample::isId('A', 'B'))));
        self::assertSame('A[B[C, D[E]]], H[I]', Trees::compactOf($searched));
    }

    public function testNothingRunsBeforeATerminal(): void
    {
        $calls = 0;
        $tree = Tree::from(RunningExample::source())->filter(static function () use (&$calls): bool {
            ++$calls;

            return true;
        });
        $iterator = $tree->iterate();
        self::assertSame(0, $calls);

        $iterator->current();
        self::assertSame(1, $calls);
    }

    public function testIterateYieldsDepthKeys(): void
    {
        $pairs = [];
        foreach (Tree::from(RunningExample::source())->maxDepth(1) as $depth => $node) {
            $pairs[] = $depth . ':' . Field::id($node);
        }

        self::assertSame(['0:A', '1:B', '1:F', '0:H', '1:I'], $pairs);
    }

    public function testIterateAndFromDepthListRoundTrip(): void
    {
        $tree = Tree::from(RunningExample::source())->filter(RunningExample::matches(), new KeepAncestors());

        self::assertSame(Trees::compactOf($tree), Trees::compactOf(Tree::fromDepthList($tree->iterate())));
        self::assertSame(Trees::compactOf($tree), Trees::compactOf(Tree::fromDepthList($tree->getIterator())));
    }

    public function testNamedConstructorsReadTheRunningExample(): void
    {
        $rows = RunningExample::rows();

        self::assertSame(RunningExample::WHOLE, Trees::compactOf(Tree::fromNestedSet($rows)));
        self::assertSame(RunningExample::WHOLE, Trees::compactOf(Tree::fromAdjacencyList(array_reverse($rows), siblingOrder: 'lft')));
        self::assertSame(RunningExample::WHOLE, Trees::compactOf(Tree::fromPreorderedAdjacencyList($rows)));
        self::assertSame(RunningExample::WHOLE, Trees::compactOf(Tree::fromMaterializedPath($rows)));
        self::assertSame(RunningExample::WHOLE, Trees::compactOf(Tree::fromNestedArray(RunningExample::nestedArrays())));
    }

    public function testAdjacencyFacadePassesOrphanPolicyAndRootParent(): void
    {
        $rows = [['id' => 'a', 'parent_id' => 0], ['id' => 'b', 'parent_id' => 'x']];

        self::assertSame(1, Tree::fromAdjacencyList($rows, orphans: \Ghispi\TreeTransform\Source\OrphanPolicy::Drop, rootParent: 0)->count());
        self::assertSame(1, Tree::fromPreorderedAdjacencyList([['id' => 'a', 'parent_id' => 0]], rootParent: 0)->count());
    }

    public function testLazyRebuildsTheSourceForEveryOutput(): void
    {
        $opened = 0;
        $tree = Tree::lazy(static function () use (&$opened): \Ghispi\TreeTransform\Source\Source {
            ++$opened;

            return new \Ghispi\TreeTransform\Source\NestedSetSource((static function (): \Generator {
                yield from RunningExample::rows();
            })());
        });

        self::assertSame(9, $tree->count());
        self::assertSame(RunningExample::WHOLE, Trees::compactOf($tree));
        self::assertSame(2, $opened);
    }

    public function testAnnotateWithCombine(): void
    {
        $states = Tree::from(RunningExample::source())
            ->annotate(RunningExample::isId('I'), static fn(mixed $n, MatchState $s): string => $s->value)
            ->maxDepth(0)
            ->toFlatList();

        self::assertSame([['depth' => 0, 'node' => 'none'], ['depth' => 0, 'node' => 'ancestor']], $states);
    }

    public function testCursorAndInto(): void
    {
        $tree = Tree::from(DepthListSource::pairs([[0, ['id' => 'a']]]));
        $cursor = $tree->cursor();

        self::assertTrue($cursor->next());
        self::assertSame(['id' => 'a'], $cursor->node());
        self::assertSame([['depth' => 0, 'node' => ['id' => 'a']]], $tree->into(new FlatListSink()));
    }

    public function testDeepChainOfTenThousandLevelsInEverySource(): void
    {
        $depth = 10_000;
        $nestedSet = [];
        $adjacency = [];
        for ($i = 0; $i < $depth; ++$i) {
            $nestedSet[] = ['id' => $i, 'lft' => $i + 1, 'rgt' => 2 * $depth - $i];
            $adjacency[] = ['id' => $i, 'parent_id' => $i === 0 ? null : $i - 1];
        }
        $nested = ['id' => $depth - 1];
        for ($i = $depth - 2; $i >= 0; --$i) {
            $nested = ['id' => $i, 'children' => [$nested]];
        }

        foreach ([
            'nested set' => Tree::fromNestedSet($nestedSet),
            'adjacency' => Tree::fromAdjacencyList(array_reverse($adjacency)),
            'pre-ordered adjacency' => Tree::fromPreorderedAdjacencyList($adjacency),
            'nested array' => Tree::fromNestedArray([$nested]),
        ] as $name => $tree) {
            $last = -1;
            foreach ($tree->filter(static fn(): bool => true, new KeepAncestors())->iterate() as $d => $node) {
                $last = $d;
            }
            self::assertSame($depth - 1, $last, $name);
            self::assertSame([], Trees::nestedSetProblems($tree->toNestedSet()), $name);
            self::assertSame($depth, $tree->filter(static fn(mixed $n): bool => Field::int($n, 'id') % 2 === 0, new \Ghispi\TreeTransform\Strategy\Promote())->count() * 2, $name);
        }
    }

    public function testDeepMaterializedPath(): void
    {
        // Materialized paths grow with depth (O(depth²) bytes in total), so a shallower chain.
        $depth = 2_000;
        $rows = (static function () use ($depth): \Generator {
            $path = '';
            for ($i = 0; $i < $depth; ++$i) {
                $path .= $i . ',';
                yield ['id' => $i, 'path' => $path];
            }
        })();

        self::assertSame($depth, Tree::fromMaterializedPath($rows)->filter(static fn(): bool => true, new KeepAncestors())->count());
    }
}
