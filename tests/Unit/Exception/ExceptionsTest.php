<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Tests\Unit\Exception;

use Ghispi\TreeTransform\Exception\CycleDetectedException;
use Ghispi\TreeTransform\Exception\InvalidArgumentException;
use Ghispi\TreeTransform\Exception\InvalidTreeException;
use Ghispi\TreeTransform\Exception\LogicException;
use Ghispi\TreeTransform\Exception\OrphanNodeException;
use Ghispi\TreeTransform\Exception\TreeTransformException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CycleDetectedException::class)]
#[CoversClass(InvalidTreeException::class)]
#[CoversClass(OrphanNodeException::class)]
final class ExceptionsTest extends TestCase
{
    public function testEveryExceptionImplementsTheMarker(): void
    {
        foreach ([
            CycleDetectedException::forIds([1]),
            OrphanNodeException::forParent(1, 1),
            InvalidTreeException::at(1, 'x'),
            new InvalidArgumentException(),
            new LogicException(),
        ] as $exception) {
            self::assertInstanceOf(TreeTransformException::class, $exception);
        }
        self::assertInstanceOf(\UnexpectedValueException::class, InvalidTreeException::at(1, 'x'));
        self::assertInstanceOf(InvalidTreeException::class, CycleDetectedException::forIds([1]));
        self::assertInstanceOf(InvalidTreeException::class, OrphanNodeException::forParent(1, 1));
    }

    public function testCycleMessageShowsAtMostTenIds(): void
    {
        $message = CycleDetectedException::forIds(range(1, 12))->getMessage();

        self::assertStringContainsString('12 node(s)', $message);
        self::assertStringContainsString('(e.g. 1, 2, 3, 4, 5, 6, 7, 8, 9, 10)', $message);
    }

    public function testCycleMessageExportsIds(): void
    {
        self::assertSame(
            "Parent cycle detected: 3 node(s) are not reachable from any root (e.g. 'a', 7, '8').",
            CycleDetectedException::forIds(['a', 7, '8'])->getMessage(),
        );
    }

    public function testOrphanMessageSuggestsRootParent(): void
    {
        self::assertStringContainsString('pass it as "rootParent"', OrphanNodeException::forParent('0', 3)->getMessage());
    }

    public function testOrphanMessage(): void
    {
        self::assertSame(
            "Parent '0' of 3 node(s) does not exist. If this value marks root nodes, pass it as \"rootParent\"; "
            . 'otherwise choose an OrphanPolicy (AsRoot or Drop).',
            OrphanNodeException::forParent('0', 3)->getMessage(),
        );
        self::assertStringStartsWith('Parent 5 of 1 node(s)', OrphanNodeException::forParent(5, 1)->getMessage());
    }

    public function testSubclassesCanDescribeIds(): void
    {
        $subclass = new class ('') extends InvalidTreeException {
            public static function describeId(mixed $id): string
            {
                return self::describe($id);
            }
        };

        self::assertSame("'a'", $subclass::describeId('a'));
        self::assertSame('(unknown id)', $subclass::describeId(null));
    }

    public function testInvalidTreeDescribesIds(): void
    {
        self::assertSame("Invalid tree at node 'a': x", InvalidTreeException::at('a', 'x')->getMessage());
        self::assertSame('Invalid tree at node 7: x', InvalidTreeException::at(7, 'x')->getMessage());
        self::assertSame('Invalid tree at node (unknown id): x', InvalidTreeException::at(null, 'x')->getMessage());
        self::assertSame('Invalid tree at node stdClass: x', InvalidTreeException::at(new \stdClass(), 'x')->getMessage());
        self::assertSame('Invalid tree at node float: x', InvalidTreeException::at(1.5, 'x')->getMessage());
    }
}
