<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Tests\Unit\Predicate;

use Ghispi\TreeTransform\Predicate\AllOf;
use Ghispi\TreeTransform\Predicate\Ancestors;
use Ghispi\TreeTransform\Predicate\AnyOf;
use Ghispi\TreeTransform\Predicate\Callback;
use Ghispi\TreeTransform\Predicate\DepthRange;
use Ghispi\TreeTransform\Predicate\Not;
use Ghispi\TreeTransform\Predicate\Predicate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AllOf::class)]
#[CoversClass(AnyOf::class)]
#[CoversClass(Not::class)]
#[CoversClass(Callback::class)]
final class CombinatorsTest extends TestCase
{
    private static function test(Predicate $p, mixed $node = null, int $depth = 0): bool
    {
        return $p->matches($node, $depth, new Ancestors());
    }

    public function testCallbackPassesAllArguments(): void
    {
        $ancestors = new Ancestors();
        $seen = [];
        $p = new Callback(static function (mixed $n, int $d, Ancestors $a) use (&$seen): bool {
            $seen = [$n, $d, $a];

            return true;
        });

        self::assertTrue($p->matches('x', 3, $ancestors));
        self::assertSame(['x', 3, $ancestors], $seen);
    }

    public function testCallbackAcceptsOneParameterClosures(): void
    {
        self::assertTrue(self::test(new Callback(static fn(mixed $n): bool => $n === 'x'), 'x'));
    }

    public function testCallbackCastsTruthyResultsToBool(): void
    {
        self::assertTrue(self::test(new Callback(static fn(): int => 1)));
        self::assertFalse(self::test(new Callback(static fn(): string => '')));
        self::assertFalse(self::test(new Callback(static fn(): ?bool => null)));
    }

    public function testCallbackOfKeepsPredicatesAndWrapsClosures(): void
    {
        $p = new DepthRange(1);

        self::assertSame($p, Callback::of($p));
        self::assertInstanceOf(Callback::class, Callback::of(static fn(): bool => true));
    }

    public function testAllOf(): void
    {
        self::assertTrue(self::test(new AllOf()));
        self::assertTrue(self::test(new AllOf(static fn(): bool => true, new DepthRange(0, 0))));
        self::assertFalse(self::test(new AllOf(static fn(): bool => true, new DepthRange(1))));
    }

    public function testAllOfShortCircuits(): void
    {
        $called = false;
        $p = new AllOf(static fn(): bool => false, static function () use (&$called): bool {
            $called = true;

            return true;
        });

        self::assertFalse(self::test($p));
        self::assertFalse($called);
    }

    public function testAnyOf(): void
    {
        self::assertFalse(self::test(new AnyOf()));
        self::assertTrue(self::test(new AnyOf(static fn(): bool => false, new DepthRange(0, 0))));
        self::assertFalse(self::test(new AnyOf(static fn(): bool => false, new DepthRange(1))));
    }

    public function testAnyOfShortCircuits(): void
    {
        $called = false;
        $p = new AnyOf(static fn(): bool => true, static function () use (&$called): bool {
            $called = true;

            return false;
        });

        self::assertTrue(self::test($p));
        self::assertFalse($called);
    }

    public function testNot(): void
    {
        self::assertFalse(self::test(new Not(static fn(): bool => true)));
        self::assertTrue(self::test(new Not(new DepthRange(1)), null, 0));
        self::assertTrue(self::test(new Not(new Not(new DepthRange(2))), null, 5));
    }
}
