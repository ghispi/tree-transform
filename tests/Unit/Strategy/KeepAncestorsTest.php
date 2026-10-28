<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Tests\Unit\Strategy;

use Ghispi\TreeTransform\Predicate\Callback;
use Ghispi\TreeTransform\Source\Source;
use Ghispi\TreeTransform\Strategy\KeepAncestors;
use Ghispi\TreeTransform\Strategy\KeepAncestorsCursor;
use Ghispi\TreeTransform\Strategy\OnMatch;
use Ghispi\TreeTransform\Tests\Fixtures\Field;
use Ghispi\TreeTransform\Tests\Fixtures\RunningExample;
use Ghispi\TreeTransform\Tests\Fixtures\SpyCursor;
use Ghispi\TreeTransform\Tests\Fixtures\Trees;
use Ghispi\TreeTransform\Tree;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(KeepAncestors::class)]
#[CoversClass(KeepAncestorsCursor::class)]
final class KeepAncestorsTest extends TestCase
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

        self::assertSame('A[B[C, D[E]]], H[I]', Trees::compactOf($tree->filter($m, new KeepAncestors())));
        self::assertSame(RunningExample::WHOLE, Trees::compactOf($tree->filter($m, new KeepAncestors(OnMatch::KeepDescendants))));
        self::assertSame('A, H[I]', Trees::compactOf($tree->filter($m, new KeepAncestors(OnMatch::DropDescendants))));
    }

    public function testNothingMatchesGivesEmptyResult(): void
    {
        self::assertSame('', Trees::compactOf(Tree::from(RunningExample::source())->filter(static fn(): bool => false, new KeepAncestors())));
    }

    public function testDeepMatchFlushesTheWholePath(): void
    {
        self::assertSame('A[B[D[E]]]', Trees::compactOf(Tree::from(RunningExample::source())->filter(RunningExample::isId('E'), new KeepAncestors())));
    }

    public function testEvaluatesEveryNodeByDefault(): void
    {
        $tested = [];
        $predicate = static function (mixed $n) use (&$tested): bool {
            $tested[] = Field::id($n);

            return RunningExample::matches()($n);
        };
        Tree::from(RunningExample::source())->filter($predicate, new KeepAncestors())->count();

        self::assertSame(['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I'], $tested);
    }

    public function testSkippingAnAncestorWhileItIsFlushedDropsTheRestOfItsSubtree(): void
    {
        $cursor = new KeepAncestors()->apply(RunningExample::source()->open(), new Callback(RunningExample::isId('C', 'I')));
        $seen = [];
        while ($cursor->next()) {
            $seen[] = [$cursor->depth(), $cursor->node()];
            if (Field::id($cursor->node()) === 'B') {
                $cursor->skipSubtree();
            }
        }

        self::assertSame('A[B], H[I]', Trees::compact($seen));
    }

    public function testSkippingTheFlushedRootDropsTheMatchBelowIt(): void
    {
        $cursor = new KeepAncestors()->apply(RunningExample::source()->open(), new Callback(RunningExample::isId('E', 'I')));
        $seen = [];
        while ($cursor->next()) {
            $seen[] = [$cursor->depth(), $cursor->node()];
            if (Field::id($cursor->node()) === 'H') {
                $cursor->skipSubtree();
            }
        }

        self::assertSame('A[B[D[E]]], H', Trees::compact($seen));
    }

    public function testSkippingAMatchIsForwardedToTheInput(): void
    {
        $spy = SpyCursor::on(RunningExample::source());
        $cursor = new KeepAncestors()->apply($spy, new Callback(RunningExample::matches()));
        $seen = [];
        while ($cursor->next()) {
            $seen[] = [$cursor->depth(), $cursor->node()];
            if (Field::id($cursor->node()) === 'A') {
                $cursor->skipSubtree();
            }
        }

        self::assertSame('A, H[I]', Trees::compact($seen));
        self::assertSame(['A'], $spy->skipped);
    }

    public function testKeepDescendantsSkipInsideKeptSubtree(): void
    {
        $cursor = new KeepAncestors(OnMatch::KeepDescendants)->apply(RunningExample::source()->open(), new Callback(RunningExample::matches()));
        $seen = [];
        while ($cursor->next()) {
            $seen[] = [$cursor->depth(), $cursor->node()];
            if (Field::id($cursor->node()) === 'D') {
                $cursor->skipSubtree();
            }
        }

        self::assertSame('A[B[C, D], F[G]], H[I]', Trees::compact($seen));
    }

    public function testSkippingAFlushedAncestorAlsoSkipsItInTheInput(): void
    {
        $spy = SpyCursor::on(RunningExample::source());
        $cursor = new KeepAncestors()->apply($spy, new Callback(RunningExample::isId('C', 'I')));
        $seen = [];
        while ($cursor->next()) {
            $seen[] = [$cursor->depth(), $cursor->node()];
            if (Field::id($cursor->node()) === 'B') {
                $cursor->skipSubtree();
            }
        }

        self::assertSame('A[B], H[I]', Trees::compact($seen));
        // C (the input's current node) and D, the rest of B's subtree, are skipped in the input: E is never read.
        self::assertSame(['C', 'D'], $spy->skipped);
        self::assertSame(['A', 'B', 'C', 'D', 'F', 'G', 'H', 'I'], $spy->visited);
    }
}
