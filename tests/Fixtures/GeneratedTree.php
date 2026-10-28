<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Tests\Fixtures;

use Ghispi\TreeTransform\Tests\Fixtures\Oracle\Node;

/**
 * One logical forest rendered in every input format the library reads.
 *
 * Siblings are ordered by the binary (strcmp) order of their ids, which is the order a materialized path sorted by
 * path yields, so every rendering describes the same ordered forest.
 *
 * Every base row has: id (int), parent_id (int|null), w (0..99, for predicates), root (id of its root) and rank
 * (pre-order position, a sibling sort key for shuffled adjacency rows).
 *
 * @phpstan-type Entry array{id: int, parent: int|null, depth: int, w: int, root: int, rank: int}
 * @phpstan-type Row array{id: int, parent_id: int|null, w: int, root: int, rank: int}
 */
final readonly class GeneratedTree
{
    /**
     * @param list<Entry> $entries pre-order
     */
    public function __construct(
        public string $label,
        public int $seed,
        private array $entries,
    ) {}

    public function describe(): string
    {
        return \sprintf('%s (seed %d, %d nodes)', $this->label, $this->seed, \count($this->entries));
    }

    public function size(): int
    {
        return \count($this->entries);
    }

    /**
     * @return list<int> ids in pre-order
     */
    public function ids(): array
    {
        return array_column($this->entries, 'id');
    }

    /**
     * Expected "depth:id" sequence.
     *
     * @return list<string>
     */
    public function signature(): array
    {
        return array_map(static fn(array $e): string => $e['depth'] . ':' . $e['id'], $this->entries);
    }

    /**
     * @return list<Row>
     */
    public function preorderedRows(): array
    {
        return array_map(self::row(...), $this->entries);
    }

    /**
     * Rows in a seeded random order.
     *
     * @return list<Row>
     */
    public function shuffledAdjacencyRows(): array
    {
        $randomizer = new \Random\Randomizer(new \Random\Engine\Mt19937($this->seed ^ 0x5EED));

        /** @var list<Row> */
        return $randomizer->shuffleArray($this->preorderedRows());
    }

    /**
     * Nested set rows ordered by (root,) lft; with $perRoot every root's tree is numbered from 1.
     *
     * @return list<array{id: int, parent_id: int|null, w: int, root: int, rank: int, lft: int, rgt: int, lvl: int}>
     */
    public function nestedSetRows(bool $perRoot = false): array
    {
        $lefts = [];
        $rights = [];
        /** @var array<int, int> $open */
        $open = [];
        $top = -1;
        $counter = 0;
        foreach ($this->entries as $i => $entry) {
            while ($top >= $entry['depth']) {
                $rights[$open[$top--]] = ++$counter;
            }
            if ($entry['depth'] === 0 && $perRoot) {
                $counter = 0;
            }
            $lefts[$i] = ++$counter;
            $open[++$top] = $i;
        }
        while ($top >= 0) {
            $rights[$open[$top--]] = ++$counter;
        }

        $rows = [];
        foreach ($this->entries as $i => $entry) {
            $rows[] = self::row($entry) + ['lft' => $lefts[$i], 'rgt' => $rights[$i], 'lvl' => $entry['depth']];
        }

        return $rows;
    }

    /**
     * Materialized path rows sorted by path with binary comparison, like ORDER BY path COLLATE "C".
     *
     * @param bool $leading  "/1/4/7" style (leading separator, no trailing one) instead of "1,4,7,"
     *
     * @return list<array{id: int, parent_id: int|null, w: int, root: int, rank: int, path: string}>
     */
    public function materializedPathRows(string $separator = ',', bool $leading = false): array
    {
        /** @var array<int, string> $paths */
        $paths = [];
        $rows = [];
        foreach ($this->entries as $entry) {
            $prefix = $entry['depth'] === 0 ? '' : $paths[$entry['depth'] - 1];
            $paths[$entry['depth']] = $prefix . $entry['id'] . $separator;
            $path = $paths[$entry['depth']];
            if ($leading) {
                $path = $separator . substr($path, 0, -\strlen($separator));
            }
            $rows[] = self::row($entry) + ['path' => $path];
        }
        usort($rows, static fn(array $a, array $b): int => strcmp($a['path'], $b['path']));

        return $rows;
    }

    /**
     * Nested arrays with a children key (always present, empty for leaves).
     *
     * @return list<array<string, mixed>>
     */
    public function nestedArrays(string $childrenKey = 'children'): array
    {
        $roots = [];
        /** @var array<int, array<string, mixed>> $nodes */
        $nodes = [];
        /** @var array<int, list<array<string, mixed>>> $children */
        $children = [];
        $top = -1;
        // A sentinel at depth -1 closes every open node at the end.
        foreach ([...$this->entries, null] as $entry) {
            $depth = $entry === null ? -1 : $entry['depth'];
            while ($top >= 0 && $top >= $depth) {
                $built = $nodes[$top] + [$childrenKey => $children[$top]];
                unset($nodes[$top], $children[$top]);
                --$top;
                if ($top >= 0) {
                    $children[$top][] = $built;
                } else {
                    $roots[] = $built;
                }
            }
            if ($entry !== null) {
                $top = $depth;
                $nodes[$top] = self::row($entry);
                $children[$top] = [];
            }
        }

        return $roots;
    }

    /**
     * @return list<array{0: int, 1: Row}>
     */
    public function depthPairs(): array
    {
        return array_map(static fn(array $e): array => [$e['depth'], self::row($e)], $this->entries);
    }

    /**
     * @return list<Node>
     */
    public function oracleForest(): array
    {
        return Node::forestFromPairs($this->depthPairs());
    }

    /**
     * @param Entry $entry
     *
     * @return Row
     */
    private static function row(array $entry): array
    {
        return [
            'id' => $entry['id'],
            'parent_id' => $entry['parent'],
            'w' => $entry['w'],
            'root' => $entry['root'],
            'rank' => $entry['rank'],
        ];
    }
}
