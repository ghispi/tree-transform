<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Tests\Api;

use Ghispi\TreeTransform\Exception\CycleDetectedException;
use Ghispi\TreeTransform\Exception\InvalidTreeException;
use Ghispi\TreeTransform\Exception\OrphanNodeException;
use Ghispi\TreeTransform\Predicate\Ancestors;
use Ghispi\TreeTransform\Source\AdjacencyListFields;
use Ghispi\TreeTransform\Source\NestedSetFields;
use Ghispi\TreeTransform\Source\OrphanPolicy;
use Ghispi\TreeTransform\Strategy\ActiveTrail;
use Ghispi\TreeTransform\Strategy\Annotate;
use Ghispi\TreeTransform\Strategy\Annotated;
use Ghispi\TreeTransform\Strategy\KeepAncestors;
use Ghispi\TreeTransform\Strategy\MinMatches;
use Ghispi\TreeTransform\Strategy\OnMatch;
use Ghispi\TreeTransform\Strategy\Placeholder;
use Ghispi\TreeTransform\Strategy\Promote;
use Ghispi\TreeTransform\Strategy\Prune;
use Ghispi\TreeTransform\Tree;

/**
 * The behaviour covered by the BC policy (docs/api.md), as named cases rendered to stable text: the nodes, depths
 * and order every source, strategy and stage yields, the shapes the sinks build, and the exception class (with its
 * ids) thrown for each kind of bad input. Exception messages and predicate call order are not covered, so they are
 * not rendered.
 *
 * The inputs are written out here on purpose, not taken from tests/Fixtures: the snapshot of a release must stay
 * comparable with later code, so nothing but the library may change what a case renders.
 */
final class Behaviour
{
    /**
     * The running example of SPEC §5 (✓ = match): A✓[B✗[C✓, D✗[E✓]], F✗[G✗]], H✗[I✓].
     *
     * @var list<array{0: string, 1: string|null, 2: bool, 3: int, 4: int}> id, parent, match, lft, rgt
     */
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

    /**
     * @return array<string, string> case name => rendered output
     */
    public static function cases(): array
    {
        $cases = [];
        foreach (self::catalogue() as $name => $case) {
            try {
                $cases[$name] = self::render($case());
            } catch (\Throwable $e) {
                $cases[$name] = self::renderException($e);
            }
        }

        return $cases;
    }

    public static function renderAll(): string
    {
        $out = '';
        foreach (self::cases() as $name => $output) {
            $out .= "## {$name}\n{$output}\n";
        }

        return $out;
    }

    /**
     * Parses a rendered snapshot back into cases.
     *
     * @return array<string, string>
     */
    public static function parse(string $snapshot): array
    {
        $cases = [];
        $name = null;
        foreach (explode("\n", $snapshot) as $line) {
            if (str_starts_with($line, '## ')) {
                $name = substr($line, 3);
                $cases[$name] = '';
            } elseif ($name !== null && $line !== '') {
                $cases[$name] .= ($cases[$name] === '' ? '' : "\n") . $line;
            }
        }

        return $cases;
    }

    /**
     * @return array<string, \Closure(): mixed>
     */
    private static function catalogue(): array
    {
        $match = static fn(array $n): bool => self::matches($n);
        $isId = static fn(string ...$ids): \Closure => static fn(array $n): bool => \in_array(self::id($n), $ids, true);
        $tree = static fn(): Tree => Tree::fromNestedSet(self::rows());
        $slim = static fn(Tree $t): Tree => $t->map(static fn(array $n): array => ['id' => self::id($n), 'match' => self::matches($n)]);

        $cases = [];

        // Sources: every input format reads the same forest.
        $cases['source: nested set'] = static fn(): Tree => Tree::fromNestedSet(self::rows());
        $cases['error: nested set, rows not ordered by lft'] = static fn(): Tree => Tree::fromNestedSet(array_reverse(self::rows()));
        $cases['source: nested set with level and root fields'] = static fn(): Tree => Tree::fromNestedSet(
            self::rowsWithRoot(),
            new NestedSetFields(root: 'root', level: 'lvl'),
        );
        $cases['source: adjacency list, shuffled, sibling order'] = static fn(): Tree => Tree::fromAdjacencyList(
            self::shuffled(),
            siblingOrder: 'lft',
        );
        $cases['source: adjacency list, input order is sibling order'] = static fn(): Tree => Tree::fromAdjacencyList(
            array_reverse(self::rows()),
        );
        $cases['source: pre-ordered adjacency list'] = static fn(): Tree => Tree::fromPreorderedAdjacencyList(self::rows());
        $cases['source: materialized path'] = static fn(): Tree => Tree::fromMaterializedPath(self::rows());
        $cases['error: materialized path, rows not ordered by path'] = static fn(): Tree => Tree::fromMaterializedPath(self::shuffled());
        $cases['source: nested array'] = static fn(): Tree => Tree::fromNestedArray(self::nestedArrays());
        $cases['source: depth list'] = static fn(): Tree => Tree::fromDepthList(self::depthList());
        $cases['source: flat list'] = static fn(): Tree => Tree::fromFlatList(
            array_map(static fn(array $e): array => ['depth' => $e[0], 'node' => $e[1]], self::depthPairs()),
        );
        $cases['source: empty'] = static fn(): Tree => Tree::fromNestedSet([]);
        $cases['source: single node'] = static fn(): Tree => Tree::fromAdjacencyList([['id' => 'X', 'parent_id' => null]]);

        // Strategies with every OnMatch mode.
        foreach (OnMatch::cases() as $mode) {
            $cases["filter: Prune {$mode->name}"] = static fn(): Tree => $tree()->filter($match, new Prune($mode));
            $cases["filter: KeepAncestors {$mode->name}"] = static fn(): Tree => $tree()->filter($match, new KeepAncestors($mode));
            $cases["filter: Promote {$mode->name}"] = static fn(): Tree => $tree()->filter($match, new Promote($mode));
            $cases["filter: Placeholder {$mode->name}"] = static fn(): Tree => $tree()->filter(
                $match,
                new Placeholder(static fn(int $hidden, int $depth): string => "{$hidden} hidden at {$depth}", $mode),
            );
        }
        $cases['filter: default strategy'] = static fn(): Tree => $tree()->filter($match);
        $cases['filter: nothing matches'] = static fn(): Tree => $tree()->filter(static fn(): bool => false, new KeepAncestors());
        $cases['filter: everything matches'] = static fn(): Tree => $tree()->filter(static fn(): bool => true, new Promote());
        foreach ([1, 2, 3, 4] as $min) {
            $cases["filter: MinMatches {$min}"] = static fn(): Tree => $tree()->filter($match, new MinMatches($min));
        }
        $cases['filter: depth argument'] = static fn(): Tree => $tree()->filter(
            static fn(array $n, int $depth): bool => $depth !== 1,
            new Prune(),
        );
        $cases['filter: ancestors argument'] = static fn(): Tree => $tree()->filter(
            static fn(array $n, int $depth, Ancestors $ancestors): bool => $ancestors->count() === 0
                || self::id($ancestors->parent()) !== 'B',
            new Prune(),
        );

        // Annotate.
        $cases['annotate: default'] = static fn(): Tree => $tree()->annotate($match);
        $cases['annotate: combine'] = static fn(): Tree => $tree()->annotate(
            $match,
            static fn(array $n, \Ghispi\TreeTransform\Strategy\MatchState $state): string => self::id($n) . '=' . $state->value,
        );
        $cases['annotate: as strategy'] = static fn(): Tree => $tree()->filter($isId('D'), new Annotate());

        // Active trail.
        foreach ([['E'], ['C'], ['A'], ['G', 'I'], []] as $active) {
            $label = $active === [] ? 'none' : implode('+', $active);
            $cases["activeTrail: {$label}"] = static fn(): Tree => $tree()->activeTrail($isId(...$active));
            $cases["activeTrail: {$label}, no siblings"] = static fn(): Tree => $tree()->activeTrail($isId(...$active), siblings: false);
        }
        foreach ([0, 2] as $children) {
            $cases["activeTrail: B, activeChildrenDepth {$children}"] = static fn(): Tree => $tree()->activeTrail(
                $isId('B'),
                activeChildrenDepth: $children,
            );
        }
        $cases['activeTrail: G+I, single'] = static fn(): Tree => $tree()->activeTrail($isId('G', 'I'), multiple: false);
        $cases['activeTrail: as strategy'] = static fn(): Tree => $tree()->filter($isId('E'), new ActiveTrail(siblings: false));

        // Stages.
        foreach ([0, 1, 2] as $max) {
            $cases["maxDepth: {$max}"] = static fn(): Tree => $tree()->maxDepth($max);
        }
        foreach ([[1, 2], [2, PHP_INT_MAX], [0, 0], [3, 3]] as [$min, $max]) {
            $cases["depthWindow: {$min}-{$max}"] = static fn(): Tree => $tree()->depthWindow($min, $max);
        }
        foreach ([0, 1, 4, 100] as $count) {
            $cases["limit: {$count}"] = static fn(): Tree => $tree()->limit($count);
        }
        $cases['sortSiblings: descending id'] = static fn(): Tree => $tree()->sortSiblings(
            static fn(array $a, array $b): int => strcmp(self::id($b), self::id($a)),
        );
        $cases['sortSiblings: stable for ties'] = static fn(): Tree => $tree()->sortSiblings(
            static fn(array $a, array $b): int => (int) self::matches($b) <=> (int) self::matches($a),
        );
        $cases['map: depth into the node'] = static fn(): Tree => $tree()->map(
            static fn(array $n, int $depth): string => self::id($n) . '@' . $depth,
        );

        // Chains.
        $cases['chain: filter, maxDepth, limit'] = static fn(): Tree => $tree()
            ->filter($match, new KeepAncestors())->maxDepth(1)->limit(3);
        $cases['chain: sortSiblings, filter Promote, map'] = static fn(): Tree => $tree()
            ->sortSiblings(static fn(array $a, array $b): int => strcmp(self::id($b), self::id($a)))
            ->filter($match, new Promote())
            ->map(static fn(array $n): string => strtolower(self::id($n)));
        $cases['chain: depthWindow then activeTrail'] = static fn(): Tree => $tree()->depthWindow(1)->activeTrail($isId('E'));
        $cases['chain: filter twice'] = static fn(): Tree => $tree()
            ->filter($match, new KeepAncestors())->filter(static fn(array $n): bool => $n['id'] !== 'D', new Prune());

        // Sinks.
        $cases['sink: toNestedArray'] = static fn(): array => $slim($tree()->filter($match, new KeepAncestors()))->toNestedArray();
        $cases['sink: toNestedArray, omitEmptyChildren, childrenField'] = static fn(): array => $slim($tree()->filter($match, new KeepAncestors()))
            ->toNestedArray(childrenField: 'kids', omitEmptyChildren: true);
        $cases['sink: toNestedArray, nodeFactory'] = static fn(): array => $slim($tree()->maxDepth(1))->toNestedArray(
            nodeFactory: static fn(array $n, array $children): array => [self::id($n) => $children],
        );
        $cases['sink: toNestedArray of scalars'] = static fn(): array => $tree()->maxDepth(1)
            ->map(static fn(array $n): string => self::id($n))->toNestedArray();
        $cases['sink: toFlatList'] = static fn(): array => $slim($tree()->filter($match, new Promote()))->toFlatList();
        $cases['sink: toAdjacencyList'] = static fn(): array => $slim($tree()->filter($match, new Promote()))->toAdjacencyList();
        $cases['sink: toAdjacencyList, fields and rootParent'] = static fn(): array => $slim($tree()->maxDepth(1))->toAdjacencyList(
            new AdjacencyListFields(parent: 'up'),
            rootParent: 0,
        );
        $cases['sink: toNestedSet'] = static fn(): array => $slim($tree()->filter($match, new KeepAncestors()))->toNestedSet();
        $cases['sink: toNestedSet, levelBase 1, gedmo fields'] = static fn(): array => $slim($tree()->maxDepth(1))->toNestedSet(
            NestedSetFields::gedmo(),
            levelBase: 1,
        );
        $cases['sink: count'] = static fn(): int => $tree()->filter($match, new KeepAncestors())->count();
        $cases['sink: iterate keys are depths'] = static fn(): array => array_map(
            static fn(array $e): string => $e[0] . ':' . self::id($e[1]),
            self::entries($tree()->maxDepth(1)->iterate()),
        );
        $cases['sink: input rows are not modified'] = static function () use ($match): array {
            $rows = self::rows();
            Tree::fromNestedSet($rows)->filter($match, new Promote())->toNestedSet();

            return ['unchanged' => $rows === self::rows()];
        };

        // Bad input: exception class and ids (messages are not covered).
        $cases['error: nested set, rgt before lft'] = static fn(): Tree => Tree::fromNestedSet([
            ['id' => 'A', 'lft' => 4, 'rgt' => 1],
        ]);
        $cases['error: nested set, overlapping intervals'] = static fn(): Tree => Tree::fromNestedSet([
            ['id' => 'A', 'lft' => 1, 'rgt' => 4],
            ['id' => 'B', 'lft' => 2, 'rgt' => 6],
        ]);
        $cases['error: adjacency list, cycle'] = static fn(): Tree => Tree::fromAdjacencyList([
            ['id' => 'R', 'parent_id' => null],
            ['id' => 'A', 'parent_id' => 'B'],
            ['id' => 'B', 'parent_id' => 'A'],
        ]);
        $cases['error: adjacency list, orphan'] = static fn(): Tree => Tree::fromAdjacencyList(self::withOrphan());
        $cases['adjacency list, orphan as root'] = static fn(): Tree => Tree::fromAdjacencyList(
            self::withOrphan(),
            orphans: OrphanPolicy::AsRoot,
        );
        $cases['adjacency list, orphan dropped'] = static fn(): Tree => Tree::fromAdjacencyList(
            self::withOrphan(),
            orphans: OrphanPolicy::Drop,
        );
        $cases['error: adjacency list, duplicate id'] = static fn(): Tree => Tree::fromAdjacencyList([
            ['id' => 'A', 'parent_id' => null],
            ['id' => 'A', 'parent_id' => null],
        ]);
        $cases['error: pre-ordered adjacency list, child before parent'] = static fn(): Tree => Tree::fromPreorderedAdjacencyList([
            ['id' => 'B', 'parent_id' => 'A'],
            ['id' => 'A', 'parent_id' => null],
        ]);
        $cases['error: materialized path, gap'] = static fn(): Tree => Tree::fromMaterializedPath(self::withGap());
        $cases['materialized path, gap allowed'] = static fn(): Tree => Tree::fromMaterializedPath(self::withGap(), allowGaps: true);
        $cases['error: depth list, depth jumps by two'] = static fn(): Tree => Tree::fromDepthList([0 => ['id' => 'A'], 2 => ['id' => 'B']]);
        $cases['error: flat list, first depth not 0'] = static fn(): Tree => Tree::fromFlatList([[1, ['id' => 'A']]]);
        $cases['error: generator source read twice'] = static function (): array {
            $tree = Tree::fromNestedSet((static fn(): \Generator => yield from self::rows())());
            $tree->count();

            return [$tree->count()];
        };
        $cases['error: limit -1'] = static fn(): Tree => Tree::fromNestedSet(self::rows())->limit(-1);
        $cases['error: maxDepth -1'] = static fn(): Tree => Tree::fromNestedSet(self::rows())->maxDepth(-1);
        $cases['error: depthWindow 3-1'] = static fn(): Tree => Tree::fromNestedSet(self::rows())->depthWindow(3, 1);
        $cases['error: MinMatches 0'] = static fn(): Tree => Tree::fromNestedSet(self::rows())->filter($match, new MinMatches(0));
        $cases['error: activeTrail activeChildrenDepth -1'] = static fn(): Tree => Tree::fromNestedSet(self::rows())
            ->activeTrail($match, activeChildrenDepth: -1);

        return $cases;
    }

    private static function render(mixed $result): string
    {
        if ($result instanceof Tree) {
            $out = [];
            foreach (self::entries($result->iterate()) as [$depth, $node]) {
                $out[] = $depth . ':' . self::label($node);
            }

            return $out === [] ? '(empty)' : implode(' ', $out);
        }

        return json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function renderException(\Throwable $e): string
    {
        $class = $e::class;
        $short = substr($class, (int) strrpos($class, '\\') + 1);
        $details = match (true) {
            $e instanceof CycleDetectedException => ['ids' => $e->ids()],
            $e instanceof OrphanNodeException => ['parentId' => $e->parentId(), 'childCount' => $e->childCount()],
            $e instanceof InvalidTreeException => ['nodeId' => $e->nodeId()],
            default => [],
        };

        return 'throws ' . (str_starts_with($class, 'Ghispi\\TreeTransform\\') ? $short : $class)
            . ($details === [] ? '' : ' ' . json_encode($details, JSON_THROW_ON_ERROR));
    }

    private static function id(mixed $node): string
    {
        \assert(\is_array($node) && \is_string($node['id'] ?? null));

        return $node['id'];
    }

    private static function matches(mixed $node): bool
    {
        \assert(\is_array($node) && \is_bool($node['match'] ?? null));

        return $node['match'];
    }

    private static function label(mixed $node): string
    {
        return match (true) {
            $node instanceof Annotated => self::label($node->node) . '(' . $node->state->value . ')',
            \is_array($node) && isset($node['id']) && \is_scalar($node['id']) => (string) $node['id'],
            \is_scalar($node) => json_encode($node, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            default => get_debug_type($node),
        };
    }

    /**
     * @param iterable<int, mixed> $iterable depth => node
     *
     * @return list<array{0: int, 1: mixed}>
     */
    private static function entries(iterable $iterable): array
    {
        $out = [];
        foreach ($iterable as $depth => $node) {
            $out[] = [$depth, $node];
        }

        return $out;
    }

    /**
     * @return list<array{id: string, parent_id: string|null, match: bool, lft: int, rgt: int, path: string}>
     */
    private static function rows(): array
    {
        $rows = [];
        $paths = [];
        foreach (self::NODES as [$id, $parent, $match, $lft, $rgt]) {
            $paths[$id] = ($parent === null ? '' : $paths[$parent] ?? '') . $id . ',';
            $rows[] = ['id' => $id, 'parent_id' => $parent, 'match' => $match, 'lft' => $lft, 'rgt' => $rgt, 'path' => $paths[$id]];
        }

        return $rows;
    }

    /**
     * Nested set rows numbered per root, with a level field.
     *
     * @return list<array<string, mixed>>
     */
    private static function rowsWithRoot(): array
    {
        $out = [];
        foreach (self::depthPairs() as [$depth, $row]) {
            $root = $row['path'][0];
            $offset = $root === 'H' ? 14 : 0;
            $out[] = ['id' => $row['id'], 'root' => $root, 'lvl' => $depth, 'lft' => $row['lft'] - $offset, 'rgt' => $row['rgt'] - $offset];
        }

        return $out;
    }

    /**
     * A fixed permutation of the rows (not random: the snapshot must not depend on a seed).
     *
     * @return list<array<string, mixed>>
     */
    private static function shuffled(): array
    {
        $rows = self::rows();

        return array_map(static fn(int $i): array => $rows[$i], [4, 0, 7, 2, 8, 5, 1, 6, 3]);
    }

    /**
     * @return list<array{0: int, 1: array{id: string, parent_id: string|null, match: bool, lft: int, rgt: int, path: string}}>
     */
    private static function depthPairs(): array
    {
        return array_map(
            static fn(array $row): array => [substr_count($row['path'], ',') - 1, $row],
            self::rows(),
        );
    }

    /**
     * @return \Generator<int, array<string, mixed>>
     */
    private static function depthList(): \Generator
    {
        foreach (self::depthPairs() as [$depth, $row]) {
            yield $depth => $row;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function nestedArrays(): array
    {
        $node = static fn(string $id, bool $match, array $children = []): array => ['id' => $id, 'match' => $match, 'children' => $children];

        return [
            $node('A', true, [
                $node('B', false, [$node('C', true), $node('D', false, [$node('E', true)])]),
                $node('F', false, [$node('G', false)]),
            ]),
            $node('H', false, [$node('I', true)]),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function withOrphan(): array
    {
        return [
            ['id' => 'A', 'parent_id' => null],
            ['id' => 'B', 'parent_id' => 'A'],
            ['id' => 'X', 'parent_id' => 'missing'],
            ['id' => 'Y', 'parent_id' => 'X'],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function withGap(): array
    {
        return [
            ['id' => 'A', 'path' => 'A,'],
            ['id' => 'C', 'path' => 'A,B,C,'],
            ['id' => 'D', 'path' => 'D,'],
        ];
    }
}
