<?php

declare(strict_types=1);

use Ghispi\TreeTransform\Cursor\Cursor;
use Ghispi\TreeTransform\Source\Source;
use Ghispi\TreeTransform\Tree;

require __DIR__ . '/../vendor/autoload.php';

$printTree = require __DIR__ . '/support/print-tree.php';

/**
 * Reads an indented outline (two spaces per level), one node per line. Streams: O(1) memory.
 */
final readonly class OutlineSource implements Source
{
    public function __construct(private string $text) {}

    public function open(): Cursor
    {
        return new OutlineCursor(preg_split('/\R/', trim($this->text, "\n")) ?: []);
    }
}

final class OutlineCursor implements Cursor
{
    private int $position = -1;

    private int $depth = 0;

    private string $node = '';

    private int $skipBelow = -1;

    /** @param list<string> $lines */
    public function __construct(private readonly array $lines) {}

    public function next(): bool
    {
        while (isset($this->lines[++$this->position])) {
            $line = $this->lines[$this->position];
            $depth = intdiv(strlen($line) - strlen(ltrim($line, ' ')), 2);
            // skipSubtree(): pass over deeper lines without returning them.
            if ($this->skipBelow >= 0 && $depth > $this->skipBelow) {
                continue;
            }
            $this->skipBelow = -1;
            $this->depth = $depth;
            $this->node = trim($line);

            return true;
        }

        return false;
    }

    public function node(): mixed
    {
        return $this->node;
    }

    public function depth(): int
    {
        return $this->depth;
    }

    public function skipSubtree(): void
    {
        $this->skipBelow = $this->depth;
    }
}

$outline = <<<'TXT'
    Shop
      Clothing
        Men
        Women
      Archive
        Old collection
      Electronics
    TXT;

echo $printTree(
    Tree::from(new OutlineSource($outline))->filter(fn(string $title) => $title !== 'Archive'),
    fn(string $title) => $title,
);
