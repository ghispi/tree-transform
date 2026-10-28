# tree-transform — Specification (v0.2)

> Status: **agreed baseline**, decisions recorded in §14. Items marked **[DEFERRED]** are decided during
> implementation.
> Package `ghispi/tree-transform`, namespace `Ghispi\TreeTransform\`, PHP `>=8.4`.

## 1. Purpose

A dependency-free PHP library that **filters and transforms trees** efficiently, regardless of how the tree
is stored:

- **Nested set** (`lft` / `rgt` / `level` / `root`)
- **Adjacency list** (plain `id` / `parent_id`)
- **Materialized path** (`path` like `1,4,7,`)
- **Nested arrays** (`children` key, or any iterable of children)

It works out of the box with the tree behaviours of
[`gedmo/doctrine-extensions`](https://github.com/doctrine-extensions/DoctrineExtensions) (NestedSet, Closure,
MaterializedPath) through an optional bridge, but the core never depends on Doctrine.

The core feature is **pluggable filter strategies**: what happens to a node's subtree and ancestors when a node does
or does not match a predicate.

### Non-goals

- Writing trees back to a database or moving nodes (Gedmo already does this).
- Translating arbitrary predicates into SQL. (Structural pushdown — subtree, depth limit — is in scope via the bridge.)
- Rendering (HTML menus, etc.). Users build that on top of the outputs.

## 2. Design principles

| Principle | How it shows up |
|---|---|
| **Streaming first** | Everything is a forward-only pre-order *cursor*. Pre-ordered inputs (nested set, materialized path, nested arrays) are processed in **O(depth)** working memory. |
| **Lazy** | Nothing runs until a terminal operation (`toNestedArray()`, `iterate()`, …). |
| **Never mutate input** | Rows/entities are passed through or copied; Doctrine entities are never touched (no accidental flushes). |
| **Evaluate each predicate at most once per node**, and **not at all** for nodes inside a pruned subtree. |
| **No per-node allocations in the core loop** | Internally a node is `(payload, depth)`; no wrapper object per node. |
| **SOLID** | Small interfaces (Source, Cursor, Predicate, FilterStrategy, Stage, Sink); new strategies/sources/sinks added without modifying existing code; facade depends on abstractions only. |
| **PSR** | PSR-4 autoloading, PER Coding Style 2.0 (successor of PSR-12). No runtime PSR dependencies needed. |

## 3. Architecture

```
 Source ──open()──▶ Cursor ──▶ Stage ──▶ Stage ──▶ … ──▶ Sink ──▶ result
 (nested set,       (pre-order     (filter strategy,     (nested array,
  adjacency, MP,     node+depth)    map, max depth,       adjacency, nested set,
  nested array)                     limit, …)             flat list, generator)
```

Every stage is a `Cursor` decorator, so stages compose freely and a strategy never needs to know the storage format.

### 3.1 Core contracts

```php
namespace Ghispi\TreeTransform\Cursor;

/** Forward-only pre-order traversal over a forest. */
interface Cursor
{
    /** Advance to the next node in pre-order; false when exhausted. */
    public function next(): bool;

    /** Payload of the current node (array row, entity, DTO …), unchanged. */
    public function node(): mixed;

    /** 0 for roots. Contract: depth of the next node <= current depth + 1. */
    public function depth(): int;

    /**
     * Do not visit descendants of the current node. Must be called before next().
     * Sources use this to avoid work (not descending, skipping rows by rgt / path prefix).
     */
    public function skipSubtree(): void;
}

namespace Ghispi\TreeTransform\Source;

interface Source
{
    /** Re-iterable if the underlying input is (arrays yes, one-shot generators/DB iterators no). */
    public function open(): Cursor;
}

namespace Ghispi\TreeTransform\Predicate;

interface Predicate
{
    /** $ancestors is a lazy view, see §5.3; ignore it if you don't need it. */
    public function matches(mixed $node, int $depth, Ancestors $ancestors): bool;
}

namespace Ghispi\TreeTransform\Strategy;

interface FilterStrategy
{
    public function apply(Cursor $input, Predicate $predicate): Cursor;
}

namespace Ghispi\TreeTransform\Stage;

interface Stage
{
    public function apply(Cursor $input): Cursor;
}

namespace Ghispi\TreeTransform\Sink;

/** @template TResult */
interface Sink
{
    /** @return TResult */
    public function consume(Cursor $cursor): mixed;
}
```

Why a cursor class instead of `Generator::send()`: explicit `skipSubtree()` is easier to reason about and test, and it
lets sources skip work. Phase 0 benchmarks a generator-based variant (`yield $depth => $node`) as an alternative
internal representation; the public contract stays the same either way.

### 3.2 Field access

Sources read structural fields (`id`, `parent`, `lft`, `rgt`, `level`, `root`, `path`, `children`) through an
`Accessor` that **compiles a reader closure once per field**, not per node:

```php
interface Accessor
{
    /** @return \Closure(mixed $node): mixed */
    public function reader(string $field): \Closure;
}
```

Implementations: `ArrayKeyAccessor` (`$row['lft']`), `PropertyAccessor` (public props), `GetterAccessor`
(`getLft()`), `PrivatePropertyAccessor` (bound closure — fast, used for Gedmo entities). Array sources get a
specialised fast path that reads keys inline (benchmarked in Phase 0).

Field names are configured with small readonly value objects, e.g.
`new NestedSetFields(left: 'lft', right: 'rgt', level: 'lvl', root: 'root')`, with presets such as
`NestedSetFields::gedmo()`.

## 4. Sources

| Source | Input requirement | Depth from | `skipSubtree()` cost | Memory |
|---|---|---|---|---|
| `NestedSetSource` | ordered by `(root, lft)` | `level` field, else stack of open `rgt` values | skips rows with `lft < rgt(current)` without evaluating them | O(depth) |
| `MaterializedPathSource` | ordered by `path` (binary collation!) | `level` field, else separator count | skips rows with matching path prefix | O(depth) |
| `NestedArraySource` | nested arrays / iterables / Doctrine collections | stack | does not descend | O(depth) |
| `PreorderedAdjacencySource` | rows already in pre-order with `parent` (e.g. Gedmo nested set queried with parent) | stack of ids | skips by depth | O(depth) |
| `AdjacencyListSource` | any order | built index | does not descend | **O(n)** (index of int offsets, no objects) |
| `DepthListSource` | `iterable<int depth, node>` or `[depth, node]` pairs | given | skips by depth | O(1) |

Details:

- **Forests**: all sources support multiple roots. Nested set resets its stack when `root` changes.
- **Adjacency roots**: `parent === null`, or explicit root id(s). **Orphan policy** (parent id not present):
  `OrphanPolicy::AsRoot | Drop | Throw` (default `Throw`).
- **Cycles** (adjacency): nodes unreachable from any root are reported; default throws `CycleDetected`.
- **Sibling order**: input order by default; adjacency source accepts an optional sort field.
- **Materialized path gotcha**: pre-order holds only if the separator sorts before every id character under the
  DB collation. Locale/ICU collations may ignore punctuation — the docs recommend binary collation (`COLLATE "C"`,
  `utf8mb4_bin`). Strict mode detects ordering violations.
- **Validation**: cheap checks always on (depth contract, `lft < rgt`); deep checks (interval nesting, ordering)
  enabled with `strict: true`. Errors are exceptions implementing `Ghispi\TreeTransform\Exception\TreeTransformException`.

## 5. Filter strategies

A strategy decides what to do with a node based on the predicate result. Two orthogonal axes:

1. **Non-match behaviour** — the strategy class.
2. **Match behaviour for descendants** — `OnMatch` option accepted by every built-in strategy:
   - `OnMatch::EvaluateDescendants` (default) — descendants are tested normally.
   - `OnMatch::KeepDescendants` — a match keeps its whole subtree untested (cheaper; "show folder contents").
   - `OnMatch::DropDescendants` — a match is kept but its descendants are not (first match per branch).

### Running example

```
A ✓              ✓ = matches predicate
├─ B ✗
│  ├─ C ✓
│  └─ D ✗
│     └─ E ✓
└─ F ✗
   └─ G ✗
H ✗
└─ I ✓
```

### 5.1 v1 strategies

| Strategy | Rule | Result on example | Memory |
|---|---|---|---|
| **`Prune`** (strict) | Non-match drops the node and its whole subtree; descendants are never evaluated. | `A` | O(depth) |
| **`KeepAncestors`** (search / context) | Node kept if it matches **or** any descendant matches; non-matching ancestors kept as context. | `A[B[C, D[E]]], H[I]` | O(depth) buffer of pending ancestors |
| **`Promote`** (hoist / collapse) | Non-matching nodes removed; matching descendants re-attached to the nearest kept ancestor (or become roots). | `A[C, E], I` | O(depth) |
| **`Annotate`** (mark, non-destructive) | Nothing removed; each node tagged `MatchState::Match / AncestorOfMatch / DescendantOfMatch / None`. For UI highlighting/greying. | whole tree, tagged | buffers one root tree (state of ancestors known only when subtree closes) |

With `OnMatch`:

| Combination | Result on example |
|---|---|
| `Prune` + `KeepDescendants` | `A[B[C, D[E]], F[G]]` (H cut) |
| `KeepAncestors` + `KeepDescendants` | `A[B[C, D[E]], F[G]], H[I]` |
| `Prune` + `DropDescendants` | `A` |
| `Promote` + `DropDescendants` | `A, I` (first match per branch) |

Streaming algorithms (sketch):

- **Prune**: on non-match → `skipSubtree()`; output depth = input depth.
- **KeepAncestors**: keep a stack of not-yet-emitted ancestors. On match → flush the stack, emit the node. When depth
  drops, discard pending entries at deeper or equal depth. Output depth = input depth.
- **Promote**: keep a stack of input depths of *kept* ancestors; output depth = stack size. Pop while
  `stackTop >= currentDepth`.

### 5.1.1 `ActiveTrail` (menu expansion) — v0.1

The CMS navigation pattern. The predicate identifies the **active** node(s); the output is the menu as it looks
expanded down to the active node.

Rule: a node is kept if it is a root, or its parent is **on the trail** (an ancestor of an active node, or an active
node itself). In other words: all roots, the children of every trail node, and the children of the active node.

Options:

| Option | Default | Effect |
|---|---|---|
| `siblings` | `true` | `false` keeps only the trail itself (breadcrumb) instead of every child of trail nodes. |
| `activeChildrenDepth` | `1` | How many levels below the active node are shown (`0` = none, `PHP_INT_MAX` = whole subtree). |
| `multiple` | `true` | Several active nodes → union of their trails. `false` stops evaluating the predicate after the first match. |

On the running example with active = `E`:

| Options | Result |
|---|---|
| defaults | `A[B[C, D[E]], F], H` |
| `siblings: false` | `A[B[D[E]]]` |

Streaming algorithm: while a node's trail status is unknown (its subtree is still open and no active node has been
seen in it), its **direct** children are buffered; when its subtree closes without a match, the buffer is discarded
and the node itself stays in its parent's buffer as a collapsed sibling. Once a match is found, buffers along the
path are flushed, and later children of trail nodes stream straight through.
Memory: O(sum of child counts along the open path) — typically depth × branching, worst case O(n) for a single very
wide node. The Gedmo bridge adds a pushdown query that fetches only roots, the active node's ancestors and their
children (`parent IN (…)`) instead of the full tree.

### 5.2 Further strategies (proposed, later phases)

| Strategy | Use case | Notes |
|---|---|---|
| **`Placeholder`** | Replace each removed run with a stub node ("3 hidden items") instead of silently dropping. | Node factory supplied by user. |
| **`SubtreeAggregate`** | Keep a node if an aggregate over its subtree satisfies a condition: "≥ k matching descendants", "category has products", sum/count thresholds. `KeepAncestors` is the k = 1 special case. | Buffers per subtree. |
| **`MatchWithSiblings`** | Show matches plus their siblings for context (search results in a file tree). | Sibling buffering. |
| **`Reroot` / `DepthWindow(min, max)`** | "Show levels 2–3": nodes at `min` become roots, everything below `max` cut. | Stage, O(depth). |
| **`Limit(n)`** | Stop after n output nodes — early termination stops reading the DB iterator. | Stage, O(1). |
| **`MaxDepth(n)`** | Cut below depth n — implemented as a `Prune` on depth, so the source skips work. | Stage. |
| **`SortSiblings`** | Reorder children by a comparator. | Buffers sibling groups. |

All strategies and stages are open for extension: users implement `FilterStrategy` / `Stage`.

### 5.3 Predicates

`Predicate` plus composable implementations: `Callback` (wraps a closure), `AllOf`, `AnyOf`, `Not`, `DepthRange`,
`FieldEquals`, `FieldIn`. The facade accepts plain closures with one to three parameters — PHP ignores extra
arguments, so `fn (array $n) => $n['visible']` works as well as `fn ($n, int $depth, Ancestors $a) => …`.

**Ancestor access (lazy).** Every predicate receives an `Ancestors` view of the current node's ancestors, root first:

```php
final class Ancestors implements \Countable, \IteratorAggregate
{
    public function count(): int;                    // == $depth
    public function parent(): mixed;                 // null for roots
    public function at(int $depth): mixed;           // ancestor at that depth
    public function some(callable $test): bool;      // short-circuits, nearest ancestor first
    public function every(callable $test): bool;
    public function toArray(): array;                // list, root first — copy it to keep it
    public function getIterator(): \Generator;       // root first
}
```

- Lazy and cheap: the filter stage keeps the ancestors in a depth-indexed array it overwrites as it goes
  (`$path[$depth] = $node`: one array write per node, O(depth) memory). No list is built unless the predicate calls
  `toArray()` / iterates.
- One `Ancestors` instance is reused per stage; it is valid **only during the `matches()` call**.
- Ancestors are those of the stage's **input** (after previous stages, not necessarily the raw source).
- Phase 0 benchmarks the overhead; if it is measurable on `Prune`, tracking is switched on only for predicates
  that use it (closures with a third parameter, detected once by reflection, or a `NeedsAncestors` marker
  interface). The public API stays the same.

Example — hide everything below an unpublished section:

```php
$tree->filter(fn (array $n, int $d, Ancestors $a) => $n['published'] && $a->every(fn ($p) => $p['published']));
```

## 6. Transformation stages

- **`Map(fn(mixed $node, int $depth): mixed)`** — applied lazily, only to nodes that survive filtering.
- **`MaxDepth`, `Limit`, `DepthWindow`, `SortSiblings`** — see 5.2.
- Multiple `filter()` calls chain naturally (each is a stage).

## 7. Sinks (outputs)

| Sink | Output | Memory |
|---|---|---|
| `iterate()` / `FlatSink` | `Generator<int depth, mixed node>` — e.g. indented `<select>` | O(1), fully streaming |
| `NestedArraySink` | nested arrays, configurable children key (`children`, Gedmo's `__children`), option to omit empty children | O(output) |
| `AdjacencyListSink` | flat list with **rewritten** parent ids (matters after `Promote`) | O(output) |
| `NestedSetSink` | flat list in pre-order with **recomputed** `lft` / `rgt` / `level` | O(output) |
| `CountSink`, `IdsSink` | counts / id lists | O(1) / O(output) |

Building nested arrays uses a stack of child lists that are attached to the parent when a level closes — no PHP
references, relying on copy-on-write.

**Objects as payload** **[DEFERRED — decided when `NestedArraySink` is implemented]**: sinks cannot add a
`children` key to an entity, and must not mutate it. Requirements: easy for the common case, typed (PHPStan
generics), no entity mutation. Candidates: a readonly `TreeNode { mixed $data; list<TreeNode> $children }`
wrapper by default; an optional node factory `fn(mixed $node, array $children, int $depth): mixed` for custom shapes
(DTOs, view models); or steering users to `map()` objects to arrays first. The chosen approach and the reasons go
into this section and the docs.

## 8. Facade

Immutable, fluent, lazy:

```php
use Ghispi\TreeTransform\Tree;
use Ghispi\TreeTransform\Strategy\{KeepAncestors, OnMatch};

$menu = Tree::fromNestedSet($rows, NestedSetFields::gedmo())
    ->filter(fn (array $n) => $n['visible'])                       // default strategy: Prune
    ->filter(fn (array $n) => str_contains($n['title'], $q), new KeepAncestors(OnMatch::KeepDescendants))
    ->maxDepth(3)
    ->map(fn (array $n, int $depth) => ['id' => $n['id'], 'label' => $n['title']])
    ->toNestedArray(childrenKey: 'children');
```

Named constructors: `fromNestedSet`, `fromAdjacencyList`, `fromMaterializedPath`, `fromNestedArray`, `from(Source)`.
Terminals: `toNestedArray`, `toAdjacencyList`, `toNestedSet`, `iterate`, `count`, `into(Sink)`.

The facade is sugar only; every piece is usable standalone.

## 9. Doctrine / Gedmo bridge (`Ghispi\TreeTransform\Bridge\Gedmo`)

- **Auto-configuration**: reads the Gedmo tree configuration for an entity class (strategy and field names —
  left/right/level/root/parent/path/separator) from the `TreeListener`, and builds the matching Source with a
  `PrivatePropertyAccessor` (entities) or `ArrayKeyAccessor` (array hydration).
- **Efficient querying**:
  - Correct `ORDER BY` per strategy (`root, lft` / `path`).
  - Streams via `Query::toIterable()`; recommends `HYDRATE_ARRAY` (much cheaper than entities).
  - Structural pushdown: subtree of a node (`lft BETWEEN` / `path LIKE 'x%'`), max depth (`level <= n`).
- **Closure strategy**: queried via the entity's `parent` + `level` and served through the adjacency or pre-ordered
  adjacency source (closure tables do not give pre-order on their own).
- **Output compatibility**: `__children` preset to match `buildTree()` output.
- Supported: `doctrine/orm ^3`, `gedmo/doctrine-extensions ^3`. ODM MaterializedPath works via the generic sources.

**Packaging (decided):** the bridge ships in this package for v0.x. Doctrine and Gedmo are `suggest` + dev
dependencies only; bridge classes are inert unless used, and `conflict` rules exclude unsupported major versions.
Before 1.0 it is split into `ghispi/tree-transform-gedmo` if it grows its own release cadence or dependencies.

## 10. Performance

### Targets (to be calibrated in Phase 0 on PHP 8.4, JIT off)

- Streaming strategies: peak memory growth independent of n — asserted in tests (e.g. 1M generated nodes through
  `iterate()` stays under a fixed budget).
- Throughput target ≥ 1M nodes/s for `Prune` over an array nested set; ≥ 2× faster and lower memory than a naive
  recursive "build full tree then filter" baseline and than Gedmo `buildTree()` + filtering.

### Benchmarks (phpbench)

Shapes: balanced (branching 8, depth 6), wide (1 root × 100k children), deep chain (depth 10k — also proves
no recursion limits), realistic category tree. Sizes 1k / 100k / 1M. Matrix over sources × strategies × sinks.
CI compares PR benchmarks against `main` and flags regressions > 10%.

### Implementation rules

- Iterative traversal only (no recursion).
- Arrays of ints / payload references in buffers; no per-node objects in hot paths.
- Final classes, typed properties, closures compiled once.

## 11. Quality and testing

- **PHPUnit** unit tests per class.
- **Differential / property-based tests**: a seeded random tree generator renders the same tree in all four
  representations; tests assert that (1) all sources yield identical `(depth, id)` sequences, (2) every strategy
  matches a simple recursive **reference oracle** implementation, (3) round-trips (nested set → strategy →
  `NestedSetSink`) produce valid nested sets.
- **Integration tests**: Doctrine ORM + Gedmo on SQLite (and MySQL/PostgreSQL in CI via service containers) for all
  three Gedmo strategies.
- **Static analysis**: PHPStan level max (+ strict rules), full generics in docblocks.
- **Mutation testing**: Infection, MSI ≥ 85% on `src/` (excluding bridge).
- **Code style**: PHP-CS-Fixer with `@PER-CS2.0`, plus Rector for upgrades.
- **Coverage**: ≥ 95% line coverage (PCOV).

## 12. Project and repo

- **Docker by default**: all tooling runs in containers (`compose.yaml` + `Makefile`); nothing needed on the host
  except Docker. CI uses the same image.
- **PHP**: `>=8.4`; CI matrix 8.4 / 8.5, lowest + highest dependencies. 8.4 features in use where they help:
  asymmetric visibility (`public private(set)`) and property hooks for value objects, `new` without parentheses.
- **License**: MIT. SemVer; `0.x` until the API is frozen.
- **GitHub**: Actions for tests, static analysis, mutation, CS, benchmarks; Dependabot; `CHANGELOG.md`
  (Keep a Changelog), `CONTRIBUTING.md`, `SECURITY.md`, issue/PR templates; release to Packagist.

```
src/
  Tree.php
  Cursor/  Source/  Accessor/  Predicate/  Strategy/  Stage/  Sink/
  Bridge/Gedmo/
  Exception/
tests/
  Unit/  Differential/  Integration/  Fixtures/
benchmarks/
docs/
```

## 13. Roadmap

| Phase | Scope |
|---|---|
| **0 — spike** | Docker env, CI skeleton, benchmark harness; cursor class vs generator; inline array fast path vs accessor. Lock the internal representation. |
| **0.1** | Cursor, all sources, `Prune` / `KeepAncestors` / `Promote` + `OnMatch`, `ActiveTrail`, predicates with lazy `Ancestors`, `iterate` / nested array / adjacency sinks, facade, differential tests, docs for all of it. |
| **0.2** | `NestedSetSink`, `Annotate`, `Map` / `MaxDepth` / `Limit` / `DepthWindow`, Gedmo bridge (incl. `ActiveTrail` pushdown) + integration tests. |
| **0.3** | `Placeholder`, `SubtreeAggregate`, `MatchWithSiblings`, `SortSiblings`. |
| **1.0** | API freeze, docs site, benchmark report published. |

Every phase ships its documentation (§15) — a feature is not done until it is documented with a tested example.

## 14. Decisions

| # | Question | Decision |
|---|---|---|
| 1 | Namespace | `Ghispi\TreeTransform\`, package `ghispi/tree-transform` |
| 2 | PHP minimum | 8.4 |
| 3 | Gedmo bridge packaging | Same package for v0.x, split before 1.0 if it grows (§9) |
| 4 | Ancestor access in predicates | Yes, v0.1, lazy `Ancestors` view (§5.3) |
| 5 | Output shape for object payloads | **[DEFERRED]** to `NestedArraySink` implementation; must be easy to use (§7) |
| 6 | `ActiveTrail` in v0.1 | Yes (§5.1.1) |

## 15. Documentation

Documentation is written **together with the code**, in the same change. Target audience: a PHP developer with a
tree in a database who wants a filtered menu/search result in five minutes, then the details.

```
README.md              what it is, install, 30-second example, links
docs/
  index.md             overview and concepts (sources → stages → sinks), choosing a strategy
  getting-started.md   install, first filter for each input format
  sources.md           one section per source: input requirements, field config, gotchas
  strategies.md        every strategy and OnMatch mode with the running example drawn as trees
  predicates.md        closures, combinators, Ancestors
  stages.md            map, maxDepth, limit, …
  outputs.md           every sink, output shapes
  gedmo.md             Doctrine/Gedmo setup, efficient queries, pushdown
  performance.md       memory/speed model, benchmark results, tips
  recipes.md           menus, breadcrumbs, search-in-tree, select boxes, category pruning
  extending.md         writing your own Source, Strategy, Stage, Sink
  SPEC.md              this file (design reference, not user docs)
examples/              runnable scripts, one per recipe/feature
```

Rules:

- **Every code example is tested.** Examples live in `examples/*.php` and are included in the docs; a PHPUnit test
  (`tests/Docs`) runs each example and compares its output with the expected output stored next to it
  (`examples/*.out`). Inline snippets in docs are allowed only for one-liners.
- Every strategy page shows input tree, predicate, and output tree, drawn as ASCII trees.
- Every public class, method and option has a docblock with a one-line summary and PHPStan types.
- `CHANGELOG.md` entry for every user-visible change.
