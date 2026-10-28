<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Tests\Unit\Strategy;

use Ghispi\TreeTransform\Predicate\Callback;
use Ghispi\TreeTransform\Source\DepthListSource;
use Ghispi\TreeTransform\Source\Source;
use Ghispi\TreeTransform\Strategy\OnMatch;
use Ghispi\TreeTransform\Strategy\Placeholder;
use Ghispi\TreeTransform\Tests\Fixtures\Field;
use Ghispi\TreeTransform\Tests\Fixtures\RunningExample;
use Ghispi\TreeTransform\Tests\Fixtures\SpyCursor;
use Ghispi\TreeTransform\Tests\Fixtures\Trees;
use Ghispi\TreeTransform\Tree;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Placeholder::class)]
final class PlaceholderTest extends TestCase
{
    /**
     * @return array<string, array{0: Source}>
     */
    public static function sources(): array
    {
        return RunningExample::sources();
    }

    /**
     * @return \Closure(int, int): array{id: string}
     */
    private static function hidden(): \Closure
    {
        return static fn(int $count): array => ['id' => "[{$count} hidden]"];
    }

    #[DataProvider('sources')]
    public function testRunningExample(Source $source): void
    {
        $tree = Tree::from($source);
        $m = RunningExample::matches();

        self::assertSame('A[[2 hidden]], [1 hidden]', Trees::compactOf($tree->filter($m, new Placeholder(self::hidden()))));
        self::assertSame('A[B[C, D[E]], F[G]], [1 hidden]', Trees::compactOf($tree->filter($m, new Placeholder(self::hidden(), OnMatch::KeepDescendants))));
        self::assertSame('A, [1 hidden]', Trees::compactOf($tree->filter($m, new Placeholder(self::hidden(), OnMatch::DropDescendants))));
    }

    public function testFactoryReceivesCountAndDepth(): void
    {
        $calls = [];
        $factory = static function (int $hidden, int $depth) use (&$calls): array {
            $calls[] = [$hidden, $depth];

            return ['id' => 'x'];
        };
        Tree::from(RunningExample::source())->filter(RunningExample::matches(), new Placeholder($factory))->count();

        self::assertSame([[2, 1], [1, 0]], $calls);
    }

    /**
     * @return array<string, array{0: list<array{0: int, 1: array{id: string, m: bool}}>, 1: string}>
     */
    public static function runs(): array
    {
        $n = static fn(string $id, bool $m = true): array => ['id' => $id, 'm' => $m];

        return [
            'empty' => [[], ''],
            'runs split by a kept sibling' => [[[0, $n('x', false)], [0, $n('y')], [0, $n('z', false)], [0, $n('w', false)]], '[1 hidden], y, [2 hidden]'],
            'everything removed' => [[[0, $n('x', false)], [1, $n('y')], [0, $n('z', false)]], '[2 hidden]'],
            'flushed deepest first when parents close' => [
                [[0, $n('a')], [1, $n('b')], [2, $n('c', false)], [2, $n('d', false)], [0, $n('e', false)]],
                'a[b[[2 hidden]]], [1 hidden]',
            ],
            'runs at several levels close together' => [
                [[0, $n('a')], [1, $n('b')], [2, $n('c', false)], [1, $n('f', false)]],
                'a[b[[1 hidden]], [1 hidden]]',
            ],
        ];
    }

    /**
     * @param list<array{0: int, 1: array{id: string, m: bool}}> $pairs
     */
    #[DataProvider('runs')]
    public function testRuns(array $pairs, string $expected): void
    {
        $tree = Tree::from(DepthListSource::pairs($pairs))->filter(static fn(mixed $n): bool => Field::bool($n, 'm'), new Placeholder(self::hidden()));

        self::assertSame($expected, Trees::compactOf($tree));
    }

    public function testSkipOnPlaceholderIsIgnoredAndOnRealNodeForwarded(): void
    {
        $spy = SpyCursor::on(RunningExample::source());
        $cursor = new Placeholder(self::hidden(), OnMatch::KeepDescendants)->apply($spy, new Callback(RunningExample::isId('A')));
        $seen = [];
        while ($cursor->next()) {
            $seen[] = [$cursor->depth(), $cursor->node()];
            $cursor->skipSubtree();
        }

        self::assertSame('A, [1 hidden]', Trees::compact($seen));
        self::assertSame(['A', 'H'], $spy->skipped);
    }
}
