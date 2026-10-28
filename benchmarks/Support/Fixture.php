<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Benchmarks\Support;

/**
 * Deterministic benchmark trees, rendered in every input format the library reads.
 *
 * Every shape is first described as child lists (int offsets), then walked iteratively in pre-order to produce
 * rows. Row ids are 1-based pre-order positions, so rows are ordered by id, by lft and by path at the same time.
 *
 * Row layout (all formats share it):
 *     id, parent_id (null for roots), lft, rgt, lvl, path ("1,5,9,"), title, hit (~10 %), visible (~90 %)
 *
 * - hit:     ~10 % of nodes, the "search" predicate of the strategy benchmarks.
 * - visible: ~90 % of nodes (roots always), the "hide unpublished" predicate used for Prune.
 * - path:    empty for the deep chain (a 10k-level materialized path is unrealistic and ~250 MB).
 *
 * @phpstan-type Row array{id: int, parent_id: int|null, lft: int, rgt: int, lvl: int, path: string, title: string, hit: bool, visible: bool}
 */
final class Fixture
{
    /** @var array<string, string> shape name => description, for reports */
    public const SHAPES = [
        'balanced-37k' => 'balanced, branching 8, depth 5 (37,449 nodes)',
        'balanced-300k' => 'balanced, branching 8, depth 6 (299,593 nodes)',
        'wide-1k' => '1 root x 1,000 children',
        'wide-100k' => '1 root x 100,000 children',
        'deep-10k' => 'chain of depth 10,000',
        'category-1k' => 'random category tree, 1,000 nodes, 8 roots, depth <= 6',
        'category-100k' => 'random category tree, 100,000 nodes, 25 roots, depth <= 7',
    ];

    /** @var array<string, list<array<string, mixed>>> */
    private static array $rowCache = [];

    /**
     * Number of nodes of a shape, without building it.
     */
    public static function size(string $shape): int
    {
        return match ($shape) {
            'balanced-37k' => self::balancedSize(8, 5),
            'balanced-300k' => self::balancedSize(8, 6),
            'wide-1k' => 1_001,
            'wide-100k' => 100_001,
            'deep-10k' => 10_001,
            'category-1k' => 1_000,
            'category-100k' => 100_000,
            default => throw new \InvalidArgumentException('Unknown shape ' . $shape),
        };
    }

    /**
     * Nodes of a balanced tree with levels 0..$depth.
     */
    public static function balancedSize(int $branching, int $depth): int
    {
        return intdiv($branching ** ($depth + 1) - 1, $branching - 1);
    }

    /**
     * Pre-order rows (nested set / materialized path / pre-ordered adjacency / any-order adjacency input).
     *
     * @return list<array<string, mixed>>
     */
    public static function rows(string $shape): array
    {
        // The deep chain gets no materialized path: it would be ~50 KB per row at depth 10k.
        return self::$rowCache[$shape] ??= self::render(...self::structure($shape), withPath: $shape !== 'deep-10k');
    }

    /**
     * Nested arrays with a "children" key (NestedArraySource input). Built iteratively from the rows.
     *
     * @return list<array<string, mixed>>
     */
    public static function nested(string $shape): array
    {
        $roots = [];
        /** @var array<int, array<string, mixed>> $nodes */
        $nodes = [];
        /** @var array<int, list<array<string, mixed>>> $children */
        $children = [];
        $top = -1;
        foreach (self::rows($shape) as $row) {
            $depth = $row['lvl'];
            \assert(\is_int($depth));
            while ($top >= $depth) {
                $built = $nodes[$top] + ['children' => $children[$top]];
                unset($nodes[$top], $children[$top]);
                if (--$top >= 0) {
                    $children[$top][] = $built;
                } else {
                    $roots[] = $built;
                }
            }
            $top = $depth;
            $nodes[$top] = $row;
            $children[$top] = [];
        }
        while ($top >= 0) {
            $built = $nodes[$top] + ['children' => $children[$top]];
            unset($nodes[$top], $children[$top]);
            if (--$top >= 0) {
                $children[$top][] = $built;
            } else {
                $roots[] = $built;
            }
        }

        return $roots;
    }

    /**
     * [depth, row] pairs (DepthListSource::pairs input).
     *
     * @return list<array{0: int, 1: array<string, mixed>}>
     */
    public static function depthPairs(string $shape): array
    {
        $pairs = [];
        foreach (self::rows($shape) as $row) {
            \assert(\is_int($row['lvl']));
            $pairs[] = [$row['lvl'], $row];
        }

        return $pairs;
    }

    /**
     * Id of a node near the end of the pre-order at the deepest level, used as the single "active" menu node.
     */
    public static function deepNodeId(string $shape): int
    {
        $rows = self::rows($shape);
        $best = $rows[0];
        foreach ($rows as $row) {
            if ($row['lvl'] >= $best['lvl']) {
                $best = $row;
            }
        }
        \assert(\is_int($best['id']));

        return $best['id'];
    }

    public static function hit(int $id): bool
    {
        return (($id * 2_654_435_761) & 0xFFFF_FFFF) % 100 < 10;
    }

    public static function visible(int $id, int $depth): bool
    {
        return $depth === 0 || (($id * 40_503 + 12_345) & 0xFFFF_FFFF) % 100 >= 10;
    }

    /**
     * @return array{0: list<int>, 1: array<int, list<int>>, 2: int} roots, children by offset, node count
     */
    private static function structure(string $shape): array
    {
        return match ($shape) {
            'balanced-37k' => self::balanced(8, 5),
            'balanced-300k' => self::balanced(8, 6),
            'wide-1k' => self::wide(1_000),
            'wide-100k' => self::wide(100_000),
            'deep-10k' => self::chain(10_000),
            'category-1k' => self::category(1_000, 8, 6, 1),
            'category-100k' => self::category(100_000, 25, 7, 2),
            default => throw new \InvalidArgumentException('Unknown shape ' . $shape),
        };
    }

    /**
     * @return array{0: list<int>, 1: array<int, list<int>>, 2: int}
     */
    private static function balanced(int $branching, int $depth): array
    {
        $children = [];
        $next = 1;
        $level = [0];
        for ($d = 0; $d < $depth; ++$d) {
            $nextLevel = [];
            foreach ($level as $parent) {
                $list = [];
                for ($k = 0; $k < $branching; ++$k) {
                    $list[] = $next;
                    $nextLevel[] = $next++;
                }
                $children[$parent] = $list;
            }
            $level = $nextLevel;
        }

        return [[0], $children, $next];
    }

    /**
     * @return array{0: list<int>, 1: array<int, list<int>>, 2: int}
     */
    private static function wide(int $width): array
    {
        return [[0], [0 => range(1, $width)], $width + 1];
    }

    /**
     * @return array{0: list<int>, 1: array<int, list<int>>, 2: int}
     */
    private static function chain(int $depth): array
    {
        $children = [];
        for ($i = 0; $i < $depth; ++$i) {
            $children[$i] = [$i + 1];
        }

        return [[0], $children, $depth + 1];
    }

    /**
     * A shop-like category tree: a few roots, wide top levels, long tail of small leaf groups.
     * Each new node attaches to a random earlier node, biased towards shallow nodes (preferential by rank).
     *
     * @return array{0: list<int>, 1: array<int, list<int>>, 2: int}
     */
    private static function category(int $count, int $rootCount, int $maxDepth, int $seed): array
    {
        mt_srand($seed);
        $depth = [];
        $children = [];
        $roots = [];
        $candidates = []; // offsets that may still get children
        for ($i = 0; $i < $count; ++$i) {
            if ($i < $rootCount) {
                $roots[] = $i;
                $depth[$i] = 0;
            } else {
                // r^2 bias: most picks fall on early (shallow, "popular") categories, some deep ones.
                $r = mt_rand() / mt_getrandmax();
                $parent = $candidates[(int) (($r ** 2) * \count($candidates))];
                $children[$parent][] = $i;
                $depth[$i] = $depth[$parent] + 1;
            }
            if ($depth[$i] < $maxDepth) {
                $candidates[] = $i;
            }
        }
        mt_srand();

        return [$roots, $children, $count];
    }

    /**
     * Walks the structure in pre-order (iteratively) and produces rows.
     *
     * @param list<int>             $roots
     * @param array<int, list<int>> $children
     *
     * @return list<array<string, mixed>>
     */
    private static function render(array $roots, array $children, int $count, bool $withPath): array
    {
        $rows = [];
        $counter = 0;
        /** @var list<array{0: int, 1: int, 2: int, 3: string}> $stack [offset, position, parent position, path] */
        $stack = [];
        for ($r = \count($roots) - 1; $r >= 0; --$r) {
            $stack[] = [$roots[$r], -1, -1, ''];
        }
        // Each stack entry is visited twice: on entry (position -1: emit the row, push children) and on exit
        // (set rgt).
        while ($stack !== []) {
            [$offset, $position, $parentPosition, $parentPath] = array_pop($stack);
            if ($position >= 0) {
                $rows[$position]['rgt'] = ++$counter;
                continue;
            }
            $position = \count($rows);
            $id = $position + 1;
            $depth = $parentPosition < 0 ? 0 : $rows[$parentPosition]['lvl'] + 1;
            $path = $withPath ? $parentPath . $id . ',' : '';
            $rows[] = [
                'id' => $id,
                'parent_id' => $parentPosition < 0 ? null : $parentPosition + 1,
                'lft' => ++$counter,
                'rgt' => 0,
                'lvl' => $depth,
                'path' => $path,
                'title' => 'Node ' . $id,
                'hit' => self::hit($id),
                'visible' => self::visible($id, $depth),
            ];
            $stack[] = [$offset, $position, $parentPosition, $parentPath];
            $list = $children[$offset] ?? [];
            for ($k = \count($list) - 1; $k >= 0; --$k) {
                $stack[] = [$list[$k], -1, $position, $path];
            }
        }
        \assert(\count($rows) === $count);

        return $rows;
    }
}
