<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Tests\Unit\Strategy;

use Ghispi\TreeTransform\Exception\InvalidArgumentException;
use Ghispi\TreeTransform\Predicate\Callback;
use Ghispi\TreeTransform\Source\Source;
use Ghispi\TreeTransform\Strategy\KeepAncestors;
use Ghispi\TreeTransform\Strategy\MinMatches;
use Ghispi\TreeTransform\Tests\Fixtures\Field;
use Ghispi\TreeTransform\Tests\Fixtures\Outline;
use Ghispi\TreeTransform\Tests\Fixtures\RunningExample;
use Ghispi\TreeTransform\Tests\Fixtures\SpyCursor;
use Ghispi\TreeTransform\Tests\Fixtures\Trees;
use Ghispi\TreeTransform\Tree;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(MinMatches::class)]
final class MinMatchesTest extends TestCase
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
        $tree = Tree::from($source);
        $m = RunningExample::matches();

        self::assertSame('A[B[C, D[E]]], H[I]', Trees::compactOf($tree->filter($m, new MinMatches(1))));
        self::assertSame('A[B]', Trees::compactOf($tree->filter($m, new MinMatches(2))));
        self::assertSame('A', Trees::compactOf($tree->filter($m, new MinMatches(3))));
        self::assertSame('', Trees::compactOf($tree->filter($m, new MinMatches(4))));
    }

    public function testMinimumOneEqualsKeepAncestors(): void
    {
        $tree = Tree::from(RunningExample::source());
        $p = RunningExample::isId('C', 'G');

        self::assertSame(Trees::compactOf($tree->filter($p, new KeepAncestors())), Trees::compactOf($tree->filter($p, new MinMatches(1))));
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function invalidMinimums(): array
    {
        return ['zero' => [0], 'negative' => [-3]];
    }

    #[DataProvider('invalidMinimums')]
    public function testMinimumBelowOneIsRejected(int $min): void
    {
        $this->expectException(InvalidArgumentException::class);

        new MinMatches($min);
    }

    public function testConsumerSkip(): void
    {
        $cursor = new MinMatches(1)->apply(RunningExample::source()->open(), new Callback(RunningExample::matches()));
        $seen = [];
        while ($cursor->next()) {
            $seen[] = [$cursor->depth(), $cursor->node()];
            if (Field::id($cursor->node()) === 'B') {
                $cursor->skipSubtree();
            }
        }

        self::assertSame('A[B], H[I]', Trees::compact($seen));
    }

    public function testSkippingTheCurrentNodeIsForwardedToTheInput(): void
    {
        $spy = SpyCursor::on(Outline::source('A[B[b1, b2]], C'));
        $cursor = new MinMatches(1)->apply($spy, new Callback(RunningExample::isId('B')));
        while ($cursor->next()) {
            if (Field::id($cursor->node()) === 'B') {
                $cursor->skipSubtree();
            }
        }

        self::assertSame(['B'], $spy->skipped);
        self::assertSame(['A', 'B', 'C'], $spy->visited);
    }
}
