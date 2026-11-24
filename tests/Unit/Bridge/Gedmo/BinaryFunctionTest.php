<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Tests\Unit\Bridge\Gedmo;

use Ghispi\TreeTransform\Bridge\Gedmo\BinaryFunction;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BinaryFunction::class)]
final class BinaryFunctionTest extends TestCase
{
    public function testSqliteIsRecognisedByNameAlongTheInheritanceChain(): void
    {
        self::assertTrue(self::isSqlite(new Fake\SQLitePlatform()));
        self::assertTrue(self::isSqlite(new Fake\CustomSqlite()));
        self::assertFalse(self::isSqlite(new Fake\NotSqlitePlatform()));
        self::assertFalse(self::isSqlite(new \stdClass()));
    }

    private static function isSqlite(object $platform): bool
    {
        $method = new \ReflectionMethod(BinaryFunction::class, 'isSqlite');

        return $method->invoke(null, $platform) === true;
    }
}
