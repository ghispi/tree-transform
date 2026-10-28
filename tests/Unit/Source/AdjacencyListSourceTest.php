<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Tests\Unit\Source;

use Ghispi\TreeTransform\Exception\CycleDetectedException;
use Ghispi\TreeTransform\Exception\InvalidTreeException;
use Ghispi\TreeTransform\Exception\LogicException;
use Ghispi\TreeTransform\Exception\OrphanNodeException;
use Ghispi\TreeTransform\Source\AdjacencyListCursor;
use Ghispi\TreeTransform\Source\AdjacencyListFields;
use Ghispi\TreeTransform\Source\AdjacencyListSource;
use Ghispi\TreeTransform\Source\OrphanPolicy;
use Ghispi\TreeTransform\Tests\Fixtures\Matrix;
use Ghispi\TreeTransform\Tests\Fixtures\Model\GetterNode;
use Ghispi\TreeTransform\Tests\Fixtures\Model\PublicNode;
use Ghispi\TreeTransform\Tests\Fixtures\Trees;
use Ghispi\TreeTransform\Tree;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(AdjacencyListSource::class)]
#[CoversClass(AdjacencyListCursor::class)]
#[CoversClass(AdjacencyListFields::class)]
final class AdjacencyListSourceTest extends TestCase
{
    /**
     * @return array{id: mixed, parent_id: mixed, sort?: int}
     */
    private static function n(mixed $id, mixed $parent = null, ?int $sort = null): array
    {
        return ['id' => $id, 'parent_id' => $parent] + ($sort === null ? [] : ['sort' => $sort]);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private static function read(array $rows, OrphanPolicy $orphans = OrphanPolicy::Throw, mixed $rootParent = null, ?string $siblingOrder = null): string
    {
        return Trees::compactOf(new AdjacencyListSource($rows, orphans: $orphans, rootParent: $rootParent, siblingOrder: $siblingOrder));
    }

    public function testAnyOrderKeepsInputOrderOfSiblings(): void
    {
        $rows = [self::n('c', 'a'), self::n('b', 'a'), self::n('a'), self::n('d', 'c'), self::n('z')];

        self::assertSame('a[c[d], b], z', self::read($rows));
    }

    public function testEmptyInput(): void
    {
        self::assertSame('', self::read([]));
    }

    public function testSiblingOrderField(): void
    {
        $rows = [self::n('c', 'a', 2), self::n('b', 'a', 1), self::n('z', null, 0), self::n('a', null, 5)];

        self::assertSame('z, a[b, c]', self::read($rows, siblingOrder: 'sort'));
    }

    public function testRootParentMarksRoots(): void
    {
        self::assertSame('a[b]', self::read([self::n('a', 0), self::n('b', 'a')], rootParent: 0));
    }

    public function testRootParentMatchesNumericStrings(): void
    {
        self::assertSame('1[2]', self::read([self::n('1', '0'), self::n('2', '1')], rootParent: 0));
    }

    public function testIntAndNumericStringIdsAreTheSameKey(): void
    {
        self::assertSame('1[2]', self::read([self::n(1), self::n(2, '1')]));
    }

    public function testOrphansThrowByDefault(): void
    {
        $this->expectException(OrphanNodeException::class);
        $this->expectExceptionMessage("Parent 'x' of 2 node(s) does not exist");

        self::read([self::n('a'), self::n('b', 'x'), self::n('c', 'x')]);
    }

    public function testOrphansAsRootKeepInputOrder(): void
    {
        $rows = [self::n('b', 'x'), self::n('a'), self::n('c', 'b'), self::n('d', 'y')];

        self::assertSame('b[c], a, d', self::read($rows, OrphanPolicy::AsRoot));
    }

    public function testOrphansDroppedWithTheirSubtrees(): void
    {
        $rows = [self::n('b', 'x'), self::n('a'), self::n('c', 'b'), self::n('e', 'a')];

        self::assertSame('a[e]', self::read($rows, OrphanPolicy::Drop));
    }

    /**
     * @return array<string, array{0: list<array<string, mixed>>, 1: OrphanPolicy}>
     */
    public static function cycles(): array
    {
        $twoCycle = [self::n('r'), self::n('a', 'b'), self::n('b', 'a')];

        return [
            'self parent' => [[self::n('a', 'a')], OrphanPolicy::Throw],
            'two-node cycle' => [$twoCycle, OrphanPolicy::Throw],
            'cycle with orphans dropped' => [[...$twoCycle, self::n('o', 'missing')], OrphanPolicy::Drop],
            'cycle with orphans as roots' => [[...$twoCycle, self::n('o', 'missing')], OrphanPolicy::AsRoot],
            'cycle next to a tree with children' => [[self::n('r'), self::n('c', 'r'), self::n('a', 'b'), self::n('b', 'a')], OrphanPolicy::Throw],
            'cycle below a cycle' => [[self::n('a', 'c'), self::n('b', 'a'), self::n('c', 'b'), self::n('d', 'c')], OrphanPolicy::Throw],
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    #[DataProvider('cycles')]
    public function testCyclesThrow(array $rows, OrphanPolicy $orphans): void
    {
        $this->expectException(CycleDetectedException::class);

        self::read($rows, $orphans);
    }

    public function testCycleMessageNamesUnreachableIds(): void
    {
        $this->expectExceptionMessage("2 node(s) are not reachable from any root (e.g. 'a', 'b')");

        self::read([self::n('r'), self::n('a', 'b'), self::n('b', 'a')]);
    }

    public function testDuplicateIdsThrow(): void
    {
        $this->expectException(InvalidTreeException::class);
        $this->expectExceptionMessage('Invalid tree at node 5: duplicate id.');

        self::read([self::n(5), self::n('5')]);
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function invalidIds(): array
    {
        return ['null' => [null], 'array' => [[1]], 'fraction' => [1.5], 'bool' => [true]];
    }

    #[DataProvider('invalidIds')]
    public function testInvalidIdsThrow(mixed $id): void
    {
        $this->expectException(InvalidTreeException::class);
        $this->expectExceptionMessage('Node ids must be int, string or Stringable');

        self::read([self::n($id)]);
    }

    public function testIntegralFloatAndStringableIds(): void
    {
        $stringable = new class implements \Stringable {
            public function __toString(): string
            {
                return 'a';
            }
        };

        self::assertSame(2, Tree::fromAdjacencyList([self::n($stringable), self::n(2.0, 'a')])->count());
    }

    public function testObjectsWithPropertiesAndGetters(): void
    {
        $fields = new AdjacencyListFields('id', 'parentId');

        self::assertSame('1[2]', Trees::compactOf(new AdjacencyListSource([new PublicNode(2, 1), new PublicNode(1)], $fields)));
        self::assertSame(2, Tree::fromAdjacencyList([new GetterNode(2, 1), new GetterNode(1)], $fields)->count());
    }

    public function testSkipDoesNotDescend(): void
    {
        $cursor = new AdjacencyListSource([self::n('a'), self::n('b', 'a'), self::n('c')])->open();
        $seen = [];
        while ($cursor->next()) {
            $seen[] = [$cursor->depth(), $cursor->node()];
            $cursor->skipSubtree();
        }

        self::assertSame('a, c', Trees::compact($seen));
        self::assertFalse($cursor->next());
        self::assertNull($cursor->node());
    }

    public function testGedmoFieldPreset(): void
    {
        self::assertSame(['id', 'parent'], [AdjacencyListFields::gedmo()->id, AdjacencyListFields::gedmo()->parent]);
        self::assertSame(['id', 'parent_id'], [new AdjacencyListFields()->id, new AdjacencyListFields()->parent]);
    }

    public function testReopeningAGeneratorThrowsLogicException(): void
    {
        $tree = Tree::fromAdjacencyList(Matrix::generate([self::n('a')]));
        $tree->count();

        $this->expectException(LogicException::class);
        $tree->count();
    }
}
