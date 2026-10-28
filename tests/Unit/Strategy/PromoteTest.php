<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Tests\Unit\Strategy;

use Ghispi\TreeTransform\Predicate\Callback;
use Ghispi\TreeTransform\Source\Source;
use Ghispi\TreeTransform\Strategy\OnMatch;
use Ghispi\TreeTransform\Strategy\Promote;
use Ghispi\TreeTransform\Tests\Fixtures\Field;
use Ghispi\TreeTransform\Tests\Fixtures\RunningExample;
use Ghispi\TreeTransform\Tests\Fixtures\SpyCursor;
use Ghispi\TreeTransform\Tests\Fixtures\Trees;
use Ghispi\TreeTransform\Tree;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Promote::class)]
final class PromoteTest extends TestCase
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

        self::assertSame('A[C, E], I', Trees::compactOf($tree->filter($m, new Promote())));
        self::assertSame('A[B[C, D[E]], F[G]], I', Trees::compactOf($tree->filter($m, new Promote(OnMatch::KeepDescendants))));
        self::assertSame('A, I', Trees::compactOf($tree->filter($m, new Promote(OnMatch::DropDescendants))));
    }

    public function testMatchesBelowRemovedRootsBecomeRoots(): void
    {
        self::assertSame('C, E, G', Trees::compactOf(Tree::from(RunningExample::source())->filter(RunningExample::isId('C', 'E', 'G'), new Promote())));
    }

    public function testMultiLevelDepthDropPopsKeptStack(): void
    {
        // E (depth 3, output depth 1 under A) is followed by F (depth 1) and its kept child G.
        self::assertSame('A[E, G], I', Trees::compactOf(Tree::from(RunningExample::source())->filter(RunningExample::isId('A', 'E', 'G', 'I'), new Promote())));
    }

    public function testSkipIsForwardedToTheInputNode(): void
    {
        $spy = SpyCursor::on(RunningExample::source());
        $cursor = new Promote()->apply($spy, new Callback(RunningExample::matches()));
        $seen = [];
        while ($cursor->next()) {
            $seen[] = [$cursor->depth(), $cursor->node()];
            if (Field::id($cursor->node()) === 'A') {
                $cursor->skipSubtree();
            }
        }

        self::assertSame('A, I', Trees::compact($seen));
        self::assertSame(['A'], $spy->skipped);
    }

    public function testDropDescendantsSkipsTheSourceSubtree(): void
    {
        $spy = SpyCursor::on(RunningExample::source());
        Trees::pairs(new Promote(OnMatch::DropDescendants)->apply($spy, new Callback(RunningExample::matches())));

        self::assertSame(['A', 'I'], $spy->skipped);
        self::assertSame(['A', 'H', 'I'], $spy->visited);
    }
}
