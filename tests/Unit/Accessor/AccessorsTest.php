<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Tests\Unit\Accessor;

use Ghispi\TreeTransform\Accessor\ArrayKeyAccessor;
use Ghispi\TreeTransform\Accessor\GetterAccessor;
use Ghispi\TreeTransform\Accessor\PrivatePropertyAccessor;
use Ghispi\TreeTransform\Accessor\PropertyAccessor;
use Ghispi\TreeTransform\Tests\Fixtures\Model\ArrayAccessNode;
use Ghispi\TreeTransform\Tests\Fixtures\Model\ChildEntity;
use Ghispi\TreeTransform\Tests\Fixtures\Model\GetterNode;
use Ghispi\TreeTransform\Tests\Fixtures\Model\PrivateNode;
use Ghispi\TreeTransform\Tests\Fixtures\Model\PublicNode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ArrayKeyAccessor::class)]
#[CoversClass(PropertyAccessor::class)]
#[CoversClass(GetterAccessor::class)]
#[CoversClass(PrivatePropertyAccessor::class)]
final class AccessorsTest extends TestCase
{
    public function testArrayKeyAccessorReadsArraysAndArrayAccess(): void
    {
        $read = new ArrayKeyAccessor()->reader('lft');

        self::assertSame(3, $read(['lft' => 3]));
        self::assertNull($read(['rgt' => 3]));
        self::assertNull($read(['lft' => null]));
        self::assertSame(4, $read(new ArrayAccessNode(['lft' => 4])));
        self::assertNull($read(new ArrayAccessNode([])));
    }

    public function testPropertyAccessorReadsPublicAndDynamicProperties(): void
    {
        $read = new PropertyAccessor()->reader('lft');
        $std = new \stdClass();
        $std->lft = 7;

        self::assertSame(2, $read(new PublicNode(1, lft: 2)));
        self::assertSame(7, $read($std));
        self::assertNull($read(new \stdClass()));
    }

    public function testGetterAccessorPrefixesGetUnlessTheFieldIsAGetterName(): void
    {
        $node = new GetterNode(9, 3, active: true, kids: true);
        $accessor = new GetterAccessor();

        self::assertSame(9, $accessor->reader('id')($node));
        self::assertSame(3, $accessor->reader('parentId')($node));
        self::assertTrue($accessor->reader('isActive')($node));
        self::assertTrue($accessor->reader('hasKids')($node));
        self::assertSame(9, $accessor->reader('getId')($node));
    }

    public function testGetterAccessorDoesNotTreatPrefixedWordsAsGetters(): void
    {
        // "island" starts with "is" but is not "is" + capital letter: read with getIsland().
        $node = new class {
            public function getIsland(): string
            {
                return 'yes';
            }
        };

        self::assertSame('yes', new GetterAccessor()->reader('island')($node));
    }

    public function testGetterAccessorOnlyRecognisesPrefixesAtTheStart(): void
    {
        // "budgetTotal" contains "get" + capital letter, but not at the start: read with getBudgetTotal().
        $node = new class {
            public function getBudgetTotal(): int
            {
                return 7;
            }
        };

        self::assertSame(7, new GetterAccessor()->reader('budgetTotal')($node));
    }

    public function testPrivatePropertyAccessorReadsAnyVisibility(): void
    {
        $node = new PrivateNode(5, 1, 2);
        $accessor = new PrivatePropertyAccessor(PrivateNode::class);

        self::assertSame(5, $accessor->reader('id')($node));
        self::assertSame(1, $accessor->reader('lft')($node));
        self::assertSame(2, $accessor->reader('rgt')($node));
        self::assertNull($accessor->reader('missing')($node));
    }

    public function testPrivatePropertyAccessorReadsPrivatePropertiesDeclaredInAParent(): void
    {
        $node = new ChildEntity(8, 3, 4);
        $accessor = new PrivatePropertyAccessor(ChildEntity::class);

        self::assertSame(8, $accessor->reader('id')($node));
        self::assertSame(3, $accessor->reader('lft')($node));
        self::assertSame('child', $accessor->reader('title')($node));
    }

    public function testPrivatePropertyAccessorReturnsNullForUninitialisedNull(): void
    {
        self::assertNull(new PrivatePropertyAccessor(PrivateNode::class)->reader('lft')(new PrivateNode(1)));
    }

    public function testGetterNamesAreBuiltWithAnUppercasedField(): void
    {
        $node = new class {
            /**
             * @param list<mixed> $arguments
             */
            public function __call(string $name, array $arguments): mixed
            {
                return $name === 'getTitle' ? 'called' : null;
            }
        };

        self::assertSame('called', new GetterAccessor()->reader('title')($node));
    }
}
