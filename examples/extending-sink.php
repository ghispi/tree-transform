<?php

declare(strict_types=1);

use Ghispi\TreeTransform\Cursor\Cursor;
use Ghispi\TreeTransform\Sink\Sink;
use Ghispi\TreeTransform\Tree;

require __DIR__ . '/../vendor/autoload.php';

$rows = require __DIR__ . '/support/shop.php';

/**
 * Renders nested <ul> lists in one pass, without building a tree: it only needs the depth of each node.
 *
 * @implements Sink<string>
 */
final readonly class HtmlListSink implements Sink
{
    /** @param Closure(mixed): string $label */
    public function __construct(private Closure $label) {}

    public function consume(Cursor $cursor): string
    {
        $html = '';
        $open = -1; // depth of the deepest open <li>
        while ($cursor->next()) {
            $depth = $cursor->depth();
            if ($depth > $open) {
                $html .= ($open >= 0 ? "\n" : '') . self::indent(2 * $depth) . "<ul>\n";
            } else {
                $html .= "</li>\n" . $this->close($open, $depth);
            }
            $html .= self::indent(2 * $depth + 1) . '<li>' . htmlspecialchars(($this->label)($cursor->node()));
            $open = $depth;
        }

        return $open < 0 ? '' : $html . "</li>\n" . $this->close($open, 0) . "</ul>\n";
    }

    /** Closes the lists from depth $from up to depth $to. */
    private function close(int $from, int $to): string
    {
        $html = '';
        for ($depth = $from; $depth > $to; --$depth) {
            $html .= self::indent(2 * $depth) . "</ul>\n" . self::indent(2 * $depth - 1) . "</li>\n";
        }

        return $html;
    }

    private static function indent(int $level): string
    {
        return str_repeat('  ', $level);
    }
}

echo Tree::fromNestedSet($rows)
    ->maxDepth(2)
    ->depthWindow(1)
    ->filter(fn(array $c) => $c['slug'] !== 'garden')
    ->into(new HtmlListSink(fn(array $c) => $c['title']));
