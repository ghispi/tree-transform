<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Stage;

use Ghispi\TreeTransform\Cursor\Cursor;
use Ghispi\TreeTransform\Source\AdjacencyListCursor;

/**
 * @internal created by SortSiblings
 */
final class SortSiblingsCursor implements Cursor
{
    private ?Cursor $sorted = null;

    /**
     * @param \Closure(mixed, mixed): int $compare
     */
    public function __construct(private readonly Cursor $input, private readonly \Closure $compare) {}

    public function next(): bool
    {
        return ($this->sorted ??= $this->sort())->next();
    }

    public function node(): mixed
    {
        return $this->sorted?->node();
    }

    public function depth(): int
    {
        return $this->sorted?->depth() ?? 0;
    }

    public function skipSubtree(): void
    {
        $this->sorted?->skipSubtree();
    }

    private function sort(): Cursor
    {
        $nodes = [];
        $roots = [];
        /** @var array<int, list<int>> $children */
        $children = [];
        /** @var array<int, int> $open offsets of open ancestors, indexed by depth */
        $open = [];
        $offset = 0;
        while ($this->input->next()) {
            $depth = $this->input->depth();
            $nodes[$offset] = $this->input->node();
            if ($depth === 0) {
                $roots[] = $offset;
            } else {
                $children[$open[$depth - 1]][] = $offset;
            }
            $open[$depth] = $offset;
            ++$offset;
        }

        $compare = $this->compare;
        $byNode = static fn(int $a, int $b): int => $compare($nodes[$a], $nodes[$b]);
        usort($roots, $byNode);
        foreach ($children as &$list) {
            usort($list, $byNode);
        }
        unset($list);

        return new AdjacencyListCursor($nodes, $roots, $children);
    }
}
