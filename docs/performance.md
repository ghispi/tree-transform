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

Measured with the phpbench suite in [`benchmarks/`](../benchmarks/) on PHP 8.4.27, opcache on, JIT off, in Docker on
a Linux workstation. Times are medians; "own memory" is what the operation itself allocates, without the input. Every
compared pair is checked to produce identical output (`benchmarks/verify.php`). Re-run with `make bench`; the full
tables and the design questions they answered are in [`benchmarks/RESULTS.md`](../benchmarks/RESULTS.md).

Tree shapes: **balanced-300k** (branching 8, depth 6, 299,593 nodes), **category-100k** (a realistic shop tree with
uneven branching), **deep-10k** (a single chain 10,000 levels deep). Filters match about 10% of nodes.

### Reading a tree

`count()` over each source, i.e. the raw cost of reading and validating:

| Source | balanced-300k | category-100k | deep-10k | Own memory |
|---|---|---|---|---|
| Nested set | 80 ms (3.7M nodes/s) | 28 ms | 2.3 ms | 103 KiB |
| Pre-ordered adjacency list | 99 ms (3.0M nodes/s) | 34 ms | 2.8 ms | 103 KiB |
| Materialized path | 142 ms (2.1M nodes/s) | 43 ms | — | 103 KiB |
| Nested arrays | 62 ms (4.8M nodes/s) | 30 ms | 2.7 ms | 64 KiB |
| Adjacency list, any order | 121 ms (2.5M nodes/s) | 49 ms | 4.5 ms | 30 MiB (index) |

### Filtering

Over a nested set array, into `count()`:

| Strategy | balanced-300k | category-100k |
|---|---|---|
| none | 60 ms | 22 ms |
| `Prune` (90% kept) | 76 ms | 28 ms |
| `Prune` (10% kept — skipped subtrees are not read) | 22 ms | 7 ms |
| `Promote` | 92 ms | 31 ms |
| `KeepAncestors` | 104 ms | 34 ms |
| `ActiveTrail` (one active node) | 123 ms | 44 ms |
| `Annotate` | 217 ms | 74 ms |

### Streaming

Rows from a generator through a strategy into `iterate()`. Memory stays flat while the tree grows a hundredfold:

| Pipeline | 11k nodes | 111k nodes | 1.1M nodes | Peak memory |
|---|---|---|---|---|
| Nested set → `Prune` | 12 ms | 90 ms | 795 ms | 1.0 MiB at every size |
| Nested set → `KeepAncestors` | 11 ms | 80 ms | 834 ms | 1.0 MiB at every size |
| Materialized path (no filter) | 11 ms | 86 ms | 929 ms | 1.0 MiB at every size |

### Compared with building the whole tree first

The usual approach is to build the full nested array recursively, then filter it recursively. Same input, same
output, `KeepAncestors` semantics:

| Input, balanced-300k | Naive build-then-filter | This library |
|---|---|---|
| Nested set | 144 ms, 260 MiB | **122 ms, 45 MiB** |
| Adjacency list, any order | **128 ms**, 251 MiB | 170 ms, **63 MiB** |

| Input, category-100k | Naive build-then-filter | This library |
|---|---|---|
| Nested set | 60 ms, 96 MiB | **44 ms, 18 MiB** |
| Adjacency list, any order | **54 ms**, 93 MiB | 65 ms, **31 MiB** |

The library always uses far less memory: 3–6× less here, and constant memory when streaming. It is faster for
inputs that arrive in tree order (nested set, materialized path, pre-ordered adjacency). For adjacency lists in
arbitrary order it is somewhat slower, because it builds an index and checks for cycles before streaming — if you
control the query, order it by a nested set left value or a path and use the pre-ordered source.
