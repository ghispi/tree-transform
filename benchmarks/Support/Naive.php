<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Benchmarks\Support;

/**
 * The "build the full tree, then filter it recursively" approach the library is compared with (SPEC §10).
 *
 * Written the way it usually appears in applications: group rows by parent / build with references, recurse
 * over the nested arrays, copy what survives.
 */
final class Naive
{
    /**
     * Adjacency rows → full nested array (recursive) → recursive filter.
     *
     * @param list<array<string, mixed>>         $rows
     * @param \Closure(array<string, mixed>): bool $keep
     *
     * @return list<array<string, mixed>>
     */
    public static function adjacencyKeepAncestors(array $rows, \Closure $keep): array
    {
        return self::filterKeepAncestors(self::buildFromAdjacency($rows), $keep);
    }

    /**
     * @param list<array<string, mixed>>         $rows
     * @param \Closure(array<string, mixed>): bool $keep
     *
     * @return list<array<string, mixed>>
     */
    public static function adjacencyPrune(array $rows, \Closure $keep): array
    {
        return self::filterPrune(self::buildFromAdjacency($rows), $keep);
    }

    /**
     * Nested set rows → full nested array (Gedmo buildTreeArray() style: level stack with references) →
     * recursive filter. A stand-in for Gedmo buildTree() + filtering, without Doctrine.
     *
     * @param list<array<string, mixed>>         $rows
     * @param \Closure(array<string, mixed>): bool $keep
     *
     * @return list<array<string, mixed>>
     */
    public static function nestedSetKeepAncestors(array $rows, \Closure $keep): array
    {
        return self::filterKeepAncestors(self::buildFromNestedSet($rows), $keep);
    }

    /**
     * @param list<array<string, mixed>>         $rows
     * @param \Closure(array<string, mixed>): bool $keep
     *
     * @return list<array<string, mixed>>
     */
    public static function nestedSetPrune(array $rows, \Closure $keep): array
    {
        return self::filterPrune(self::buildFromNestedSet($rows), $keep);
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    public static function buildFromAdjacency(array $rows): array
    {
        $byParent = [];
        foreach ($rows as $row) {
            $byParent[$row['parent_id'] ?? 0][] = $row;
        }

        return self::children($byParent, 0);
    }

    /**
     * Copy of the algorithm in Gedmo\Tree\RepositoryUtils::buildTreeArray().
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    public static function buildFromNestedSet(array $rows): array
    {
        $tree = [];
        $stack = [];
        foreach ($rows as $item) {
            $item['children'] = [];
            $l = \count($stack);
            while ($l > 0 && $stack[$l - 1]['lvl'] >= $item['lvl']) {
                array_pop($stack);
                --$l;
            }
            if ($l === 0) {
                $i = \count($tree);
                $tree[$i] = $item;
                $stack[] = &$tree[$i];
            } else {
                $i = \count($stack[$l - 1]['children']);
                $stack[$l - 1]['children'][$i] = $item;
                $stack[] = &$stack[$l - 1]['children'][$i];
            }
        }

        /** @var list<array<string, mixed>> $tree */
        return $tree;
    }

    /**
     * @param array<int|string, list<array<string, mixed>>> $byParent
     *
     * @return list<array<string, mixed>>
     */
    private static function children(array $byParent, int|string $parent): array
    {
        $out = [];
        foreach ($byParent[$parent] ?? [] as $row) {
            \assert(\is_int($row['id']));
            $row['children'] = self::children($byParent, $row['id']);
            $out[] = $row;
        }

        return $out;
    }

    /**
     * Keeps a node if it matches or any descendant matches.
     *
     * @param list<array<string, mixed>>         $nodes
     * @param \Closure(array<string, mixed>): bool $keep
     *
     * @return list<array<string, mixed>>
     */
    private static function filterKeepAncestors(array $nodes, \Closure $keep): array
    {
        $out = [];
        foreach ($nodes as $node) {
            \assert(\is_array($node['children']));
            /** @var list<array<string, mixed>> $children */
            $children = $node['children'];
            $node['children'] = self::filterKeepAncestors($children, $keep);
            if ($node['children'] !== [] || $keep($node)) {
                $out[] = $node;
            }
        }

        return $out;
    }

    /**
     * Drops a non-matching node with its subtree.
     *
     * @param list<array<string, mixed>>         $nodes
     * @param \Closure(array<string, mixed>): bool $keep
     *
     * @return list<array<string, mixed>>
     */
    private static function filterPrune(array $nodes, \Closure $keep): array
    {
        $out = [];
        foreach ($nodes as $node) {
            if (!$keep($node)) {
                continue;
            }
            \assert(\is_array($node['children']));
            /** @var list<array<string, mixed>> $children */
            $children = $node['children'];
            $node['children'] = self::filterPrune($children, $keep);
            $out[] = $node;
        }

        return $out;
    }
}
