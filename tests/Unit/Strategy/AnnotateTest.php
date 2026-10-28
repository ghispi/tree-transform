<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Tests\Unit\Strategy;

use Ghispi\TreeTransform\Predicate\Callback;
use Ghispi\TreeTransform\Source\Source;
use Ghispi\TreeTransform\Strategy\Annotate;
use Ghispi\TreeTransform\Strategy\AnnotateCursor;
use Ghispi\TreeTransform\Strategy\Annotated;
use Ghispi\TreeTransform\Strategy\MatchState;
use Ghispi\TreeTransform\Tests\Fixtures\Field;
use Ghispi\TreeTransform\Tests\Fixtures\RunningExample;
use Ghispi\TreeTransform\Tests\Fixtures\Trees;
use Ghispi\TreeTransform\Tree;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Annotate::class)]
#[CoversClass(AnnotateCursor::class)]
#[CoversClass(Annotated::class)]
final class AnnotateTest extends TestCase
{
    /**
     * @return array<string, array{0: Source}>
     */
    public static function sources(): array
    {
        return RunningExample::sources();
    }

    #[DataProvider('sources')]
    public function testRunningExampleIsTaggedByDefaultWithAnnotated(Source $source): void
    {
        $states = [];
        foreach (Tree::from($source)->annotate(RunningExample::matches())->iterate() as $node) {
            self::assertInstanceOf(Annotated::class, $node);
            $states[Field::id($node->node)] = $node->state;
        }

        self::assertSame([
            'A' => MatchState::Match,
            'B' => MatchState::AncestorOfMatch,
            'C' => MatchState::Match,
            'D' => MatchState::AncestorOfMatch,
            'E' => MatchState::Match,
            'F' => MatchState::DescendantOfMatch,
            'G' => MatchState::DescendantOfMatch,
            'H' => MatchState::AncestorOfMatch,
            'I' => MatchState::Match,
        ], $states);
    }

    public function testStructureIsUnchanged(): void
    {
        self::assertSame(RunningExample::WHOLE, Trees::compactOf(Tree::from(RunningExample::source())->annotate(RunningExample::matches())));
    }

    public function testCombineMergesTheState(): void
    {
        $tree = Tree::from(RunningExample::source())
            ->annotate(RunningExample::isId('G'), static fn(mixed $n, MatchState $s): string => Field::id($n) . '=' . $s->value);
        $label = static fn(mixed $n): string => \is_string($n) ? $n : '?';

        self::assertSame(
            'A=ancestor[B=none[C=none, D=none[E=none]], F=ancestor[G=match]], H=none[I=none]',
            Trees::compact(Trees::pairs($tree), $label),
        );
    }

    public function testInputPayloadIsNotModified(): void
    {
        $rows = RunningExample::rows();
        $copy = $rows;
        Tree::fromNestedSet($rows)->annotate(RunningExample::matches())->toFlatList();

        self::assertSame($copy, $rows);
    }

    public function testConsumerSkip(): void
    {
        $cursor = new Annotate()->apply(RunningExample::source()->open(), new Callback(RunningExample::matches()));
        $seen = [];
        while ($cursor->next()) {
            $seen[] = [$cursor->depth(), $cursor->node()];
            if (Field::id($cursor->node()) === 'B') {
                $cursor->skipSubtree();
            }
        }

        self::assertSame('A[B, F[G]], H[I]', Trees::compact($seen));
    }

    public function testEmptyInput(): void
    {
        self::assertSame([], Tree::fromNestedSet([])->annotate(static fn(): bool => true)->toFlatList());
    }
}
