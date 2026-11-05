<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Tests\Docs;

use Ghispi\TreeTransform\Tests\Docs\Support\DocsSite;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class DocsSiteTest extends TestCase
{
    public function testEveryLinkOutOfDocsPointsToAnExistingFile(): void
    {
        $links = new DocsSite(\dirname(__DIR__, 2))->outsideLinks();

        self::assertNotEmpty($links);
        foreach ($links as $link) {
            self::assertTrue($link['exists'], "docs/{$link['page']} links to missing ../{$link['target']}");
        }
    }

    public function testSitePagesLinkToGitHubAtTheRequestedRef(): void
    {
        $pages = new DocsSite(\dirname(__DIR__, 2))->pages('v1.2.3');

        self::assertArrayHasKey('index.md', $pages);
        foreach ($pages as $name => $content) {
            self::assertDoesNotMatchRegularExpression('/\]\(\.\.\//', $content, "{$name} still links outside docs/");
        }
        self::assertStringContainsString('](' . DocsSite::REPOSITORY . '/blob/v1.2.3/CHANGELOG.md)', $pages['index.md']);
        self::assertStringContainsString(DocsSite::REPOSITORY . '/blob/v1.2.3/examples/', $pages['getting-started.md']);
    }
}
