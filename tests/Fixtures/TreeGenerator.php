<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Tests\Fixtures;

/**
 * Deterministic, seeded random forests. The same seed always yields the same tree.
 *
 * Shapes: random forest, balanced, wide (one root with many children), deep chain. Ids are a seeded permutation of
 * 1..n so that id order differs from pre-order and ids have different string lengths.
 */
final class TreeGenerator
{
    /**
     * Random forest of 0..$maxNodes nodes, mixing deep runs, bushy nodes and several roots.
     */
    public static function random(int $seed, int $maxNodes = 120): GeneratedTree
    {
        $randomizer = self::randomizer($seed);
        $count = $randomizer->getInt(0, $maxNodes);
        $parents = [];
        for ($k = 0; $k < $count; ++$k) {
            $roll = $randomizer->getInt(0, 99);
            $parents[$k] = match (true) {
                $k === 0, $roll < 8 => null,
                $roll < 50 => $k - 1,
                default => $randomizer->getInt(0, $k - 1),
            };
        }

        return self::build('random', $seed, $parents, $randomizer);
    }

    public static function balanced(int $seed, int $branching, int $depth): GeneratedTree
    {
        $parents = [null];
        $level = [0];
        for ($d = 1; $d <= $depth; ++$d) {
            $next = [];
            foreach ($level as $parent) {
                for ($b = 0; $b < $branching; ++$b) {
                    $next[] = \count($parents);
                    $parents[] = $parent;
                }
            }
            $level = $next;
        }

        return self::build("balanced {$branching}x{$depth}", $seed, $parents, self::randomizer($seed));
    }

    public static function wide(int $seed, int $width): GeneratedTree
    {
        $parents = [null];
        for ($i = 0; $i < $width; ++$i) {
            $parents[] = 0;
        }

        return self::build("wide 1x{$width}", $seed, $parents, self::randomizer($seed));
    }

    public static function chain(int $seed, int $length): GeneratedTree
    {
        $parents = [];
        for ($i = 0; $i < $length; ++$i) {
            $parents[] = $i === 0 ? null : $i - 1;
        }

        return self::build("chain {$length}", $seed, $parents, self::randomizer($seed));
    }

    private static function randomizer(int $seed): \Random\Randomizer
    {
        return new \Random\Randomizer(new \Random\Engine\Mt19937($seed));
    }

    /**
     * @param array<int, int|null> $parents parent index of each node index (null = root); parents come first
     */
    private static function build(string $label, int $seed, array $parents, \Random\Randomizer $randomizer): GeneratedTree
    {
        $count = \count($parents);
        $ids = $count === 0 ? [] : $randomizer->shuffleArray(range(1, $count));
        $weights = [];
        for ($k = 0; $k < $count; ++$k) {
            $weights[$k] = $randomizer->getInt(0, 99);
        }

        $roots = [];
        /** @var array<int, list<int>> $children */
        $children = [];
        foreach ($parents as $k => $parent) {
            if ($parent === null) {
                $roots[] = $k;
            } else {
                $children[$parent][] = $k;
            }
        }
        $byId = static fn(int $a, int $b): int => strcmp((string) $ids[$a], (string) $ids[$b]);
        usort($roots, $byId);
        foreach ($children as &$list) {
            usort($list, $byId);
        }
        unset($list);

        // Iterative pre-order: a stack of [index, depth, root id] in reverse sibling order.
        $entries = [];
        $stack = [];
        foreach (array_reverse($roots) as $root) {
            $stack[] = [$root, 0, $ids[$root]];
        }
        while ($stack !== []) {
            [$k, $depth, $rootId] = array_pop($stack);
            $parent = $parents[$k];
            $entries[] = [
                'id' => $ids[$k],
                'parent' => $parent === null ? null : $ids[$parent],
                'depth' => $depth,
                'w' => $weights[$k],
                'root' => $rootId,
                'rank' => \count($entries),
            ];
            foreach (array_reverse($children[$k] ?? []) as $child) {
                $stack[] = [$child, $depth + 1, $rootId];
            }
        }

        return new GeneratedTree($label, $seed, $entries);
    }
}
