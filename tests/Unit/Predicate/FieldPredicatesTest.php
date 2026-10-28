<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Tests\Unit\Predicate;

use Ghispi\TreeTransform\Accessor\GetterAccessor;
use Ghispi\TreeTransform\Exception\InvalidArgumentException;
use Ghispi\TreeTransform\Predicate\Ancestors;
use Ghispi\TreeTransform\Predicate\DepthRange;
use Ghispi\TreeTransform\Predicate\FieldEquals;
use Ghispi\TreeTransform\Predicate\FieldIn;
use Ghispi\TreeTransform\Predicate\Predicate;
use Ghispi\TreeTransform\Tests\Fixtures\Model\GetterNode;
use Ghispi\TreeTransform\Tests\Fixtures\Model\PublicNode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(DepthRange::class)]
#[CoversClass(FieldEquals::class)]
#[CoversClass(FieldIn::class)]
final class FieldPredicatesTest extends TestCase
{
    private static function test(Predicate $p, mixed $node = null, int $depth = 0): bool
    {
        return $p->matches($node, $depth, new Ancestors());
    }

    public function testDepthRangeIsInclusive(): void
    {
        $p = new DepthRange(1, 2);

        self::assertSame([false, true, true, false], array_map(static fn(int $d): bool => self::test($p, null, $d), [0, 1, 2, 3]));
        self::assertTrue(self::test(new DepthRange(), null, 1000));
    }

    /**
     * @return array<string, array{0: int, 1: int}>
     */
    public static function invalidRanges(): array
    {
        return ['negative min' => [-1, 3], 'max below min' => [3, 2]];
    }

    #[DataProvider('invalidRanges')]
    public function testDepthRangeRejectsInvalidRanges(int $min, int $max): void
    {
        $this->expectException(InvalidArgumentException::class);

        new DepthRange($min, $max);
    }

    public function testFieldEqualsIsStrictExceptForIntegerStrings(): void
    {
        $p = new FieldEquals('id', 5);

        self::assertTrue(self::test($p, ['id' => 5]));
        self::assertTrue(self::test($p, ['id' => '5']), 'databases return integers as strings');
        self::assertFalse(self::test($p, ['id' => '05']));
        self::assertFalse(self::test($p, ['id' => 5.0]));
        self::assertFalse(self::test($p, ['id' => true]));
        self::assertFalse(self::test($p, ['other' => 5]));
        self::assertTrue(self::test(new FieldEquals('id', '-7'), ['id' => -7]));
        self::assertFalse(self::test(new FieldEquals('id', '5x'), ['id' => 5]));
        self::assertFalse(self::test(new FieldEquals('flag', true), ['flag' => 1]));
        self::assertFalse(self::test(new FieldEquals('flag', '1'), ['flag' => true]));
        self::assertTrue(self::test(new FieldEquals('missing', null), ['id' => 5]));
    }

    public function testFieldEqualsReadsObjects(): void
    {
        self::assertTrue(self::test(new FieldEquals('match', true), new PublicNode(1, match: true)));
        self::assertTrue(self::test(new FieldEquals('isActive', true, new GetterAccessor()), new GetterNode(1, active: true)));
    }

    public function testFieldInTreatsNumericStringsAndIntsAlike(): void
    {
        $p = new FieldIn('id', [5, 'x']);

        self::assertTrue(self::test($p, ['id' => 5]));
        self::assertTrue(self::test($p, ['id' => '5']));
        self::assertTrue(self::test($p, ['id' => 'x']));
        self::assertFalse(self::test($p, ['id' => 6]));
        self::assertFalse(self::test($p, ['id' => null]));
        self::assertFalse(self::test($p, ['id' => 5.0]));
        self::assertFalse(self::test(new FieldIn('id', []), ['id' => 5]));
    }

    public function testFieldInAcceptsAnyIterable(): void
    {
        $values = (static function (): \Generator {
            yield 1;
            yield 2;
        })();

        self::assertTrue(self::test(new FieldIn('id', $values), ['id' => 2]));
    }

    public function testFieldInRejectsNonScalarValues(): void
    {
        $this->expectException(InvalidArgumentException::class);

        /** @phpstan-ignore argument.type (deliberately invalid) */
        new FieldIn('id', [1.5]);
    }
}
