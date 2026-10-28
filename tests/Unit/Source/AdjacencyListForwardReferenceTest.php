<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Tests\Unit\Source;

use Ghispi\TreeTransform\Accessor\ArrayKeyAccessor;
use Ghispi\TreeTransform\Exception\CycleDetectedException;
use Ghispi\TreeTransform\Exception\OrphanNodeException;
use Ghispi\TreeTransform\Source\AdjacencyListFields;
use Ghispi\TreeTransform\Source\AdjacencyListSource;
use Ghispi\TreeTransform\Source\OrphanPolicy;
use Ghispi\TreeTransform\Tests\Fixtures\Model\GetterNode;
use Ghispi\TreeTransform\Tests\Fixtures\Model\PublicNode;
use Ghispi\TreeTransform\Tests\Fixtures\Trees;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Rows whose parents come later in the input: sibling order, cycle detection and orphan policies.
 */
#[CoversClass(AdjacencyListSource::class)]
final class AdjacencyListForwardReferenceTest extends TestCase
{
    /**
     * @return array{id: string, parent_id: string|null}
     */
    private static function n(string $id, ?string $parent = null): array
    {
        return ['id' => $id, 'parent_id' => $parent];
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private static function read(iterable $rows, OrphanPolicy $orphans = OrphanPolicy::Throw): string
    {
        return Trees::compactOf(new AdjacencyListSource($rows, orphans: $orphans));
    }

    public function testSiblingsKeepInputOrderWhenChildrenComeBeforeAndAfterTheirParent(): void
    {
        $rows = [self::n('c', 'a'), self::n('z'), self::n('a'), self::n('d', 'a'), self::n('b', 'a'), self::n('y', 'c'), self::n('x', 'c')];

        self::assertSame('z, a[c[y, x], d, b]', self::read($rows));
    }

    /**
     * @param list<array{id: string, parent_id: string|null}> $rows
     */
    private static function reference(array $rows): string
    {
        $children = [];
        foreach ($rows as $row) {
            $children[$row['parent_id'] ?? ''][] = $row['id'];
        }
        $render = static function (string $id) use (&$render, $children): string {
            $kids = $children[$id] ?? [];

            return $kids === [] ? $id : $id . '[' . implode(', ', array_map($render, $kids)) . ']';
        };

        return implode(', ', array_map($render, $children[''] ?? []));
    }

    public function testEveryPermutationOfTheRowsKeepsSiblingsInInputOrder(): void
    {
        $rows = [self::n('a'), self::n('b', 'a'), self::n('c', 'a'), self::n('d', 'b'), self::n('e', 'b'), self::n('f')];

        $permutations = self::permute($rows);

        self::assertCount(720, $permutations);
        foreach ($permutations as $order) {
            self::assertSame(self::reference($order), self::read($order), implode(' ', array_map(static fn(array $r): string => $r['id'], $order)));
        }
    }

    public function testCycleAmongForwardReferencesIsDetected(): void
    {
        $this->expectException(CycleDetectedException::class);
        $this->expectExceptionMessage("2 node(s) are not reachable from any root (e.g. 'c', 'd')");

        self::read([self::n('b', 'a'), self::n('a'), self::n('c', 'd'), self::n('d', 'c')]);
    }

    public function testCycleMembersAfterValidNodesAreDetected(): void
    {
        $this->expectException(CycleDetectedException::class);
        $this->expectExceptionMessage("3 node(s) are not reachable from any root (e.g. 'x', 'y', 'z')");

        self::read([self::n('a'), self::n('b', 'a'), self::n('c', 'b'), self::n('x', 'z'), self::n('y', 'x'), self::n('z', 'y')]);
    }

    public function testCycleOfTwoWithValidSubtreeHangingOffIt(): void
    {
        $this->expectException(CycleDetectedException::class);

        self::read([self::n('a'), self::n('t', 'c'), self::n('c', 'd'), self::n('d', 'c')]);
    }

    public function testSelfParentIsACycle(): void
    {
        $this->expectException(CycleDetectedException::class);
        $this->expectExceptionMessage("(e.g. 'x')");

        self::read([self::n('a'), self::n('x', 'x')]);
    }

    public function testCycleIsDetectedWithEveryOrphanPolicy(): void
    {
        foreach ([OrphanPolicy::Throw, OrphanPolicy::AsRoot, OrphanPolicy::Drop] as $policy) {
            try {
                self::read([self::n('b', 'a'), self::n('a'), self::n('c', 'd'), self::n('d', 'c')], $policy);
                self::fail("Expected a cycle with {$policy->name}");
            } catch (CycleDetectedException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testCycleBesideADroppedOrphanIsStillDetected(): void
    {
        $this->expectException(CycleDetectedException::class);
        $this->expectExceptionMessage("'c', 'd'");

        self::read([self::n('b', 'a'), self::n('a'), self::n('o', 'missing'), self::n('p', 'o'), self::n('c', 'd'), self::n('d', 'c')], OrphanPolicy::Drop);
    }

    /**
     * Regression: the message used to count and name dropped orphans (o, p) as if they were cycle members.
     */
    public function testCycleMessageNamesOnlyCycleMembersWhenOrphansAreDropped(): void
    {
        $this->expectException(CycleDetectedException::class);
        $this->expectExceptionMessage('2 node(s) are not reachable');

        self::read([self::n('b', 'a'), self::n('a'), self::n('o', 'missing'), self::n('p', 'o'), self::n('c', 'd'), self::n('d', 'c')], OrphanPolicy::Drop);
    }

    public function testOrphansThrowWithForwardReferences(): void
    {
        $this->expectException(OrphanNodeException::class);
        $this->expectExceptionMessage("Parent 'missing' of 1 node(s) does not exist");

        self::read([self::n('b', 'a'), self::n('a'), self::n('o', 'missing')]);
    }

    public function testOrphansAsRootKeepInputOrderWithForwardReferences(): void
    {
        $rows = [self::n('b', 'a'), self::n('o', 'missing'), self::n('a'), self::n('p', 'o'), self::n('q')];

        self::assertSame('o[p], a[b], q', self::read($rows, OrphanPolicy::AsRoot));
    }

    public function testDroppedOrphansAndTheirSubtreesVanishWithForwardReferences(): void
    {
        $rows = [self::n('b', 'a'), self::n('p', 'o'), self::n('o', 'missing'), self::n('a'), self::n('q', 'p')];

        self::assertSame('a[b]', self::read($rows, OrphanPolicy::Drop));
    }

    public function testDropWithForwardReferencesAndNoOrphans(): void
    {
        self::assertSame('a[b[c]]', self::read([self::n('c', 'b'), self::n('b', 'a'), self::n('a')], OrphanPolicy::Drop));
    }

    public function testRootParentWithForwardReferences(): void
    {
        $rows = [['id' => 2, 'parent_id' => 1], ['id' => 1, 'parent_id' => 0], ['id' => 3, 'parent_id' => '0']];

        self::assertSame('1[2], 3', Trees::compactOf(new AdjacencyListSource($rows, rootParent: 0)));
    }

    public function testIntAndNumericStringIdsMatchAcrossForwardReferences(): void
    {
        $rows = [['id' => '2', 'parent_id' => 1], ['id' => 1, 'parent_id' => null], ['id' => 3, 'parent_id' => '2']];

        self::assertSame('1[2[3]]', Trees::compactOf(new AdjacencyListSource($rows)));
    }

    public function testObjectRowsAreReadThroughAccessorsWithForwardReferences(): void
    {
        $fields = new AdjacencyListFields('id', 'parentId');

        self::assertSame('1[2, 3]', Trees::compactOf(new AdjacencyListSource([new PublicNode(2, 1), new PublicNode(1), new PublicNode(3, 1)], $fields)));
        self::assertSame('1[2[4], 3]', Trees::compactOf(new AdjacencyListSource([new GetterNode(4, 2), new GetterNode(2, 1), new GetterNode(3, 1), new GetterNode(1)], $fields)));
    }

    public function testObjectRowsCycleIsDetected(): void
    {
        $this->expectException(CycleDetectedException::class);

        Trees::compactOf(new AdjacencyListSource([new PublicNode(1, 2), new PublicNode(2, 1)], new AdjacencyListFields('id', 'parentId')));
    }

    public function testExplicitArrayKeyAccessorBehavesLikeTheDefault(): void
    {
        $rows = [self::n('c', 'a'), self::n('a'), self::n('b', 'a')];

        self::assertSame(
            self::read($rows),
            Trees::compactOf(new AdjacencyListSource($rows, accessor: new ArrayKeyAccessor())),
        );
    }

    /**
     * @param list<array{id: string, parent_id: string|null}> $items
     *
     * @return list<list<array{id: string, parent_id: string|null}>>
     */
    private static function permute(array $items): array
    {
        if (\count($items) <= 1) {
            return [$items];
        }
        $out = [];
        foreach ($items as $i => $item) {
            $rest = $items;
            unset($rest[$i]);
            foreach (self::permute(array_values($rest)) as $tail) {
                $out[] = [$item, ...$tail];
            }
        }

        return $out;
    }

    public function testDroppedOrphansDoNotHideRootsOrEachOtherWithForwardReferences(): void
    {
        $rows = [self::n('b', 'a'), self::n('a'), self::n('s'), self::n('o', 'm1'), self::n('p', 'o'), self::n('q', 'm2'), self::n('r', 'q')];

        self::assertSame('a[b], s', self::read($rows, OrphanPolicy::Drop));
    }
}
