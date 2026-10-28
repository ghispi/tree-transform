# tree-transform documentation

tree-transform filters and transforms trees in PHP. It reads a tree in the shape you store it (nested set,
adjacency list, materialized path, nested arrays, Doctrine entities), runs it through filters and transformations,
and writes the result in the shape you need. Where the input allows, it does this in one streaming pass: memory
grows with the depth of the tree, not with its size.

The library is pre-1.0 (`0.x`). The API can still change between minor versions; see the
[changelog](../CHANGELOG.md).

## Pages

| Page | What is in it |
|---|---|
| [Getting started](getting-started.md) | Install, first filter, the same filter for every input format |
| [Sources](sources.md) | Reading nested sets, adjacency lists, materialized paths, nested arrays, objects |
| [Strategies](strategies.md) | `Prune`, `KeepAncestors`, `Promote`, `Annotate`, `ActiveTrail`, `OnMatch` — drawn as trees |
| [Predicates](predicates.md) | Closures, `AllOf` / `AnyOf` / `Not`, field predicates, the `Ancestors` view |
| [Stages](stages.md) | `map`, `maxDepth`, `depthWindow`, `limit`, `sortSiblings`, chaining |
| [Outputs](outputs.md) | Nested arrays, `iterate()`, flat lists, adjacency lists, nested sets, counts |
| [Doctrine and Gedmo](gedmo.md) | Loading Gedmo trees from Doctrine ORM, efficient queries, pushdown |
| [Recipes](recipes.md) | Menus, breadcrumbs, search, select boxes, empty categories, re-rooting, highlighting |
| [Extending](extending.md) | Your own Source, FilterStrategy, Stage, Sink, Predicate, Accessor |
| [Performance](performance.md) | Memory and speed model, benchmark results, tips |

## Concepts

Every pipeline has three parts:

```
 Source ──▶ Stage ──▶ Stage ──▶ … ──▶ Output
 (how the     (filter with a      (nested array, iterate(),
  tree is      strategy, map,      adjacency list, nested set,
  stored)      maxDepth, limit…)   count, your own Sink)
```

- A **source** reads your data and produces nodes in pre-order: a node, then its whole subtree, then its next
  sibling. Each node comes with its depth (0 for roots). See [Sources](sources.md).
- A **stage** wraps the stream and changes it: removes nodes, changes depths, transforms payloads. Filters are
  stages that combine a **predicate** (does this node match?) with a **strategy** (what happens to the node, its
  subtree and its ancestors). See [Strategies](strategies.md), [Predicates](predicates.md) and [Stages](stages.md).
- An **output** (a sink) consumes the stream and builds the result. See [Outputs](outputs.md).

The `Tree` class is a fluent, immutable front end for all of this:

```php
$menu = Tree::fromNestedSet($rows)                      // source
    ->filter(fn(array $category) => $category['visible']) // stage: Prune strategy by default
    ->maxDepth(2)                                       // stage
    ->toNestedArray();                                  // output
```

Nothing runs until you call an output method. Every method returns a new `Tree`, so you can keep a configured tree
and derive several results from it (see [one-shot inputs](sources.md#reading-a-source-more-than-once) for
generators and database cursors).

Payloads are never modified. Array rows are copied when an output adds a key; objects are passed through or
wrapped.

## Choosing a strategy

All examples on the strategy page use this tree, where ✓ marks the nodes that match the predicate:

```text
A ✓
├─ B ✗
│  ├─ C ✓
│  └─ D ✗
│     └─ E ✓
└─ F ✗
   └─ G ✗
H ✗
└─ I ✓
```

| You want… | Use | Result on the example |
|---|---|---|
| Hide what doesn't match, with everything below it (visibility, permissions) | [`Prune`](strategies.md#prune) (default) | `A` |
| Matches with their path to the root (search in a tree) | [`KeepAncestors`](strategies.md#keepancestors) | `A[B[C, D[E]]], H[I]` |
| Only the matches, moved up to the nearest kept ancestor (flat category lists) | [`Promote`](strategies.md#promote) | `A[C, E], I` |
| Everything, marked as hit / ancestor of a hit / inside a hit / unrelated (highlighting) | [`Annotate`](strategies.md#annotate) | whole tree, tagged |
| A menu expanded down to the current page, or a breadcrumb | [`ActiveTrail`](strategies.md#activetrail) | active = E: `A[B[C, D[E]], F], H` |

`OnMatch` changes what happens *below* a match for `Prune`, `KeepAncestors` and `Promote`: test descendants
(default), keep them all untested, or drop them. See [OnMatch](strategies.md#onmatch).

## About the examples

Every example in these docs is a script in [`examples/`](../examples) and its output is checked by the test suite
(`tests/Docs`). Run one with:

```bash
docker compose run --rm php php examples/strategies-prune.php
```

Many examples draw trees with a small helper, [`examples/support/print-tree.php`](../examples/support/print-tree.php),
which is not part of the library. It takes the `depth => node` stream of a `Tree` and a label closure.
