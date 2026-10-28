<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Tests\Fixtures;

use Ghispi\TreeTransform\Source\AdjacencyListSource;
use Ghispi\TreeTransform\Source\DepthListSource;
use Ghispi\TreeTransform\Source\MaterializedPathSource;
use Ghispi\TreeTransform\Source\NestedArraySource;
use Ghispi\TreeTransform\Source\NestedSetSource;
use Ghispi\TreeTransform\Source\PreorderedAdjacencySource;
use Ghispi\TreeTransform\Source\Source;

/**
 * The running example of SPEC §5 (✓ = "match" is true):
 *
 *     A ✓
 *     ├─ B ✗
 *     │  ├─ C ✓
 *     │  └─ D ✗
 *     │     └─ E ✓
 *     └─ F ✗
 *        └─ G ✗
 *     H ✗
 *     └─ I ✓
 */
final class RunningExample
{
    /** @var list<array{0: string, 1: string|null, 2: bool, 3: int, 4: int}> id, parent, match, lft, rgt */
    private const array NODES = [
        ['A', null, true, 1, 14],
        ['B', 'A', false, 2, 9],
        ['C', 'B', true, 3, 4],
        ['D', 'B', false, 5, 8],
        ['E', 'D', true, 6, 7],
        ['F', 'A', false, 10, 13],
        ['G', 'F', false, 11, 12],
        ['H', null, false, 15, 18],
        ['I', 'H', true, 16, 17],
    ];

    public const string WHOLE = 'A[B[C, D[E]], F[G]], H[I]';

    /**
     * @return \Closure(mixed): bool
     */
    public static function matches(): \Closure
    {
        return static fn(mixed $node): bool => Field::bool($node, 'match');
    }

    /**
     * @return \Closure(mixed): bool
     */
    public static function isId(string ...$ids): \Closure
    {
        return static fn(mixed $node): bool => \in_array(Field::id($node), $ids, true);
    }

    /**
     * @return list<array{id: string, parent_id: string|null, match: bool, lft: int, rgt: int, path: string}>
     */
    public static function rows(): array
    {
        $rows = [];
        /** @var array<string, string> $paths */
        $paths = [];
        foreach (self::NODES as [$id, $parent, $match, $lft, $rgt]) {
            $paths[$id] = ($parent === null ? '' : $paths[$parent]) . $id . ',';
            $rows[] = ['id' => $id, 'parent_id' => $parent, 'match' => $match, 'lft' => $lft, 'rgt' => $rgt, 'path' => $paths[$id]];
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function nestedArrays(): array
    {
        $byId = [];
        foreach (self::rows() as $row) {
            $byId[$row['id']] = ['id' => $row['id'], 'match' => $row['match']];
        }
        $node = static fn(string $id, array $children = []): array => $byId[$id] + ['children' => $children];

        return [
            $node('A', [
                $node('B', [$node('C'), $node('D', [$node('E')])]),
                $node('F', [$node('G')]),
            ]),
            $node('H', [$node('I')]),
        ];
    }

    /**
     * The example in every source format.
     *
     * @return array<string, array{0: Source}>
     */
    public static function sources(): array
    {
        $rows = self::rows();
        $depths = ['A' => 0, 'B' => 1, 'C' => 2, 'D' => 2, 'E' => 3, 'F' => 1, 'G' => 2, 'H' => 0, 'I' => 1];

        return [
            'nested set' => [new NestedSetSource($rows)],
            'adjacency list (reversed)' => [new AdjacencyListSource(array_reverse($rows), siblingOrder: 'lft')],
            'pre-ordered adjacency' => [new PreorderedAdjacencySource($rows)],
            'materialized path' => [new MaterializedPathSource($rows)],
            'nested array' => [new NestedArraySource(self::nestedArrays())],
            'depth list' => [DepthListSource::pairs(array_map(static fn(array $r): array => [$depths[$r['id']], $r], $rows))],
        ];
    }

    public static function source(): Source
    {
        return new NestedSetSource(self::rows());
    }
}
