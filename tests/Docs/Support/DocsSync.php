<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Tests\Docs\Support;

/**
 * Keeps documentation pages in sync with examples/.
 *
 * A page embeds an example between markers:
 *
 *     <!-- example: strategies-prune -->
 *     (generated: the example's code, its output from examples/strategies-prune.out, and a source link)
 *     <!-- /example -->
 *
 * Add " no-output" after the name to embed only the code. Run `make docs` after changing an example.
 */
final class DocsSync
{
    private const string MARKER = '/<!-- example: ([\w-]+)( no-output)? -->.*?<!-- \/example -->/s';

    public function __construct(private readonly string $root) {}

    /**
     * Expands every marker in README.md and docs/*.md (except SPEC.md and gedmo.md).
     *
     * @return array<string, array{string, string}> changed files: path => [current content, expanded content]
     */
    public function pages(): array
    {
        $pages = glob($this->root . '/docs/*.md');
        $files = [...($pages === false ? [] : $pages), $this->root . '/README.md'];
        $changed = [];
        foreach ($files as $file) {
            if (\in_array(basename($file), ['SPEC.md', 'gedmo.md'], true)) {
                continue;
            }
            $current = (string) file_get_contents($file);
            $relative = basename($file) === 'README.md' ? '' : '../';
            $expanded = (string) preg_replace_callback(
                self::MARKER,
                fn(array $m): string => $this->expand($m[1], ($m[2] ?? '') !== '', $relative),
                $current,
            );
            if ($expanded !== $current) {
                $changed[$file] = [$current, $expanded];
            }
        }

        return $changed;
    }

    private function expand(string $name, bool $noOutput, string $relative): string
    {
        $code = (string) file_get_contents("{$this->root}/examples/{$name}.php");
        $code = rtrim((string) preg_replace('/^<\?php\s+declare\(strict_types=1\);\s+/', '', $code), "\n");
        if ($relative === '') {
            $code = str_replace("require __DIR__ . '/../vendor/autoload.php';", "require 'vendor/autoload.php';", $code);
        }
        $parts = ["<!-- example: {$name}" . ($noOutput ? ' no-output' : '') . ' -->', '```php', $code, '```', ''];
        if (!$noOutput) {
            $output = rtrim((string) file_get_contents("{$this->root}/examples/{$name}.out"), "\n");
            $first = ltrim($output)[0] ?? '';
            $language = match ($first) {
                '<' => 'html',
                '[', '{' => 'json',
                default => 'text',
            };
            array_push($parts, 'Output:', '', '```' . $language, $output, '```', '');
        }
        $parts[] = "Source: [`examples/{$name}.php`]({$relative}examples/{$name}.php)";
        $parts[] = '<!-- /example -->';

        return implode("\n", $parts);
    }
}
