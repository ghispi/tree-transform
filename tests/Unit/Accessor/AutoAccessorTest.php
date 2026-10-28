<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Tests\Unit\Accessor;

use Ghispi\TreeTransform\Accessor\AutoAccessor;
use Ghispi\TreeTransform\Tests\Fixtures\Model\ArrayAccessNode;
use Ghispi\TreeTransform\Tests\Fixtures\Model\ChildEntity;
use Ghispi\TreeTransform\Tests\Fixtures\Model\GetterNode;
use Ghispi\TreeTransform\Tests\Fixtures\Model\MagicNode;
use Ghispi\TreeTransform\Tests\Fixtures\Model\PrivateNode;
use Ghispi\TreeTransform\Tests\Fixtures\Model\PublicNode;
use Ghispi\TreeTransform\Tree;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AutoAccessor::class)]
final class AutoAccessorTest extends TestCase
{
    public function testArraysAndArrayAccess(): void
    {
        $read = new AutoAccessor()->reader('lft');

        self::assertSame(1, $read(['lft' => 1]));
        self::assertNull($read([]));
        self::assertSame(2, $read(new ArrayAccessNode(['lft' => 2])));
    }

    public function testScalarsAndNullReadAsNull(): void
    {
        $read = new AutoAccessor()->reader('lft');

        self::assertNull($read(null));
        self::assertNull($read(42));
        self::assertNull($read('lft'));
    }

    public function testPublicPropertyWins(): void
    {
        self::assertSame(3, new AutoAccessor()->reader('lft')(new PublicNode(1, lft: 3)));
    }

    public function testGetterVariants(): void
    {
        $node = new GetterNode(4, active: true, kids: true, title: 'T');
        $accessor = new AutoAccessor();

        self::assertSame(4, $accessor->reader('id')($node), 'getId()');
        self::assertTrue($accessor->reader('active')($node), 'isActive()');
        self::assertTrue($accessor->reader('kids')($node), 'hasKids()');
        self::assertSame('T', $accessor->reader('title')($node), 'title()');
    }

    public function testPrivatePropertyWithoutGetter(): void
    {
        $accessor = new AutoAccessor();
        $node = new PrivateNode(6, 1, 2);

        self::assertSame(1, $accessor->reader('lft')($node));
        self::assertSame(2, $accessor->reader('rgt')($node), 'protected');
    }

    public function testPrivatePropertyDeclaredInAParentClass(): void
    {
        $node = new ChildEntity(8, 3, 4);

        self::assertSame(3, new AutoAccessor()->reader('lft')($node));
        self::assertSame(1, Tree::fromNestedSet([$node])->count());
    }

    public function testStdClassAndMagicProperties(): void
    {
        $std = new \stdClass();
        $std->lft = 5;
        $accessor = new AutoAccessor();

        self::assertSame(5, $accessor->reader('lft')($std));
        self::assertNull($accessor->reader('rgt')($std));
        self::assertSame(9, $accessor->reader('lft')(new MagicNode(['lft' => 9])));
        self::assertNull($accessor->reader('rgt')(new MagicNode([])));
    }

    public function testResolutionIsCachedPerClassAndField(): void
    {
        $accessor = new AutoAccessor();
        $read = $accessor->reader('id');

        self::assertSame(1, $read(new PublicNode(1)));
        self::assertSame(2, $read(new GetterNode(2)), 'other class resolves on its own');
        self::assertSame(3, $read(new PublicNode(3)));
        self::assertSame(4, $read(['id' => 4]));
    }

    public function testObjectsWorkAsTreePayloadsWithoutConfiguration(): void
    {
        $rows = [new PublicNode(1, lft: 1, rgt: 4), new PublicNode(2, lft: 2, rgt: 3)];

        self::assertSame([0, 1], array_keys(iterator_to_array(Tree::fromNestedSet($rows)->iterate(), false)));
        self::assertSame(2, Tree::fromNestedSet($rows)->count());
    }
}
