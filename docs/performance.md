# Performance

The library processes a tree as a forward-only stream of `(node, depth)` pairs in pre-order. Every source, stage
and output is a cursor that pulls the next node from the one before it. Nothing is read until an output asks for
it, and most components keep only the current path in memory.

Notation: *n* = number of nodes, *d* = depth of the tree (longest path), *b* = number of children of a node.
"Memory" means working memory on top of your input and the result.

## Memory per component

### Sources

| Source | Memory | `skipSubtree()` |
|---|---|---|
| `NestedSetSource` | O(d) — right values of the open ancestors | skips rows by comparing `lft` only |
| `MaterializedPathSource` | O(d) — open path prefixes | skips rows by path prefix |
| `NestedArraySource` | O(d) — one iterator per open level | does not descend (lazy collections stay unloaded) |
| `PreorderedAdjacencySource` | O(d) — ids of the open ancestors | skips rows by depth |
| `DepthListSource` | O(1) | skips entries by depth |
| `AdjacencyListSource` | **O(n)** — all rows plus an index of integer offsets (no object per node) | does not descend |
| `LazySource` | that of the source it creates | — |

Rows that a source skips are still fetched from the iterator (a database cursor cannot jump), but they are not
tested by predicates or mapped. To avoid fetching them at all, restrict the query: a subtree (`lft BETWEEN`,
`path LIKE`), a level limit (`level <= n`). [`GedmoTree`](gedmo.md) does this for you.

### Strategies

| Strategy | Memory | Predicate calls |
|---|---|---|
| `Prune` | O(d) (the `Ancestors` path) | only nodes whose parent was kept |
| `Placeholder` | O(d) | as `Prune` |
| `Promote` | O(d) — input depths of kept ancestors | every node (none below a match with `DropDescendants` / `KeepDescendants`) |
| `KeepAncestors` | O(d) — not yet emitted ancestors | every node (none below a match with `DropDescendants` / `KeepDescendants`) |
| `ActiveTrail` | O(sum of *b* along the current path) — typically *d* × *b*, worst case O(n) for one very wide node | every node; with `multiple: false`, none after the first match |
| `MinMatches` | up to the size of one undecided subtree (O(n) worst case) | every node |
| `Annotate` | up to the size of one root's tree (O(n) for a single root) | every node |

`Ancestors` costs one array write per tested node and O(d) memory; no list is built unless a predicate asks for one.

### Stages

| Stage | Memory | Notes |
|---|---|---|
| `map()` | O(1) | runs only for nodes that reach it |
| `maxDepth()` | O(1) | skips the subtrees below the limit |
| `depthWindow()` | O(1) | reads nodes above `$min`, skips below `$max` |
| `limit()` | O(1) | stops pulling from the input after `$count` nodes |
| `sortSiblings()` | **O(n)** | reads the whole input before the first node comes out |

### Outputs

| Output | Memory |
|---|---|
| `iterate()`, `foreach` | O(1) — fully streaming |
| `count()` | O(1) |
| `toNestedArray()` | O(output) — the result, built bottom-up, no references |
| `toFlatList()` | O(output) |
| `toAdjacencyList()` | O(output) + O(d) for the parent ids |
| `toNestedSet()` | O(output) — right values are known only when a subtree ends |

So a pipeline of a pre-ordered source, streaming strategies and stages, and `iterate()` or `count()` uses
O(d) memory no matter how many nodes pass through.

## Skipping work

`Prune`, `maxDepth()` and `depthWindow()` call `skipSubtree()`, so the nodes inside a removed subtree are never
tested or mapped. `limit()` stops reading the input altogether.

<!-- example: performance-skipping -->
```php
use Ghispi\TreeTransform\Strategy\KeepAncestors;
use Ghispi\TreeTransform\Strategy\Prune;
use Ghispi\TreeTransform\Tree;

require __DIR__ . '/../vendor/autoload.php';

$rows = require __DIR__ . '/support/shop.php';

// Count how often the predicate runs: Prune never tests nodes inside a removed subtree.
$calls = 0;
$notClothing = function (array $c) use (&$calls): bool {
    ++$calls;

    return $c['slug'] !== 'clothing';
};

foreach (['Prune' => new Prune(), 'KeepAncestors' => new KeepAncestors()] as $name => $strategy) {
    $calls = 0;
    $kept = Tree::fromNestedSet($rows)->filter($notClothing, $strategy)->count();
    printf("%-14s kept %2d of %d nodes, predicate called %2d times\n", $name, $kept, count($rows), $calls);
}

// maxDepth() and filters skip work too: map() only runs for nodes that survive.
$mapped = 0;
Tree::fromNestedSet($rows)->maxDepth(1)->map(function (array $c) use (&$mapped) {
    ++$mapped;

    return $c;
})->count();
echo "map() after maxDepth(1) ran {$mapped} times\n";
```

Output:

```text
Prune          kept  7 of 13 nodes, predicate called  8 times
KeepAncestors  kept 13 of 13 nodes, predicate called 13 times
map() after maxDepth(1) ran 4 times
```

Source: [`examples/performance-skipping.php`](../examples/performance-skipping.php)
<!-- /example -->

`Prune` removed Clothing and never tested its 5 descendants. `KeepAncestors` has to test every node, because a
match can be anywhere below; here all nodes are kept, Clothing as the ancestor of its matching children.

## Streaming

A generator of 200,000 nested set rows, filtered and iterated: the extra memory stays flat.

<!-- example: performance-streaming -->
```php
use Ghispi\TreeTransform\Tree;

require __DIR__ . '/../vendor/autoload.php';

// 200,000 generated nested set rows (1000 sections × 199 items), produced one at a time like a database cursor.
$rows = (function (): Generator {
    $left = 1;
    for ($section = 1; $section <= 1000; ++$section) {
        yield ['id' => $section, 'visible' => $section % 2 === 0, 'lft' => $left, 'rgt' => $left + 2 * 199 + 1];
        for ($item = 1; $item <= 199; ++$item) {
            yield ['id' => $section * 1000 + $item, 'visible' => true, 'lft' => $left + 2 * $item - 1, 'rgt' => $left + 2 * $item];
        }
        $left += 2 * 199 + 2;
    }
})();

$before = memory_get_usage();
$peak = 0;
$count = 0;
foreach (Tree::fromNestedSet($rows)->filter(fn(array $row) => $row['visible'])->iterate() as $depth => $row) {
    ++$count;
    $peak = max($peak, memory_get_usage() - $before);
}

echo "Streamed {$count} of 200000 nodes\n";
echo 'Extra memory stayed under 1 MB: ', $peak < 1024 * 1024 ? 'yes' : 'no', "\n";
```

Output:

```text
Streamed 100000 of 200000 nodes
Extra memory stayed under 1 MB: yes
```

Source: [`examples/performance-streaming.php`](../examples/performance-streaming.php)
<!-- /example -->

## Tips

- **Order in the database.** Pre-ordered inputs stream; `AdjacencyListSource` and `sortSiblings()` need O(n).
  If your adjacency rows come from a nested set table, query them `ORDER BY root, lft` and use
  `fromPreorderedAdjacencyList()`.
- **Hydrate arrays, not entities.** With Doctrine, array rows are much cheaper than managed entities. See
  [Doctrine and Gedmo](gedmo.md).
- **Stream from the database.** Pass `$query->toIterable()` instead of `getResult()`, and use `Tree::lazy()` when you
  need several outputs.
- **Filter first, map last.** `map()` runs only for nodes that reach it.
- **Prefer `Prune` when it fits.** It is the cheapest strategy and lets sources skip work. Use `OnMatch::KeepDescendants`
  with `KeepAncestors` when the contents of a match need no testing.
- **Use `iterate()` for large outputs.** It renders without building the result tree.
- **Restrict the query** to the subtree or depth you need; skipped rows are still fetched.

## Benchmark results

> **Placeholder.** This section is filled in from the phpbench suite in `benchmarks/` (sources × strategies ×
> sinks on balanced, wide, deep and realistic trees, 1k / 100k / 1M nodes). Until then, see `make bench`.
