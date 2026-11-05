<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Tests\Unit\Sink;

use Ghispi\TreeTransform\Exception\InvalidArgumentException;
use Ghispi\TreeTransform\Sink\AdjacencyListSink;
use Ghispi\TreeTransform\Sink\CountSink;
use Ghispi\TreeTransform\Sink\FlatListSink;
use Ghispi\TreeTransform\Sink\NestedSetSink;
use Ghispi\TreeTransform\Source\AdjacencyListFields;
use Ghispi\TreeTransform\Source\NestedSetFields;
use Ghispi\TreeTransform\Strategy\Promote;
use Ghispi\TreeTransform\Tests\Fixtures\Field;
use Ghispi\TreeTransform\Tests\Fixtures\Model\GetterNode;
use Ghispi\TreeTransform\Tests\Fixtures\Model\PublicNode;
use Ghispi\TreeTransform\Tests\Fixtures\RunningExample;
use Ghispi\TreeTransform\Tests\Fixtures\Trees;
use Ghispi\TreeTransform\Tree;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AdjacencyListSink::class)]
#[CoversClass(NestedSetSink::class)]
#[CoversClass(FlatListSink::class)]
#[CoversClass(CountSink::class)]
final class FlatSinksTest extends TestCase
{
    /**
     * @param list<mixed> $rows
     *
     * @return array<string, mixed> id => field
     */
    private static function column(array $rows, string $field): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[(string) Field::id($row)] = Field::get($row, $field);
        }

        return $out;
    }

    private static function promoted(): Tree
    {
        return Tree::from(RunningExample::source())->filter(RunningExample::matches(), new Promote());
    }

    public function testAdjacencyListRewritesParentsAfterPromote(): void
    {
        self::assertSame(['A' => null, 'C' => 'A', 'E' => 'A', 'I' => null], self::column(self::promoted()->toAdjacencyList(), 'parent_id'));
    }

    public function testAdjacencyListFieldNamesAndRootParent(): void
    {
        $rows = self::promoted()->map(static fn(mixed $n): array => ['key' => Field::id($n)])->toAdjacencyList(new AdjacencyListFields('key', 'up'), rootParent: 0);

        self::assertSame([['key' => 'A', 'up' => 0], ['key' => 'C', 'up' => 'A'], ['key' => 'E', 'up' => 'A'], ['key' => 'I', 'up' => 0]], $rows);
    }

    public function testAdjacencyListNodeFactoryForObjects(): void
    {
        $rows = Tree::fromNestedSet([new PublicNode('a', lft: 1, rgt: 4), new PublicNode('b', lft: 2, rgt: 3)])
            ->toAdjacencyList(nodeFactory: static fn(mixed $n, int|string|null $parent, int $depth): string => Field::id($n) . '<' . ($parent ?? '-') . '@' . $depth);

        self::assertSame(['a<-@0', 'b<a@1'], $rows);
    }

    public function testAdjacencyListReadsIdsWithTheGivenAccessor(): void
    {
        $sink = new AdjacencyListSink(nodeFactory: static fn(mixed $n, mixed $parent): mixed => $parent, accessor: new \Ghispi\TreeTransform\Accessor\GetterAccessor());
        $rows = Tree::fromNestedArray([new GetterNode('a', children: [new GetterNode('b')])])->into($sink);

        self::assertSame([null, 'a'], $rows);
    }

    public function testAdjacencyListRejectsObjectsWithoutFactory(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('AdjacencyListSink cannot set the parent of ' . PublicNode::class . ' payloads');

        Tree::fromNestedSet([new PublicNode('a', lft: 1, rgt: 2)])->toAdjacencyList();
    }

    public function testNestedSetRecomputesValuesAfterPromote(): void
    {
        $rows = self::promoted()->toNestedSet();

        self::assertSame(['A' => 1, 'C' => 2, 'E' => 4, 'I' => 7], self::column($rows, 'lft'));
        self::assertSame(['A' => 6, 'C' => 3, 'E' => 5, 'I' => 8], self::column($rows, 'rgt'));
        self::assertSame(['A' => 0, 'C' => 1, 'E' => 1, 'I' => 0], self::column($rows, 'lvl'));
        self::assertSame([], Trees::nestedSetProblems($rows));
    }

    public function testNestedSetSinkDefaults(): void
    {
        $rows = new NestedSetSink()->consume(self::promoted()->cursor());

        self::assertSame(['A' => 1, 'C' => 2, 'E' => 4, 'I' => 7], self::column($rows, 'lft'), 'one numbering across roots');
        self::assertSame(['A' => 6, 'C' => 3, 'E' => 5, 'I' => 8], self::column($rows, 'rgt'));
        self::assertSame(['A' => 0, 'C' => 1, 'E' => 1, 'I' => 0], self::column($rows, 'lvl'), 'roots at level 0');
    }

    public function testNestedSetRestartPerRootAndLevelBase(): void
    {
        $rows = self::promoted()->toNestedSet(new NestedSetFields('l', 'r', 'root', 'level'), 1);

        self::assertSame(['A' => 1, 'C' => 2, 'E' => 4, 'I' => 1], self::column($rows, 'l'));
        self::assertSame(['A' => 6, 'C' => 3, 'E' => 5, 'I' => 2], self::column($rows, 'r'));
        self::assertSame(['A' => 1, 'C' => 2, 'E' => 2, 'I' => 1], self::column($rows, 'level'));
        self::assertSame(['A' => 'A', 'C' => 'A', 'E' => 'A', 'I' => 'I'], self::column($rows, 'root'), 'new root ids after Promote');
    }

    /**
     * @return array<string, array{0: list<mixed>, 1: string}>
     */
    public static function invalidRootIds(): array
    {
        return [
            'root without id' => [[['n' => 'a']], 'needs an int, string or Stringable id for every root to write the root field "root"; got null'],
            'float id' => [[['id' => 1.5]], 'got float'],
            'array id' => [[['id' => [1]]], 'got array'],
            'same id twice' => [[['id' => 5], ['id' => '5']], 'two consecutive roots have the root id 5'],
        ];
    }

    /**
     * @param list<mixed> $roots
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidRootIds')]
    public function testNestedSetRejectsUnusableRootIds(array $roots, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        Tree::fromNestedArray($roots)->toNestedSet(NestedSetFields::gedmo());
    }

    public function testNestedSetAcceptsStringableAndRepeatedNonAdjacentRootIds(): void
    {
        $id = new class implements \Stringable {
            public function __toString(): string
            {
                return 'r';
            }
        };
        $rows = Tree::fromNestedArray([['id' => $id], ['id' => 2], ['id' => 'r']])->toNestedSet(new NestedSetFields(root: 'root'));

        self::assertSame([1, 1, 1], array_column($rows, 'lft'));
        self::assertSame([$id, 2, 'r'], array_column($rows, 'root'));
    }

    public function testNestedSetRootIdsThroughIdFieldAndNodeFactory(): void
    {
        $rows = self::promoted()
            ->map(static fn(mixed $n): array => ['key' => Field::id($n)])
            ->toNestedSet(
                new NestedSetFields(root: 'tree'),
                nodeFactory: static fn(array $n, int $l, int $r, int $d, mixed $root): array => [$n['key'], $root],
                idField: 'key',
            );

        self::assertSame([['A', 'A'], ['C', 'A'], ['E', 'A'], ['I', 'I']], $rows);
    }

    public function testNestedSetWithoutLevelField(): void
    {
        $rows = Tree::fromDepthList([['id' => 'a']])->toNestedSet(new NestedSetFields());

        self::assertSame([['id' => 'a', 'lft' => 1, 'rgt' => 2]], $rows);
    }

    public function testNestedSetNodeFactory(): void
    {
        $rows = self::promoted()->toNestedSet(nodeFactory: static fn(mixed $n, int $l, int $r, int $d): string => Field::id($n) . "{$l}-{$r}@{$d}");

        self::assertSame(['A1-6@0', 'C2-3@1', 'E4-5@1', 'I7-8@0'], $rows);
    }

    public function testNestedSetRejectsObjectsWithoutFactory(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('NestedSetSink cannot set nested set values on ' . PublicNode::class);

        Tree::fromNestedSet([new PublicNode('a', lft: 1, rgt: 2)])->toNestedSet();
    }

    public function testNestedSetOfEmptyTree(): void
    {
        self::assertSame([], Tree::fromNestedSet([])->toNestedSet());
    }

    public function testFlatListAndCount(): void
    {
        $tree = Tree::from(RunningExample::source())->filter(RunningExample::matches(), new Promote());

        self::assertSame(['0:A', '1:C', '1:E', '0:I'], array_map(static fn(array $e): string => $e['depth'] . ':' . Field::id($e['node']), $tree->toFlatList()));
        self::assertSame(4, $tree->count());
        self::assertSame(4, $tree->into(new CountSink()));
        self::assertSame(0, Tree::fromNestedSet([])->count());
    }
}
