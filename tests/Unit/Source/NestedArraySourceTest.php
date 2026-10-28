<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Tests\Unit\Source;

use Ghispi\TreeTransform\Exception\InvalidTreeException;
use Ghispi\TreeTransform\Exception\LogicException;
use Ghispi\TreeTransform\Source\NestedArraySource;
use Ghispi\TreeTransform\Tests\Fixtures\CountingAccessor;
use Ghispi\TreeTransform\Tests\Fixtures\Matrix;
use Ghispi\TreeTransform\Tests\Fixtures\Model\GetterNode;
use Ghispi\TreeTransform\Tests\Fixtures\Model\PublicNode;
use Ghispi\TreeTransform\Tests\Fixtures\RunningExample;
use Ghispi\TreeTransform\Tests\Fixtures\Trees;
use Ghispi\TreeTransform\Tree;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(NestedArraySource::class)]
final class NestedArraySourceTest extends TestCase
{
    public function testRunningExample(): void
    {
        self::assertSame(RunningExample::WHOLE, Trees::compactOf(new NestedArraySource(RunningExample::nestedArrays())));
    }

    public function testEmptyInput(): void
    {
        self::assertSame('', Trees::compactOf(new NestedArraySource([])));
    }

    public function testMissingNullAndEmptyChildrenAreLeaves(): void
    {
        $roots = [['id' => 'a'], ['id' => 'b', 'children' => null], ['id' => 'c', 'children' => []], ['id' => 'd', 'children' => new \ArrayIterator()]];

        self::assertSame('a, b, c, d', Trees::compactOf(new NestedArraySource($roots)));
    }

    public function testCustomChildrenKey(): void
    {
        $roots = [['id' => 'a', '__children' => [['id' => 'b', '__children' => []]]]];

        self::assertSame('a[b]', Trees::compactOf(new NestedArraySource($roots, '__children')));
    }

    public function testChildrenMayBeAnyIterable(): void
    {
        $generated = (static function (): \Generator {
            yield ['id' => 'c'];
            yield ['id' => 'd'];
        })();
        $roots = new \ArrayObject([['id' => 'a', 'children' => new \ArrayObject([['id' => 'b', 'children' => $generated]])]]);

        self::assertSame('a[b[c, d]]', Trees::compactOf(new NestedArraySource($roots)));
    }

    public function testObjectsWithPublicChildren(): void
    {
        $roots = [new PublicNode('a', children: [new PublicNode('b'), new PublicNode('c')])];

        self::assertSame('a[b, c]', Trees::compactOf(new NestedArraySource($roots)));
    }

    public function testObjectsWithChildrenGetter(): void
    {
        $roots = [new GetterNode('a', children: [new GetterNode('b')])];

        self::assertSame(2, Tree::fromNestedArray($roots)->count());
    }

    public function testNonIterableChildrenThrow(): void
    {
        $this->expectException(InvalidTreeException::class);
        $this->expectExceptionMessage('field "children" must be iterable or null, got string');

        Trees::pairs(new NestedArraySource([['id' => 'a', 'children' => 'oops']]));
    }

    public function testSkipDoesNotReadChildren(): void
    {
        $accessor = new CountingAccessor();
        $cursor = new NestedArraySource(RunningExample::nestedArrays(), accessor: $accessor)->open();
        while ($cursor->next()) {
            $cursor->skipSubtree();
        }

        self::assertSame([], $accessor->readIds['children'] ?? []);
    }

    public function testSkipIsNotNeededToReadChildren(): void
    {
        $accessor = new CountingAccessor();
        Trees::pairs(new NestedArraySource(RunningExample::nestedArrays(), accessor: $accessor));

        self::assertSame(9, $accessor->reads['children']);
    }

    public function testDoesNotReadChildrenAfterExhaustion(): void
    {
        $cursor = new NestedArraySource([['id' => 'a']])->open();
        self::assertTrue($cursor->next());
        self::assertFalse($cursor->next());
        self::assertFalse($cursor->next());
        self::assertNull($cursor->node());
    }

    public function testReopeningAGeneratorThrowsLogicException(): void
    {
        $tree = Tree::fromNestedArray(Matrix::generate([['id' => 'a']]));
        $tree->count();

        $this->expectException(LogicException::class);
        $tree->count();
    }

    public function testIteratorChildrenAreFollowedByTheirParentsSiblings(): void
    {
        $tree = [
            ['id' => 'a', 'children' => new \ArrayIterator([['id' => 'b', 'children' => new \ArrayIterator([['id' => 'c']])], ['id' => 'd']])],
            ['id' => 'e'],
        ];

        self::assertSame('a[b[c], d], e', Trees::compactOf(new NestedArraySource($tree)));
    }

    public function testChildrenWithKeysAreWalkedInOrder(): void
    {
        $tree = [['id' => 'a', 'children' => ['x' => ['id' => 'b'], 'y' => ['id' => 'c']]], ['id' => 'd']];

        self::assertSame('a[b, c], d', Trees::compactOf(new NestedArraySource($tree)));
    }
}
