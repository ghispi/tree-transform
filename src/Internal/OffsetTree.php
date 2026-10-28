<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Internal;

/**
 * Streams a tree held as integer offsets: nodes by offset, root offsets, and child offsets per parent offset
 * (the index built by AdjacencyListSource and SortSiblings).
 *
 * @internal
 */
final class OffsetTree
{
    /**
     * @param array<int, mixed>     $nodes
     * @param list<int>             $roots
     * @param array<int, list<int>> $children
     *
     * @return \Generator<int, mixed, bool|null, void>
     */
    public static function stream(array $nodes, array $roots, array $children): \Generator
    {
        /** @var array<int, list<int>> $lists sibling lists being walked, indexed by depth */
        $lists = [$roots];
        /** @var array<int, int> $positions next position in each sibling list, indexed by depth */
        $positions = [0];
        $level = 0;
        while ($level >= 0) {
            $position = $positions[$level];
            if (!isset($lists[$level][$position])) {
                unset($lists[$level]);
                --$level;
                continue;
            }
            $offset = $lists[$level][$position];
            $positions[$level] = $position + 1;
            if ((yield $level => $nodes[$offset]) !== true && isset($children[$offset])) {
                $lists[++$level] = $children[$offset];
                $positions[$level] = 0;
            }
        }
    }
}
