<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Tests\Fixtures;

use Ghispi\TreeTransform\Predicate\Ancestors;
use Ghispi\TreeTransform\Source\AdjacencyListSource;
use Ghispi\TreeTransform\Source\DepthListSource;
use Ghispi\TreeTransform\Source\MaterializedPathFields;
use Ghispi\TreeTransform\Source\MaterializedPathSource;
use Ghispi\TreeTransform\Source\NestedArraySource;
use Ghispi\TreeTransform\Source\NestedSetFields;
use Ghispi\TreeTransform\Source\NestedSetSource;
use Ghispi\TreeTransform\Source\PreorderedAdjacencySource;
use Ghispi\TreeTransform\Source\Source;

/**
 * Seeded trees, every source rendering of a tree, and predicates, for differential tests.
 *
 * @phpstan-type OraclePredicate \Closure(mixed, int, list<mixed>): bool
 */
final class Matrix
{
    /**
     * Seeded trees of every shape, including the empty forest and a single node.
     *
     * @return iterable<string, array{0: GeneratedTree}>
     */
    public static function trees(int $randomSeeds = 40): iterable
    {
        for ($seed = 1; $seed <= $randomSeeds; ++$seed) {
            $tree = TreeGenerator::random($seed);
            yield $tree->describe() => [$tree];
        }
        foreach ([
            TreeGenerator::random(1000, 0),
            TreeGenerator::chain(1001, 1),
            TreeGenerator::balanced(1002, 3, 3),
            TreeGenerator::balanced(1003, 2, 5),
            TreeGenerator::wide(1004, 60),
            TreeGenerator::chain(1005, 40),
            TreeGenerator::random(1006, 400),
        ] as $tree) {
            yield $tree->describe() => [$tree];
        }
    }

    /**
     * Every source that can read the tree. Factories build a fresh source (generator-backed ones included).
     *
     * @return array<string, \Closure(): Source>
     */
    public static function sources(GeneratedTree $tree): array
    {
        $nestedSet = $tree->nestedSetRows();
        $perRoot = $tree->nestedSetRows(perRoot: true);
        $shuffled = $tree->shuffledAdjacencyRows();
        $preordered = $tree->preorderedRows();
        $paths = $tree->materializedPathRows();
        $slashPaths = $tree->materializedPathRows('/', leading: true);
        $arrays = $tree->nestedArrays();
        $pairs = $tree->depthPairs();

        return [
            'nested set' => static fn(): Source => new NestedSetSource($nestedSet),
            'nested set per root' => static fn(): Source => new NestedSetSource($perRoot, new NestedSetFields(root: 'root')),
            'nested set generator' => static fn(): Source => new NestedSetSource(self::generate($nestedSet)),
            'adjacency shuffled' => static fn(): Source => new AdjacencyListSource($shuffled, siblingOrder: 'rank'),
            'adjacency pre-order' => static fn(): Source => new AdjacencyListSource(self::generate($preordered)),
            'pre-ordered adjacency' => static fn(): Source => new PreorderedAdjacencySource($preordered),
            'materialized path' => static fn(): Source => new MaterializedPathSource($paths),
            'materialized path /a/b' => static fn(): Source => new MaterializedPathSource(self::generate($slashPaths), new MaterializedPathFields(separator: '/')),
            'nested array' => static fn(): Source => new NestedArraySource($arrays),
            'depth list' => static fn(): Source => DepthListSource::pairs($pairs),
        ];
    }

    /**
     * Predicates over generated rows (fields w, id), some using depth and ancestors.
     *
     * @return array<string, OraclePredicate>
     */
    public static function predicates(): array
    {
        return [
            'dense' => static fn(mixed $n): bool => Field::int($n, 'w') % 3 !== 0,
            'sparse' => static fn(mixed $n): bool => Field::int($n, 'w') < 12,
            'none' => static fn(): bool => false,
            'all' => static fn(): bool => true,
            'depth' => static fn(mixed $n, int $depth): bool => (Field::int($n, 'w') + $depth) % 4 !== 0 && $depth < 6,
            'parent' => static fn(mixed $n, int $depth, array $ancestors): bool => Field::int($n, 'w') % 5 !== 0
                && ($ancestors === [] || Field::int($ancestors[\count($ancestors) - 1], 'w') % 7 !== 0),
            'ancestor sum' => static fn(mixed $n, int $depth, array $ancestors): bool => array_sum(array_map(
                static fn(mixed $a): int => Field::int($a, 'w'),
                $ancestors,
            )) % 3 !== 1,
        ];
    }

    /**
     * A few seeded "active" ids of the tree (for ActiveTrail).
     *
     * @return OraclePredicate
     */
    public static function activeIds(GeneratedTree $tree, int $count): \Closure
    {
        $ids = $tree->ids();
        $picked = [];
        if ($ids !== []) {
            $randomizer = new \Random\Randomizer(new \Random\Engine\Mt19937($tree->seed * 31 + $count));
            for ($i = 0; $i < $count; ++$i) {
                $picked[$ids[$randomizer->getInt(0, \count($ids) - 1)]] = true;
            }
        }

        return static fn(mixed $n): bool => isset($picked[Field::id($n)]);
    }

    /**
     * Placeholder factory producing rows the other helpers can read ("[count@depth]" ids, w = 0).
     *
     * @return \Closure(int, int): array{id: string, w: int}
     */
    public static function placeholderFactory(): \Closure
    {
        return static fn(int $hidden, int $depth): array => ['id' => "[{$hidden}@{$depth}]", 'w' => 0];
    }

    /**
     * Adapts an oracle predicate to the library's (node, depth, Ancestors) signature.
     *
     * @param OraclePredicate $predicate
     *
     * @return \Closure(mixed, int, Ancestors): bool
     */
    public static function library(\Closure $predicate): \Closure
    {
        return static fn(mixed $n, int $depth, Ancestors $ancestors): bool => $predicate($n, $depth, $ancestors->toArray());
    }

    /**
     * @template T
     *
     * @param list<T> $rows
     *
     * @return \Generator<int, T>
     */
    public static function generate(array $rows): \Generator
    {
        yield from $rows;
    }
}
