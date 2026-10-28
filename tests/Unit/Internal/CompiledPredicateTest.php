<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Tests\Unit\Internal;

use Ghispi\TreeTransform\Internal\CompiledPredicate;
use Ghispi\TreeTransform\Predicate\AllOf;
use Ghispi\TreeTransform\Predicate\Ancestors;
use Ghispi\TreeTransform\Predicate\AnyOf;
use Ghispi\TreeTransform\Predicate\Callback;
use Ghispi\TreeTransform\Predicate\DepthRange;
use Ghispi\TreeTransform\Predicate\FieldEquals;
use Ghispi\TreeTransform\Predicate\FieldIn;
use Ghispi\TreeTransform\Predicate\Not;
use Ghispi\TreeTransform\Predicate\Predicate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(CompiledPredicate::class)]
final class CompiledPredicateTest extends TestCase
{
    private static function custom(): Predicate
    {
        return new class implements Predicate {
            public function matches(mixed $node, int $depth, Ancestors $ancestors): bool
            {
                return $ancestors->count() > 0;
            }
        };
    }

    private static function readsAncestors(): Callback
    {
        return new Callback(static fn(mixed $n, int $d, Ancestors $a): bool => $a->count() > 0);
    }

    private static function ignoresAncestors(): Callback
    {
        return new Callback(static fn(mixed $n, int $d): bool => $d > 0);
    }

    /**
     * @return array<string, array{0: Predicate, 1: bool}>
     */
    public static function predicates(): array
    {
        return [
            'callback, no parameters' => [new Callback(static fn(): bool => true), false],
            'callback, node only' => [new Callback(static fn(mixed $n): bool => true), false],
            'callback, node and depth' => [self::ignoresAncestors(), false],
            'callback, three parameters' => [self::readsAncestors(), true],
            'callback, variadic' => [new Callback(static fn(mixed ...$args): bool => true), true],
            'depth range' => [new DepthRange(1), false],
            'field equals' => [new FieldEquals('id', 1), false],
            'field in' => [new FieldIn('id', [1]), false],
            'custom predicate' => [self::custom(), true],
            'empty all of' => [new AllOf(), false],
            'all of, none reads ancestors' => [new AllOf(new DepthRange(1), self::ignoresAncestors()), false],
            'all of, last reads ancestors' => [new AllOf(new DepthRange(1), self::readsAncestors()), true],
            'all of, first reads ancestors' => [new AllOf(self::custom(), new FieldIn('id', [1])), true],
            'empty any of' => [new AnyOf(), false],
            'any of, none reads ancestors' => [new AnyOf(new FieldEquals('id', 1), self::ignoresAncestors()), false],
            'any of, one reads ancestors' => [new AnyOf(new FieldEquals('id', 1), self::readsAncestors()), true],
            'not, inner ignores ancestors' => [new Not(new DepthRange(1)), false],
            'not, inner reads ancestors' => [new Not(self::readsAncestors()), true],
            'nested combinators' => [new Not(new AnyOf(new AllOf(new DepthRange(1)), new Not(self::custom()))), true],
            'nested combinators without reads' => [new Not(new AnyOf(new AllOf(new DepthRange(1)), new Not(new FieldIn('id', [])))), false],
        ];
    }

    #[DataProvider('predicates')]
    public function testNeedsAncestors(Predicate $predicate, bool $expected): void
    {
        self::assertSame($expected, CompiledPredicate::needsAncestors($predicate));
    }

    #[DataProvider('predicates')]
    public function testOfAgreesWithNeedsAncestors(Predicate $predicate, bool $expected): void
    {
        self::assertSame($expected, CompiledPredicate::of($predicate)->needsAncestors);
    }

    public function testOfUsesTheCallbackClosureDirectly(): void
    {
        $closure = static fn(mixed $n): bool => $n === 'x';

        self::assertSame($closure, CompiledPredicate::of(new Callback($closure))->test);
    }

    public function testOfCallsMatchesOfOtherPredicates(): void
    {
        $test = CompiledPredicate::of(new DepthRange(1, 2))->test;
        $ancestors = new Ancestors();

        self::assertFalse($test(null, 0, $ancestors));
        self::assertTrue($test(null, 1, $ancestors));
        self::assertFalse($test(null, 3, $ancestors));
    }
}
