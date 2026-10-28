<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Tests\Docs;

use Ghispi\TreeTransform\Tests\Docs\Support\DocsSync;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * The code and output shown in the docs must match examples/. Fix with: make docs
 */
#[CoversNothing]
final class DocsInSyncTest extends TestCase
{
    public function testExampleBlocksMatchTheExamples(): void
    {
        $changed = (new DocsSync(\dirname(__DIR__, 2)))->pages();

        self::assertSame([], array_map(static fn(string $file): string => basename($file), array_keys($changed)), 'Run "make docs" to update these pages.');
    }
}
