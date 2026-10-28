<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Tests\Unit\Strategy;

use Ghispi\TreeTransform\Predicate\Ancestors;
use Ghispi\TreeTransform\Predicate\Callback;
use Ghispi\TreeTransform\Source\DepthListSource;
use Ghispi\TreeTransform\Source\Source;
use Ghispi\TreeTransform\Strategy\OnMatch;
use Ghispi\TreeTransform\Strategy\Prune;
use Ghispi\TreeTransform\Tests\Fixtures\Field;
use Ghispi\TreeTransform\Tests\Fixtures\RunningExample;
use Ghispi\TreeTransform\Tests\Fixtures\SpyCursor;
use Ghispi\TreeTransform\Tests\Fixtures\Trees;
use Ghispi\TreeTransform\Tree;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Prune::class)]
#[CoversClass(\Ghispi\TreeTransform\Strategy\PruneCursor::class)]
final class PruneTest extends TestCase
{
    /**
     * @return array<string, array{0: Source}>
     */
    public static function sources(): array
    {
        return RunningExample::sources();
    }

    #[DataProvider('sources')]
    public function testRunningExample(Source $source): void
    {
        self::assertSame('A', Trees::compactOf(Tree::from($source)->filter(RunningExample::matches(), new Prune())));
        self::assertSame('A[B[C, D[E]], F[G]]', Trees::compactOf(Tree::from($source)->filter(RunningExample::matches(), new Prune(OnMatch::KeepDescendants))));
        self::assertSame('A', Trees::compactOf(Tree::from($source)->filter(RunningExample::matches(), new Prune(OnMatch::DropDescendants))));
    }

    public function testDefaultStrategyOfFilterIsPrune(): void
    {
        $keepAllButF = static fn(mixed $n): bool => Field::id($n) !== 'F' && Field::id($n) !== 'B';

        self::assertSame('A, H[I]', Trees::compactOf(Tree::from(RunningExample::source())->filter($keepAllButF)));
    }

    public function testDescendantsOfRemovedNodesAreNeverEvaluated(): void
    {
        $tested = [];
        $predicate = static function (mixed $n) use (&$tested): bool {
            $tested[] = Field::id($n);

            return RunningExample::matches()($n);
        };

        Tree::from(RunningExample::source())->filter($predicate)->count();

        self::assertSame(['A', 'B', 'F', 'H'], $tested);
    }

    public function testKeepDescendantsDoesNotEvaluateTheKeptSubtree(): void
    {
        $tested = [];
        $predicate = static function (mixed $n) use (&$tested): bool {
            $tested[] = Field::id($n);

            return RunningExample::matches()($n);
        };

        Tree::from(RunningExample::source())->filter($predicate, new Prune(OnMatch::KeepDescendants))->count();

        self::assertSame(['A', 'H'], $tested);
    }

    public function testRemovedSubtreesAreSkippedInTheSource(): void
    {
        $spy = SpyCursor::on(RunningExample::source());
        Trees::pairs(new Prune()->apply($spy, new Callback(RunningExample::matches())));

        self::assertSame(['B', 'F', 'H'], $spy->skipped);
        self::assertSame(['A', 'B', 'F', 'H'], $spy->visited);
    }

    public function testConsumerSkipIsForwardedToTheInput(): void
    {
        $spy = SpyCursor::on(RunningExample::source());
        $cursor = new Prune(OnMatch::KeepDescendants)->apply($spy, new Callback(RunningExample::matches()));
        $seen = [];
        while ($cursor->next()) {
            $seen[] = Field::id($cursor->node());
            if (Field::id($cursor->node()) === 'B') {
                $cursor->skipSubtree();
            }
        }

        self::assertSame(['A', 'B', 'F', 'G'], $seen);
        self::assertContains('B', $spy->skipped);
    }

    public function testPredicateReceivesDepthAndInputAncestors(): void
    {
        $calls = [];
        $predicate = static function (mixed $n, int $depth, Ancestors $ancestors) use (&$calls): bool {
            $calls[] = Field::id($n) . '@' . $depth . ':' . implode('', array_map(Field::id(...), $ancestors->toArray()));

            return true;
        };

        Tree::from(RunningExample::source())->filter($predicate)->count();

        self::assertSame(['A@0:', 'B@1:A', 'C@2:AB', 'D@2:AB', 'E@3:ABD', 'F@1:A', 'G@2:AF', 'H@0:', 'I@1:H'], $calls);
    }

    /**
     * @return array<string, array{0: list<array{0: int, 1: array{id: string, m: bool}}>, 1: string}>
     */
    public static function edgeCases(): array
    {
        $n = static fn(string $id, bool $m = true): array => ['id' => $id, 'm' => $m];

        return [
            'empty' => [[], ''],
            'single match' => [[[0, $n('a')]], 'a'],
            'single non-match' => [[[0, $n('a', false)]], ''],
            'forest' => [[[0, $n('a')], [0, $n('b', false)], [0, $n('c')]], 'a, c'],
            'deep chain cut in the middle' => [[[0, $n('a')], [1, $n('b')], [2, $n('c', false)], [3, $n('d')], [4, $n('e')]], 'a[b]'],
            'wide node' => [[[0, $n('r')], [1, $n('a')], [1, $n('b', false)], [1, $n('c')], [1, $n('d', false)]], 'r[a, c]'],
            'multi-level depth drop' => [[[0, $n('a')], [1, $n('b')], [2, $n('c')], [3, $n('d')], [0, $n('e')]], 'a[b[c[d]]], e'],
        ];
    }

    /**
     * @param list<array{0: int, 1: array{id: string, m: bool}}> $pairs
     */
    #[DataProvider('edgeCases')]
    public function testEdgeCases(array $pairs, string $expected): void
    {
        $tree = Tree::from(DepthListSource::pairs($pairs))->filter(static fn(mixed $n): bool => Field::bool($n, 'm'));

        self::assertSame($expected, Trees::compactOf($tree));
    }
}
