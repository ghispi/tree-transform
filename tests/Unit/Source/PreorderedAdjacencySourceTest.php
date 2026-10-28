<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Tests\Unit\Source;

use Ghispi\TreeTransform\Exception\InvalidTreeException;
use Ghispi\TreeTransform\Exception\LogicException;
use Ghispi\TreeTransform\Source\PreorderedAdjacencySource;
use Ghispi\TreeTransform\Tests\Fixtures\Matrix;
use Ghispi\TreeTransform\Tests\Fixtures\RunningExample;
use Ghispi\TreeTransform\Tests\Fixtures\Trees;
use Ghispi\TreeTransform\Tree;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PreorderedAdjacencySource::class)]
final class PreorderedAdjacencySourceTest extends TestCase
{
    /**
     * @return array{id: mixed, parent_id: mixed}
     */
    private static function n(mixed $id, mixed $parent = null): array
    {
        return ['id' => $id, 'parent_id' => $parent];
    }

    public function testRunningExample(): void
    {
        self::assertSame(RunningExample::WHOLE, Trees::compactOf(new PreorderedAdjacencySource(RunningExample::rows())));
    }

    public function testMultiLevelDepthDrop(): void
    {
        $rows = [self::n('a'), self::n('b', 'a'), self::n('c', 'b'), self::n('d', 'c'), self::n('e', 'a'), self::n('f')];

        self::assertSame('a[b[c[d]], e], f', Trees::compactOf(new PreorderedAdjacencySource($rows)));
    }

    public function testRootParent(): void
    {
        self::assertSame('a[b], c', Trees::compactOf(new PreorderedAdjacencySource([self::n('a', 0), self::n('b', 'a'), self::n('c', 0)], rootParent: 0)));
    }

    public function testParentThatIsNotAnOpenAncestorThrows(): void
    {
        $this->expectException(InvalidTreeException::class);
        $this->expectExceptionMessage("Invalid tree at node 'c': parent 'b' is not an open ancestor");

        Trees::pairs(new PreorderedAdjacencySource([self::n('a'), self::n('b', 'a'), self::n('x'), self::n('c', 'b')]));
    }

    public function testChildBeforeParentThrows(): void
    {
        $this->expectException(InvalidTreeException::class);

        Trees::pairs(new PreorderedAdjacencySource([self::n('b', 'a'), self::n('a')]));
    }

    public function testIntIdsAndNumericStringParentsAreTheSame(): void
    {
        self::assertSame('1[2]', Trees::compactOf(new PreorderedAdjacencySource([self::n(1), self::n(2, '1')])));
        self::assertSame('1', Trees::compactOf(new PreorderedAdjacencySource([self::n('1', '0')], rootParent: 0)));
    }

    public function testSkipStillTracksSkippedIds(): void
    {
        $rows = [self::n('a'), self::n('b', 'a'), self::n('c', 'b'), self::n('d', 'a'), self::n('e')];
        $cursor = new PreorderedAdjacencySource($rows)->open();
        $seen = [];
        while ($cursor->next()) {
            $seen[] = [$cursor->depth(), $cursor->node()];
            if ($cursor->node() === $rows[1]) {
                $cursor->skipSubtree();
            }
        }

        self::assertSame('a[b, d], e', Trees::compact($seen));
        self::assertFalse($cursor->next());
        self::assertNull($cursor->node());
    }

    public function testReopeningAGeneratorThrowsLogicException(): void
    {
        $tree = Tree::fromPreorderedAdjacencyList(Matrix::generate([self::n('a')]));
        $tree->count();

        $this->expectException(LogicException::class);
        $tree->count();
    }

    public function testNumericStringIdsMatchTheirChildrensParents(): void
    {
        $rows = [self::n('1'), self::n('2', '1'), self::n('3', 2), self::n('4', '1')];

        self::assertSame('1[2[3], 4]', Trees::compactOf(new PreorderedAdjacencySource($rows)));
    }
}
