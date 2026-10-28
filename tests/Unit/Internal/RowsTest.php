<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Tests\Unit\Internal;

use Ghispi\TreeTransform\Exception\InvalidTreeException;
use Ghispi\TreeTransform\Exception\LogicException;
use Ghispi\TreeTransform\Internal\Rows;
use Ghispi\TreeTransform\Tests\Fixtures\Model\BaseEntity;
use Ghispi\TreeTransform\Tests\Fixtures\Model\ChildEntity;
use Ghispi\TreeTransform\Tests\Fixtures\Model\PublicNode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function testUsedGeneratorErrorKeepsCodeZeroAndThePhpErrorAsPrevious(): void
    {
        $generator = (static function (): \Generator {
            yield 1;
        })();
        foreach ($generator as $ignored) {
            // consume
        }

        try {
            Rows::iterator($generator);
            self::fail('Expected LogicException');
        } catch (LogicException $e) {
            self::assertSame(0, $e->getCode());
            self::assertInstanceOf(\Exception::class, $e->getPrevious());
            self::assertStringContainsString('already', $e->getPrevious()->getMessage());
        }
    }

    /**
     * @return array<string, array{0: \Exception}>
     */
    public static function generatorErrors(): array
    {
        return [
            'plain exception with another message' => [new \Exception('database is gone')],
            'subclass with an "already run" message' => [new \RuntimeException('job already run')],
        ];
    }

    /**
     * Errors thrown by the generator's own code on first rewind are not mistaken for reuse.
     */
    #[DataProvider('generatorErrors')]
    public function testErrorsFromTheGeneratorPropagateUnchanged(\Exception $error): void
    {
        $generator = (static function () use ($error): \Generator {
            throw $error;
            yield 1; // @phpstan-ignore deadCode.unreachable
        })();

        try {
            Rows::iterator($generator);
            self::fail('Expected the generator error');
        } catch (\Exception $e) {
            self::assertSame($error, $e);
        }
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

    /**
     * @return array<string, array{0: string, 1: int|string}>
     */
    public static function stringKeys(): array
    {
        return [
            'zero' => ['0', 0],
            'positive' => ['42', 42],
            'negative' => ['-42', -42],
            'leading zero' => ['05', '05'],
            'negative zero' => ['-0', '-0'],
            'leading plus' => ['+5', '+5'],
            'whitespace' => [' 5', ' 5'],
            'trailing text' => ['5a', '5a'],
            'leading text' => ['a5', 'a5'],
            'decimal' => ['1.0', '1.0'],
            'int max' => [(string) \PHP_INT_MAX, \PHP_INT_MAX],
            'beyond int max' => ['99999999999999999999', '99999999999999999999'],
            'below int min' => ['-99999999999999999999', '-99999999999999999999'],
        ];
    }

    /**
     * Same rule as PHP array keys.
     */
    #[DataProvider('stringKeys')]
    public function testKeyOfStrings(string $id, int|string $expected): void
    {
        self::assertSame($expected, Rows::key($id));
        self::assertSame([$expected], array_keys([$id => true]));
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

    public function testDeclaringClass(): void
    {
        self::assertSame(BaseEntity::class, Rows::declaringClass(ChildEntity::class, 'lft'), 'private parent property');
        self::assertSame(ChildEntity::class, Rows::declaringClass(ChildEntity::class, 'title'));
        self::assertSame(PublicNode::class, Rows::declaringClass(PublicNode::class, 'id'));
        self::assertNull(Rows::declaringClass(ChildEntity::class, 'missing'));
        self::assertNull(Rows::declaringClass(\stdClass::class, 'lft'));
    }

    public function testEachKeepsArraysAsTheyAre(): void
    {
        $rows = [3 => 'a', 'k' => 'b'];

        self::assertSame($rows, Rows::each($rows));
        self::assertSame([], Rows::each([]));
    }

    public function testEachReturnsAnEmptyGeneratorAsEmptyArray(): void
    {
        $empty = (static function (): \Generator {
            yield from [];
        })();

        $rows = Rows::each($empty);

        self::assertSame([], $rows);
        self::assertSame([], [...$rows]);
    }

    public function testEachReturnsAPrimedGeneratorSoForeachSeesEveryRow(): void
    {
        $generator = (static function (): \Generator {
            yield 'x' => 1;
            yield 'y' => 2;
        })();

        $rows = Rows::each($generator);

        self::assertSame($generator, $rows);
        self::assertSame(['x' => 1, 'y' => 2], iterator_to_array($rows));
    }

    public function testEachWrapsOtherTraversables(): void
    {
        self::assertSame([1, 2], iterator_to_array(Rows::each(new \ArrayObject([1, 2]))));
    }

    public function testEachRejectsAUsedGeneratorImmediately(): void
    {
        $generator = (static function (): \Generator {
            yield 1;
        })();
        foreach ($generator as $ignored) {
            // consume
        }

        $this->expectException(LogicException::class);
        Rows::each($generator);
    }
}
