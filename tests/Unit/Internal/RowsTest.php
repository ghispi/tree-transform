<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Tests\Unit\Internal;

use Ghispi\TreeTransform\Exception\InvalidTreeException;
use Ghispi\TreeTransform\Exception\LogicException;
use Ghispi\TreeTransform\Internal\Rows;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Rows::class)]
final class RowsTest extends TestCase
{
    public function testIteratorNormalisesIterables(): void
    {
        $iterator = new \ArrayIterator([1]);
        $generator = (static function (): \Generator {
            yield 1;
        })();

        self::assertInstanceOf(\ArrayIterator::class, Rows::iterator([1, 2]));
        self::assertSame($iterator, Rows::iterator($iterator));
        self::assertSame($generator, Rows::iterator($generator));
        self::assertSame([1, 2], iterator_to_array(Rows::iterator(new \ArrayObject([1, 2]))));
    }

    public function testUsedGeneratorIsRejected(): void
    {
        $generator = (static function (): \Generator {
            yield 1;
            yield 2;
        })();
        foreach ($generator as $ignored) {
            // consume
        }

        $this->expectException(LogicException::class);
        Rows::iterator($generator);
    }

    public function testKey(): void
    {
        $stringable = new class implements \Stringable {
            public function __toString(): string
            {
                return 'k';
            }
        };

        self::assertSame(5, Rows::key(5));
        self::assertSame('a', Rows::key('a'));
        self::assertSame('k', Rows::key($stringable));
        self::assertSame(3, Rows::key(3.0));
    }

    public function testKeyRejectsOtherTypes(): void
    {
        $this->expectException(InvalidTreeException::class);
        Rows::key(2.5);
    }

    public function testInt(): void
    {
        self::assertSame(4, Rows::int(4, 'lft', []));
        self::assertSame(4, Rows::int('4', 'lft', []));
    }

    public function testIntRejectsNonNumeric(): void
    {
        $this->expectExceptionMessage('Field "lft" must be an integer, got string (node of type array).');
        Rows::int('four', 'lft', []);
    }
}
