<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Tests\Api;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * The behaviour the BC policy covers (docs/api.md) is recorded in behaviour.txt. Any change to it fails this test
 * until the snapshot is regenerated with `make api-snapshot`, so every behaviour change shows up as a reviewable
 * diff. `make behaviour-check` compares with the snapshot of the latest release.
 */
#[CoversNothing]
final class BehaviourSnapshotTest extends TestCase
{
    private const string SNAPSHOT = __DIR__ . '/behaviour.txt';

    public function testBehaviourMatchesTheSnapshot(): void
    {
        $actual = Behaviour::renderAll();
        if (getenv('UPDATE_API_SNAPSHOT') === '1') {
            file_put_contents(self::SNAPSHOT, $actual);
        }

        self::assertFileExists(self::SNAPSHOT, 'Run `make api-snapshot` to record the behaviour.');
        self::assertSame(
            file_get_contents(self::SNAPSHOT),
            $actual,
            'Covered behaviour changed. If intended, run `make api-snapshot`, review the diff of '
            . 'tests/Api/behaviour.txt and note the change in CHANGELOG.md (after 1.0 a break needs a major version; '
            . 'a bug fix that changes a released case is listed in tests/Api/behaviour-changes.txt).',
        );
    }

    public function testParseReadsBackWhatRenderWrites(): void
    {
        self::assertSame(Behaviour::cases(), Behaviour::parse(Behaviour::renderAll()));
    }
}
