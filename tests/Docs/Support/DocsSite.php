<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Tests\Docs\Support;

/**
 * Prepares docs/*.md for the documentation site (make docs-site).
 *
 * The pages stay readable on GitHub, so they link to files outside docs/ with relative paths
 * (`../examples/x.php`, `../CHANGELOG.md`). The site only contains docs/, so those links are rewritten to GitHub
 * URLs at a git ref: the release tag for a deployed site, `main` for a local build.
 */
final class DocsSite
{
    public const string REPOSITORY = 'https://github.com/ghispi/tree-transform';

    private const string OUTSIDE_LINK = '/\]\(\.\.\/([^)\s#]+)(#[^)\s]*)?\)/';

    public function __construct(private readonly string $root) {}

    /**
     * @return array<string, string> page file name => content for the site
     */
    public function pages(string $ref): array
    {
        $pages = [];
        foreach ($this->files() as $file) {
            $pages[basename($file)] = (string) preg_replace_callback(
                self::OUTSIDE_LINK,
                fn(array $m): string => '](' . $this->url($m[1], $ref) . ($m[2] ?? '') . ')',
                (string) file_get_contents($file),
            );
        }

        return $pages;
    }

    /**
     * Every link from docs/ to a file outside it, with whether the target exists.
     *
     * @return list<array{page: string, target: string, exists: bool}>
     */
    public function outsideLinks(): array
    {
        $links = [];
        foreach ($this->files() as $file) {
            preg_match_all(self::OUTSIDE_LINK, (string) file_get_contents($file), $matches);
            foreach ($matches[1] as $target) {
                $links[] = ['page' => basename($file), 'target' => $target, 'exists' => file_exists($this->root . '/' . $target)];
            }
        }

        return $links;
    }

    /**
     * Writes the prepared pages into $dir, replacing what was there.
     */
    public function write(string $dir, string $ref): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0o777, true)) {
            throw new \RuntimeException("Cannot create {$dir}");
        }
        foreach (self::glob($dir . '/*.md') as $old) {
            unlink($old);
        }
        foreach ($this->pages($ref) as $name => $content) {
            file_put_contents($dir . '/' . $name, $content);
        }
    }

    /**
     * @return list<string>
     */
    private function files(): array
    {
        return self::glob($this->root . '/docs/*.md');
    }

    /**
     * @return list<string>
     */
    private static function glob(string $pattern): array
    {
        $files = glob($pattern);

        return $files === false ? [] : $files;
    }

    private function url(string $target, string $ref): string
    {
        $kind = is_dir($this->root . '/' . $target) ? 'tree' : 'blob';

        return self::REPOSITORY . "/{$kind}/{$ref}/" . rtrim($target, '/');
    }
}
