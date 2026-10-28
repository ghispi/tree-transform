<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Tests\Unit\Strategy;

use Ghispi\TreeTransform\Exception\InvalidArgumentException;
use Ghispi\TreeTransform\Predicate\Callback;
use Ghispi\TreeTransform\Source\Source;
use Ghispi\TreeTransform\Strategy\ActiveTrail;
use Ghispi\TreeTransform\Strategy\ActiveTrailCursor;
use Ghispi\TreeTransform\Tests\Fixtures\Field;
use Ghispi\TreeTransform\Tests\Fixtures\RunningExample;
use Ghispi\TreeTransform\Tests\Fixtures\Trees;
use Ghispi\TreeTransform\Tree;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ActiveTrail::class)]
#[CoversClass(ActiveTrailCursor::class)]
final class ActiveTrailTest extends TestCase
{
    /**
     * @return array<string, array{0: Source}>
     */
    public static function sources(): array
    {
        return RunningExample::sources();
    }

    /** SPEC §5.1.1 table, active = E. */
    #[DataProvider('sources')]
    public function testSpecExamples(Source $source): void
    {
        $tree = Tree::from($source);

        self::assertSame('A[B[C, D[E]], F], H', Trees::compactOf($tree->activeTrail(RunningExample::isId('E'))));
        self::assertSame('A[B[D[E]]]', Trees::compactOf($tree->activeTrail(RunningExample::isId('E'), siblings: false)));
    }

    /**
     * @return array<string, array{0: list<string>, 1: bool, 2: int, 3: bool, 4: string}>
     */
    public static function options(): array
    {
        return [
            'children depth 0' => [['B'], true, 0, true, 'A[B, F], H'],
            'children depth 1' => [['B'], true, 1, true, 'A[B[C, D], F], H'],
            'children depth 2' => [['B'], true, 2, true, 'A[B[C, D[E]], F], H'],
            'whole subtree' => [['A'], true, \PHP_INT_MAX, true, 'A[B[C, D[E]], F[G]], H'],
            'active root' => [['A'], true, 1, true, 'A[B, F], H'],
            'union of trails' => [['B', 'I'], true, 1, true, 'A[B[C, D], F], H[I]'],
            'nested active nodes' => [['B', 'E'], true, 1, true, 'A[B[C, D[E]], F], H'],
            'first active only' => [['B', 'I'], true, 1, false, 'A[B[C, D], F], H'],
            'no active node: roots' => [[], true, 1, true, 'A, H'],
            'breadcrumb, no active node' => [[], false, 1, true, ''],
            'breadcrumb with children' => [['B'], false, 1, true, 'A[B[C, D]]'],
            'breadcrumb, children depth 0' => [['B'], false, 0, true, 'A[B]'],
            'breadcrumb union' => [['C', 'I'], false, 0, true, 'A[B[C]], H[I]'],
        ];
    }

    /**
     * @param list<string> $active
     */
    #[DataProvider('options')]
    public function testOptions(array $active, bool $siblings, int $childrenDepth, bool $multiple, string $expected): void
    {
        $tree = Tree::from(RunningExample::source())->activeTrail(RunningExample::isId(...$active), $siblings, $childrenDepth, $multiple);

        self::assertSame($expected, Trees::compactOf($tree));
    }

    public function testSingleActiveStopsEvaluatingAfterFirstMatch(): void
    {
        $tested = [];
        $predicate = static function (mixed $n) use (&$tested): bool {
            $tested[] = Field::id($n);

            return \in_array(Field::id($n), ['B', 'I'], true);
        };
        Tree::from(RunningExample::source())->activeTrail($predicate, multiple: false)->count();

        self::assertSame(['A', 'B'], $tested);
    }

    public function testNegativeChildrenDepthIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ActiveTrail(activeChildrenDepth: -1);
    }

    public function testConsumerSkipDropsBufferedAndStreamedDescendants(): void
    {
        $cursor = new ActiveTrail()->apply(RunningExample::source()->open(), new Callback(RunningExample::isId('E')));
        $seen = [];
        while ($cursor->next()) {
            $seen[] = [$cursor->depth(), $cursor->node()];
            if (Field::id($cursor->node()) === 'B') {
                $cursor->skipSubtree();
            }
        }

        self::assertSame('A[B, F], H', Trees::compact($seen));
    }
}
