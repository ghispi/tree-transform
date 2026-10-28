<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Tests\Unit\Predicate;

use Ghispi\TreeTransform\Predicate\Ancestors;
use Ghispi\TreeTransform\Tests\Fixtures\Field;
use Ghispi\TreeTransform\Tests\Fixtures\RunningExample;
use Ghispi\TreeTransform\Tree;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Ancestors::class)]
final class AncestorsTest extends TestCase
{
    private static function at(string ...$path): Ancestors
    {
        $ancestors = new Ancestors();
        foreach (array_values($path) as $depth => $node) {
            $ancestors->enter($node, $depth);
        }

        return $ancestors;
    }

    public function testRootHasNoAncestors(): void
    {
        $a = self::at('A');

        self::assertCount(0, $a);
        self::assertNull($a->parent());
        self::assertSame([], $a->toArray());
        self::assertSame([], iterator_to_array($a));
        self::assertTrue($a->every(static fn(): bool => false));
        self::assertFalse($a->some(static fn(): bool => true));
    }

    public function testViewOfADeepNode(): void
    {
        $a = self::at('A', 'B', 'D', 'E');

        self::assertCount(3, $a);
        self::assertSame('D', $a->parent());
        self::assertSame('A', $a->at(0));
        self::assertSame('D', $a->at(2));
        self::assertSame(['A', 'B', 'D'], $a->toArray());
        self::assertSame([0 => 'A', 1 => 'B', 2 => 'D'], iterator_to_array($a));
    }

    public function testEnteringAShallowerNodeForgetsDeeperAncestors(): void
    {
        $a = self::at('A', 'B', 'D', 'E');
        $a->enter('F', 1);

        self::assertSame(['A'], $a->toArray());
        self::assertSame('A', $a->parent());
    }

    public function testAtOutOfRange(): void
    {
        $a = self::at('A', 'B');

        $this->expectException(\OutOfRangeException::class);
        $a->at(1);
    }

    public function testAtNegative(): void
    {
        $this->expectException(\OutOfRangeException::class);
        self::at('A', 'B')->at(-1);
    }

    public function testSomeChecksNearestFirstAndShortCircuits(): void
    {
        $order = [];
        $result = self::at('A', 'B', 'D', 'E')->some(static function (mixed $n) use (&$order): bool {
            $order[] = $n;

            return $n === 'B';
        });

        self::assertTrue($result);
        self::assertSame(['D', 'B'], $order);
    }

    public function testEveryChecksNearestFirstAndShortCircuits(): void
    {
        $order = [];
        $result = self::at('A', 'B', 'D', 'E')->every(static function (mixed $n) use (&$order): bool {
            $order[] = $n;

            return $n !== 'B';
        });

        self::assertFalse($result);
        self::assertSame(['D', 'B'], $order);
        self::assertTrue(self::at('A', 'B', 'C')->every(static fn(mixed $n): bool => \is_string($n)));
        self::assertFalse(self::at('A', 'B', 'C')->some(static fn(mixed $n): bool => $n === 'C'));
    }

    public function testSpecExampleHidesEverythingBelowAnUnpublishedSection(): void
    {
        $published = static fn(mixed $n): bool => Field::id($n) !== 'B';
        $tree = Tree::from(RunningExample::source())->filter(
            static fn(mixed $n, int $d, Ancestors $a): bool => $published($n) && $a->every($published),
            new \Ghispi\TreeTransform\Strategy\Promote(),
        );

        self::assertSame(['A', 'F', 'G', 'H', 'I'], array_map(Field::id(...), iterator_to_array($tree->iterate(), false)));
    }
}
