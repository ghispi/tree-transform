<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Tests\Api;

use PHPUnit\Framework\TestCase;

/**
 * The public API (docs/api.md) is recorded in public-api.txt. A change to it fails this test until the snapshot is
 * regenerated with `make api-snapshot` — so every API change shows up as a reviewable diff, including parameter
 * renames that the BC checker does not report.
 */
final class PublicApiTest extends TestCase
{
    private const string SNAPSHOT = __DIR__ . '/public-api.txt';

    public function testPublicApiMatchesTheSnapshot(): void
    {
        $actual = PublicApi::render(\dirname(__DIR__, 2) . '/src');
        if (getenv('UPDATE_API_SNAPSHOT') === '1') {
            file_put_contents(self::SNAPSHOT, $actual);
        }

        self::assertFileExists(self::SNAPSHOT, 'Run `make api-snapshot` to record the public API.');
        self::assertSame(
            file_get_contents(self::SNAPSHOT),
            $actual,
            'The public API changed. If intended, run `make api-snapshot`, review the diff of tests/Api/public-api.txt '
            . 'and note the change in CHANGELOG.md (a break needs a major version after 1.0).',
        );
    }
}
