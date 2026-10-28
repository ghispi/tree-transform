<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Tests\Unit\Sink;

use Ghispi\TreeTransform\Sink\NestedArraySink;
use Ghispi\TreeTransform\Sink\TreeNode;
use Ghispi\TreeTransform\Source\DepthListSource;
use Ghispi\TreeTransform\Tests\Fixtures\Model\PublicNode;
use Ghispi\TreeTransform\Tests\Fixtures\RunningExample;
use Ghispi\TreeTransform\Tree;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(NestedArraySink::class)]
#[CoversClass(TreeNode::class)]
final class NestedArraySinkTest extends TestCase
{
    /**
     * @return list<array{0: int, 1: array{id: string}}>
     */
    private static function pairs(): array
    {
        return [[0, ['id' => 'a']], [1, ['id' => 'b']], [2, ['id' => 'c']], [1, ['id' => 'd']], [0, ['id' => 'e']]];
    }

    public function testArraysGetAChildrenKey(): void
    {
        self::assertSame([
            ['id' => 'a', 'children' => [
                ['id' => 'b', 'children' => [['id' => 'c', 'children' => []]]],
                ['id' => 'd', 'children' => []],
            ]],
            ['id' => 'e', 'children' => []],
        ], Tree::from(DepthListSource::pairs(self::pairs()))->toNestedArray());
    }

    public function testCustomChildrenKeyAndOmitEmptyChildren(): void
    {
        self::assertSame([
            ['id' => 'a', '__children' => [
                ['id' => 'b', '__children' => [['id' => 'c']]],
                ['id' => 'd'],
            ]],
            ['id' => 'e'],
        ], Tree::from(DepthListSource::pairs(self::pairs()))->toNestedArray('__children', omitEmptyChildren: true));
    }

    public function testEmpty(): void
    {
        self::assertSame([], Tree::fromNestedSet([])->toNestedArray());
    }

    public function testInputIsNotModified(): void
    {
        $rows = RunningExample::rows();
        $copy = $rows;
        Tree::fromNestedSet($rows)->toNestedArray();

        self::assertSame($copy, $rows);
    }

    public function testObjectsAreWrappedInTreeNodeAndNotModified(): void
    {
        $a = new PublicNode('a', lft: 1, rgt: 4);
        $b = new PublicNode('b', lft: 2, rgt: 3);

        $result = Tree::fromNestedSet([$a, $b])->toNestedArray();

        self::assertCount(1, $result);
        $root = $result[0];
        self::assertInstanceOf(TreeNode::class, $root);
        self::assertSame($a, $root->node);
        self::assertFalse($root->isLeaf());
        self::assertCount(1, $root);
        $child = $root->children[0];
        self::assertInstanceOf(TreeNode::class, $child);
        self::assertSame($b, $child->node);
        self::assertTrue($child->isLeaf());
        self::assertSame([$child], iterator_to_array($root));
        self::assertSame([], $a->children, 'entity untouched');
    }

    public function testNodeFactoryDecidesTheShape(): void
    {
        $calls = [];
        $factory = static function (mixed $node, array $children, int $depth) use (&$calls): string {
            $id = \is_array($node) && \is_string($node['id']) ? $node['id'] : '?';
            $calls[] = $id . $depth;

            return $id . '(' . implode(',', array_map(static fn(mixed $c): string => \is_string($c) ? $c : '?', $children)) . ')';
        };

        self::assertSame(['a(b(c()),d())', 'e()'], Tree::from(DepthListSource::pairs(self::pairs()))->toNestedArray(nodeFactory: $factory));
        self::assertSame(['c2', 'b1', 'd1', 'a0', 'e0'], $calls, 'built bottom-up');
    }

    public function testDeepChainWithoutRecursion(): void
    {
        $pairs = (static function (): \Generator {
            for ($i = 0; $i < 10_000; ++$i) {
                yield $i => ['id' => $i];
            }
        })();
        $result = Tree::fromDepthList($pairs)->toNestedArray();

        $depth = 0;
        $node = $result[0];
        while (\is_array($node) && \is_array($node['children']) && $node['children'] !== []) {
            $node = $node['children'][0];
            ++$depth;
        }
        self::assertSame(9_999, $depth);
        self::assertSame(['id' => 9_999, 'children' => []], $node);
    }
}
