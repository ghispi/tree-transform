<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Tests\Unit;

use Ghispi\TreeTransform\Exception\InvalidTreeException;
use Ghispi\TreeTransform\Internal\Rows;
use Ghispi\TreeTransform\Source\MaterializedPathFields;
use Ghispi\TreeTransform\Source\NestedSetFields;
use Ghispi\TreeTransform\Strategy\MinMatches;
use Ghispi\TreeTransform\Tree;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Regressions for findings of the code review.
 */
#[CoversClass(Rows::class)]
final class ReviewRegressionTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function nonIntegers(): iterable
    {
        yield 'decimal' => ['1.9'];
        yield 'exponent' => ['1e1'];
        yield 'leading space' => [' 3'];
    }

    #[DataProvider('nonIntegers')]
    public function testNestedSetValuesMustBeIntegerStrings(string $value): void
    {
        $this->expectException(InvalidTreeException::class);

        Tree::fromNestedSet([['lft' => $value, 'rgt' => '20']])->count();
    }

    public function testExceptionsFromAGeneratorAreNotHidden(): void
    {
        $rows = (static function (): \Generator {
            throw new \RuntimeException('connection refused');
            yield []; // @phpstan-ignore deadCode.unreachable
        })();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('connection refused');

        Tree::fromNestedSet($rows)->count();
    }

    public function testNestedSetRootsCompareNormalisedIds(): void
    {
        $rows = [['lft' => 1, 'rgt' => 4, 'root' => 1], ['lft' => 2, 'rgt' => 3, 'root' => '1']];

        self::assertSame([0, 1], array_column(Tree::fromNestedSet($rows, NestedSetFields::gedmo())->toFlatList(), 'depth'));
    }

    public function testMultiCharacterSeparatorIsStrippedExactly(): void
    {
        $rows = [['path' => '-1->2->'], ['path' => '-1->2->3->']];

        self::assertSame([0, 1], array_column(Tree::fromMaterializedPath($rows, new MaterializedPathFields('path', '->'))->toFlatList(), 'depth'));
    }

    public function testBufferingStrategiesForwardSkipsToTheirInput(): void
    {
        $calls = 0;
        $root = ['id' => 0, 'children' => array_map(static fn(int $i): array => ['id' => $i], range(1, 1000))];
        $count = static function () use (&$calls): bool {
            ++$calls;

            return true;
        };
        $tree = Tree::fromNestedArray([$root]);

        foreach ([
            'annotate' => $tree->annotate($count),
            'minMatches' => $tree->filter($count, new MinMatches(1)),
            'activeTrail single' => $tree->activeTrail($count, activeChildrenDepth: \PHP_INT_MAX, multiple: false),
        ] as $name => $filtered) {
            $calls = 0;
            self::assertSame(1, $filtered->maxDepth(0)->count(), $name);
            self::assertSame(1, $calls, "{$name} should not test skipped nodes");
        }
    }
}
