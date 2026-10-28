<?php

declare(strict_types=1);

use Ghispi\TreeTransform\Cursor\Cursor;
use Ghispi\TreeTransform\Predicate\Ancestors;
use Ghispi\TreeTransform\Predicate\Predicate;
use Ghispi\TreeTransform\Strategy\FilterStrategy;
use Ghispi\TreeTransform\Tree;

require __DIR__ . '/../vendor/autoload.php';

$letters = require __DIR__ . '/support/letters.php';
$printTree = require __DIR__ . '/support/print-tree.php';

/**
 * Like Prune (a non-matching node is removed with its subtree), but keeps at most $max nodes per root tree:
 * "a preview of every section". Streaming, O(depth) memory (for the Ancestors view).
 */
final readonly class FirstPerRoot implements FilterStrategy
{
    public function __construct(private int $max) {}

    public function apply(Cursor $input, Predicate $predicate): Cursor
    {
        return new FirstPerRootCursor($input, $predicate, $this->max);
    }
}

final class FirstPerRootCursor implements Cursor
{
    private readonly Ancestors $ancestors;

    private int $kept = 0;

    public function __construct(
        private readonly Cursor $input,
        private readonly Predicate $predicate,
        private readonly int $max,
    ) {
        $this->ancestors = new Ancestors();
    }

    public function next(): bool
    {
        while ($this->input->next()) {
            $depth = $this->input->depth();
            if ($depth === 0) {
                $this->kept = 0;
            }
            if ($this->kept >= $this->max) {
                $this->input->skipSubtree(); // nothing more from this subtree: let the source skip it
                continue;
            }
            $node = $this->input->node();
            $this->ancestors->enter($node, $depth); // keep the Ancestors view up to date for the predicate
            if (!$this->predicate->matches($node, $depth, $this->ancestors)) {
                $this->input->skipSubtree();
                continue;
            }
            ++$this->kept;

            return true;
        }

        return false;
    }

    public function node(): mixed
    {
        return $this->input->node();
    }

    public function depth(): int
    {
        return $this->input->depth();
    }

    public function skipSubtree(): void
    {
        $this->input->skipSubtree();
    }
}

echo $printTree(
    Tree::fromNestedArray($letters)->filter(fn(array $n) => $n['name'] !== 'C', new FirstPerRoot(4)),
    fn(array $n) => $n['name'],
);
