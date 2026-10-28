# Benchmark results (Phase 0)

Environment: PHP 8.4.27 CLI (Docker `php:8.4-cli-alpine`), x86_64, **opcache on, JIT off**, pcov off,
`zend.assertions=-1` (all set in `phpbench.json` → `runner.php_config`; the dev image has pcov and assertions on,
which would distort the numbers). phpbench 1.7.0, `--retry-threshold=5`, every iteration in a fresh process with
GC disabled. Numbers below are pooled from two full runs (`phase0_run3`, `phase0_run4`, made against `src/` as of
commit `c4632dc`): **median [min – max] over all iterations of both runs**. Runs 1–2 (before that commit) gave
the same picture within noise.

- **nodes/s** = input nodes / time (for Prune that counts the rows the source skips, too).
- **mem_peak** = phpbench `mem_peak`: fixture **plus** the subject's peak (the fixture is built before
  `memory_reset_peak_usage()`).
- **own peak** = `mem_peak − mem_final`: the subject's own working memory, with the fixture left out.
- Shapes: balanced (branching 8, depth 5 = 37,449 / depth 6 = 299,593 nodes), wide (1 × 1k / 100k), deep chain
  (depth 10k), random category tree (1k / 100k). Sets named `… x100` run 100 passes per revolution, so divide the
  time by 100. Streaming: balanced branching 10 at 11,111 / 111,111 / 1,111,111 nodes, rows generated lazily.
- Predicates: `hit` matches ~10 % of nodes (search); `visible` matches ~90 % (roots always), the realistic Prune.

Reproduce (about 11 min per full run):

```sh
docker compose run --rm php php benchmarks/verify.php            # every compared pair computes the same output
docker compose run --rm php vendor/bin/phpbench run benchmarks --report=aggregate --retry-threshold=5 \
    --tag=mytag --store --dump-file=var/bench.xml                  # compare later with --ref=mytag
docker compose run --rm php php benchmarks/report.php var/bench.xml [more.xml …]   # these tables
```

## 0.4: generator pipeline vs v0.1.0

Roadmap 0.4 replaced the cursor objects between built-ins with one chain of generators (proposal #1 below) and
rebuilt the adjacency index. Same machine, same settings, the two versions run back to back
(`var/bench-v010.xml` from a `git archive` of v0.1.0, `var/bench-040.xml`); the naive baselines, which do not use
the library, came out within ±5 % between the two runs, so the machine was steady. Medians, balanced-300k /
category-100k:

| Subject | v0.1.0 | 0.4 | Speed-up |
|---|---|---|---|
| nested set → `count()` | 64.8 / 21.9 ms | 27.5 / 10.0 ms | 2.2× – 2.4× |
| `Prune` 90 % kept → `count()` | 77.0 / 28.0 ms | 39.2 / 16.1 ms | 1.7× – 2.0× |
| `Prune` 10 % kept → `count()` | 22.6 / 7.6 ms | 10.2 / 3.5 ms | 2.2× |
| `KeepAncestors` → `count()` | 98.3 / 36.3 ms | 50.6 / 18.6 ms | 1.9× – 2.0× |
| `Promote` → `count()` | 99.3 / 31.3 ms | 48.3 / 17.2 ms | 1.8× – 2.1× |
| `Annotate` → `count()` | 230.4 / 73.1 ms | 132.8 / 44.3 ms | 1.7× |
| `ActiveTrail` (one active node) → `count()` | 128.4 / 43.5 ms | 65.7 / 25.6 ms | 1.7× – 2.0× |
| `iterate()` (nested set) | 76.8 / 26.7 ms | 31.3 / 11.3 ms | 2.4× |
| materialized path → `iterate()` | 125.4 / 42.1 ms | 85.4 / 27.8 ms | 1.5× |
| adjacency list, any order → `iterate()` | 118.4 / 46.7 ms | 67.1 / 26.6 ms | 1.8× |
| `toNestedArray()` (sink, every node) | 139.2 / 53.2 ms | 94.1 / 35.9 ms | 1.5× |
| nested set → `KeepAncestors` → nested array | 121.9 / 43.5 ms | 76.4 / 26.0 ms | 1.6× – 1.7× |
| streaming nested set → `Prune`, 1.1M nodes | 817.7 ms | 550.3 ms | 1.5× (memory 1.0 MiB at every size, unchanged) |

Against the naive "build the full tree, then filter" baseline at 0.4:

- **nested set → KeepAncestors → nested array: 1.9× (balanced-300k), 2.1× (wide-100k), 2.3× (category-100k) faster**,
  1.0× on the 10k deep chain. This meets the SPEC §10 "≥ 2× faster" target on the realistic shapes, where Phase 0
  predicted 2.5× – 2.7× for the prototype (which had no multi-root support, accessors or validation).
- nested set → Prune → nested array: 1.4× faster (85.6 vs 117.7 ms). The sink copying ~55 % of the rows is the floor.
- adjacency list in any order: KeepAncestors 1.1× – 1.3× faster, Prune 0.9× – 1.0× (the index build stays the cost).
- Memory is unchanged: the library's own peak is 3–6× lower than the naive code's.

The adjacency index (inline array reads, children resolved in a second pass in input order, the cycle check only
when a child comes before its parent) accounts for part of the adjacency gain. On shuffled input the cycle check
runs and the gain is smaller (ad-hoc, 300k rows: ~7 %). Its own memory is 35 MiB at 300k rows (30 MiB in v0.1.0:
the second pass keeps the parent ids of all rows while it runs).

The full tables further down are from v0.1.0; regenerate them with the commands above.

## Spec targets (SPEC §10)

| Target | Result | Verdict |
|---|---|---|
| Streaming memory independent of n | `iterate()` over a generator source: **mem_peak 1.0 MiB at 11k, 111k and 1.11M nodes** (nested set, pre-ordered adjacency, materialized path; Prune, KeepAncestors, Promote alike) | **holds** |
| ≥ 1M nodes/s, Prune over an array nested set | `filter()` → `count()`: **2.1M – 2.9M nodes/s** at ~90 % kept (wide – balanced), **7.9M – 8.2M** at ~10 % kept. Streaming from a generator, including generating the rows: 1.2M | **holds** (2–3× margin) |
| ≥ 2× faster than the naive "build full tree, then filter" | nested set → KeepAncestors → nested array: **0.6× – 1.07×** (balanced 169 vs 157 ms, category 57 vs 61 ms, wide 50 vs 40 ms, deep 8.2 vs 4.8 ms); adjacency: **0.53× – 0.74×**; Prune → nested array: **0.57× – 0.79×** (nested set), **0.5× – 0.9×** (adjacency) | **missed**: the library is about as fast as the naive code or slower |
| Lower memory than the naive baseline | own peak: KeepAncestors **5× – 9× lower** (52.6 vs 260 MiB, balanced), Prune **2.1× – 2.6× lower** (132 vs 339 MiB) | **holds** |

Only a generator pipeline reaches the 2× target (see (c)): KeepAncestors **2.5× – 2.7×** faster than naive,
Prune **1.3× – 1.65×**. Prune → nested array cannot reach 2× while ~55 % of the input is copied into the output:
there the sink (copying rows to add `children`) is the floor.

## Phase 0 answers

### (a) Field access in NestedSetCursor: AutoAccessor vs ArrayKeyAccessor vs inline

Raw cursor loop, balanced-300k / category-100k / wide-100k (`Phase0AccessorBench`):

| Variant (each step includes the previous) | balanced-300k | category-100k | wide-100k | own peak (300k) |
|---|---|---|---|---|
| library, AutoAccessor (default) | 92.1 ms | 32.6 ms | 33.0 ms | 8.0 MiB |
| library, ArrayKeyAccessor | 99.4 ms | 34.7 ms | 30.8 ms | 8.0 MiB |
| inline `$row[$key] ?? null` (still `Rows::int()`) | 78.4 ms (−15 %) | 26.4 ms (−19 %) | 25.2 ms (−24 %) | 8.0 MiB |
| + inline `is_int()` fast path before `Rows::int()` | 65.7 ms (−29 %) | 22.6 ms (−31 %) | 21.6 ms (−35 %) | 8.0 MiB |
| + read the list by position (no ArrayIterator) | **53.4 ms (−42 %)** | **18.7 ms (−43 %)** | **17.4 ms (−47 %)** | **110 KiB** |
| library, rows passed as a `yield from` generator | 92.0 ms (±0) | 32.9 ms | 30.2 ms | 120 KiB |

Conclusions:
- **AutoAccessor costs nothing over ArrayKeyAccessor** for arrays: the differences (−7 % to +8 %) are within
  noise in both directions. Keep AutoAccessor as the default.
- The **reader closure call** costs 15–24 %, the **`Rows::int()` static call** another ~15 %, and
  **ArrayIterator's three method calls per row** another ~15 %. Together: the source reads **1.7× – 1.9× faster**.
- **`Rows::iterator()` duplicates every array it wraps**: `new \ArrayIterator($rows)` copies the hash table
  when the array is shared (always, since the caller keeps it): +8 MiB and ~2 ms per `open()` at 300k rows. This
  hits every source that takes an array (nested set, materialized path, pre-ordered adjacency, depth list) and
  breaks "O(depth) memory, never copy" (§2). Time is unaffected within noise; memory is O(n).

### (b) Ancestors tracking in PruneCursor

Prune (~90 % visible) → raw loop (`Phase0AncestorsBench`):

| Variant | over library NestedSetSource (balanced / category / wide) | over list-indexed source |
|---|---|---|
| library PruneCursor (`enter()` per node, `Callback::matches()`) | 123.3 / 46.5 / 52.7 ms | 79.5 / 30.0 / 36.2 ms |
| without `Ancestors::enter()` | 104.5 / 41.0 / 48.9 ms (**−15 / −12 / −7 %**) | 70.6 / 27.4 / 32.4 ms (**−11 / −9 / −10 %**) |
| + closure called directly (no Callback wrapper) | 105.5 / 39.0 / 47.7 ms (±1 / −5 / −2 %) | 65.9 / 25.7 / 30.1 ms (**−7 / −6 / −7 %**) |

Conclusion: **tracking is measurable (7–15 % of Prune)**, so per §5.3 switch it on only for predicates that use
ancestors (a `NeedsAncestors` marker, or reflection once on closures with ≥ 3 parameters). Unwrapping `Callback`
is worth another 5–7 % once the source is fast; before that it is lost in noise.

### (c) Cursor classes vs generators

Nested set → Prune (~90 %), like-for-like: same inline reads, same validation, no ancestors, direct closure
(`Phase0GeneratorBench`):

| Variant | balanced-300k | category-100k | wide-100k | cost of a 2nd stage (300k) |
|---|---|---|---|---|
| library facade `filter()->count()` | 105.0 ms | 39.4 ms | 45.6 ms | +52.6 ms |
| cursor classes, ArrayIterator source | 77.2 ms | 30.3 ms | 35.3 ms | – |
| cursor classes, list-indexed source | 65.4 ms | 26.4 ms | 30.1 ms | +41.0 ms |
| **generators, skip via `send(true)`** | **39.4 ms** | **16.2 ms** | **18.3 ms** | **+10.8 ms** |
| generators, skip via shared flag object | 34.2 ms | 13.4 ms | 14.8 ms | +9.7 ms |

Whole pipelines into nested arrays (`Phase0PipelineBench`):

| Pipeline | KeepAncestors balanced / category / wide | Prune balanced / category / wide |
|---|---|---|
| naive build-then-filter | 143.8 / 60.1 / 38.1 ms | 131.8 / 56.0 / 44.1 ms |
| library | 175.9 / 56.6 / 49.0 ms | 170.9 / 71.1 / 77.4 ms |
| library + inlined NestedArraySink | 161.2 / 58.0 / 49.6 ms | 154.6 / 63.4 / 69.6 ms |
| all cursor optimisations (list source, fast strategy, inlined sink) | 107.4 / 35.8 / 28.4 ms | 109.2 / 46.3 / 49.1 ms |
| **generator pipeline** | **56.6 / 22.9 / 14.3 ms** | **79.8 / 34.4 / 34.3 ms** |

Conclusions:
- Like-for-like, **generators are 1.65× – 1.9× faster than cursor objects**, and an extra stage costs
  **~4× less** (11 ms vs 41 ms per 300k nodes). The cost is the cursor protocol itself: `next()` + `depth()` +
  `node()` per node per stage, several of them delegating (`PruneCursor::depth()` → `input->depth()`). The
  library pipeline makes ~19 calls per node; the generator pipeline ~3 resumptions.
- `send(true)` and the shared-flag variant are within ~15 % of each other. `send()` keeps the skip explicit and
  needs no shared mutable object, so it is the recommended form.
- **Only the generator representation meets "≥ 2× faster than naive"** (KeepAncestors 2.5× – 2.7×); the
  best cursor version reaches 1.3× – 1.7× (KeepAncestors) and 0.9× – 1.2× (Prune).
- Memory is identical: both are streaming, and the output dominates.

Recommendation for locking the internal representation: **generators internally, `Cursor` kept as the public
contract**. Built-in sources and strategies become generator functions (`yield $depth => $node`; `yield` returns
`true` when the consumer wants the subtree skipped). One adapter class implements `Cursor` over a generator
(`next()` = `$skip ? send(true) : next()`), and one wraps a user `Cursor` stage as a generator. That way user
extensions keep working and only built-ins get the fast path. The cost is readability: skip-by-`send()` is less
obvious than `skipSubtree()`, which needs a short section in `extending.md` and differential tests (as for every
strategy).

## Other findings

- **NestedArraySource is O(n · depth)** (`Phase0NestedArrayBench`): one ArrayIterator per open level. Every live
  ArrayIterator registers a HashTable iterator, and `zend_hash_iterator_add()` scans the global iterator table
  linearly. Deep chain: 0.52M nodes/s at depth 10k; ad-hoc scaling 0.8 → 1.3 → 2.1 → 3.6 → 6.7 µs/node at depth
  2.5k/5k/10k/20k/40k (267 ms for a 40k chain). A (list, position) stack is linear at 0.23 µs/node: deep-10k
  **19.2 → 2.4 ms (8×)**, and also −14 % to −22 % on balanced / wide / category.
- Sinks: `toNestedArray`, `toNestedSet`, `toAdjacencyList` run at 1.6M – 2.0M nodes/s when every node is output.
  Their own memory is the output (215 MiB for 300k rows with nine fields): each row is copied once to add the
  `children`, `lft`/`rgt` or `parent_id` field. Inlining `NestedArraySink::build()` gains only ~0–9 % (noisy).
- `iterate()` (Generator wrapper over the cursor) adds ~20 % over `count()` (106 vs 86 ms at 300k).
- `Annotate` is the slowest strategy (1.1M nodes/s): one `Annotated` object per node plus the queue.
  `ActiveTrail` reaches 1.4M – 1.8M. On wide-100k with a single active node near the end it buffers the root's
  100k direct children (own peak 8 MiB): this is the documented worst case (§5.1.1).
- `AdjacencyListSource` index build adds ~25 ms per 300k nodes over the pre-ordered source and 30 MiB at 300k
  (O(n), as documented). It is the main reason the adjacency pipelines lose to the naive code
  (`fromAdjacencyList` → KeepAncestors → nested array: 196 ms vs 132 ms naive at 300k).
- Not benchmarked: Gedmo `buildTree()` itself (needs a Doctrine repository). `Naive::buildFromNestedSet()` is a
  copy of Gedmo's `buildTreeArray()` algorithm and stands in for it.

## Optimisation proposals (ranked by measured gain)

| # | Where | Change | Measured gain |
|---|---|---|---|
| 1 | `src/Source/*`, `src/Strategy/*`, `src/Sink/*`, `Tree` | Generator-based internal pipeline behind the `Cursor` contract (see (c)) | pipelines 2.1× – 3.4× faster than today's library (KeepAncestors → nested array 175.9 → 56.6 ms; Prune → nested array 170.9 → 79.8 ms at 300k); each extra stage ~4× cheaper; meets the 2× naive target for KeepAncestors |
| 2 | `src/Source/NestedArrayCursor.php` | Stack of (list, position) for list children, Iterator only for other iterables (prototype `benchmarks/Prototype/IndexedNestedArrayCursor.php`) | deep-10k 8× faster; O(n · depth) → O(n); −14 % to −22 % on the other shapes; no children-array copies |
| 3 | `src/Internal/Rows.php` `iterator()` + array sources | Do not wrap arrays in ArrayIterator: read lists by position in the cursor (`ListNestedSetCursor`), or iterate them with `foreach` in a generator (comes free with #1) | removes an O(n) copy (+8 MiB / 300k rows, +2 MiB / 100k) and ~15 % of the nested set read time |
| 4 | `src/Source/NestedSetCursor.php` (same pattern in `MaterializedPathCursor`, `PreorderedAdjacencyCursor`) | `if (!\is_int($v)) { $v = Rows::int(...); }` before calling `Rows::int()` | ~15 % of source read time (78.4 → 65.7 ms raw at 300k); no behaviour change |
| 5 | `src/Source/NestedSetSource.php` | For `ArrayKeyAccessor`/`AutoAccessor` on arrays, read keys inline instead of through reader closures (keep closures for objects) | 15 – 24 % of source read time (92 → 78 ms raw at 300k); together with #3 and #4: −42 % to −47 % |
| 6 | `PruneCursor`, `KeepAncestorsCursor`, `PromoteCursor`, `AnnotateCursor`, `ActiveTrailCursor` | Call `Ancestors::enter()` only when the predicate needs ancestors (marker interface, or reflection once on the closure) | 7 – 15 % of Prune; same per-node call removed from every strategy |
| 7 | the same strategy cursors | Unwrap `Callback` and call the closure directly | 5 – 7 % of Prune once the source is fast (within noise before) |
| 8 | `PruneCursor`/`PromoteCursor` `node()`/`depth()` | Cache node/depth in properties instead of delegating to the input (part of `FastKeepAncestorsCursor`) | not isolated; part of the 1.5× of "all cursor optimisations" over the library in the pipeline bench |

\#2 – #7 are independent of #1 and each is above the 5 % bar. If #1 is adopted, #3, #6 and #7 carry over into the
generator versions, and #8 becomes moot.

## Full tables

After the optimisations from the proposals above were applied (items 2–8; item 1, the generator pipeline, is on
the roadmap). The Phase 0 numbers earlier in this file are from before them.

PHP 8.4.27, opcache on, JIT off. Times per revolution: median [min – max] over all iterations.

### SourceBench

| subject | set | time | nodes/s | mem_peak | own peak |
|---|---|---|---|---|---|
| NestedSet | balanced-37k | 9.3 ms [9.1 ms – 9.5 ms] | 4.04M [3.94M – 4.13M] | 30.5 MiB | 103 KiB |
| NestedSet | balanced-300k | 80.1 ms [77.8 ms – 82.8 ms] | 3.74M [3.62M – 3.85M] | 241.7 MiB | 103 KiB |
| NestedSet | wide-1k x100 | 25.3 ms [25.0 ms – 26.2 ms] | 3.96M [3.83M – 4.00M] | 1.7 MiB | 103 KiB |
| NestedSet | wide-100k | 26.4 ms [26.3 ms – 27.3 ms] | 3.78M [3.66M – 3.80M] | 76.9 MiB | 103 KiB |
| NestedSet | deep-10k | 2.3 ms [2.2 ms – 2.4 ms] | 4.33M [4.16M – 4.46M] | 8.4 MiB | 279 KiB |
| NestedSet | category-1k x100 | 26.7 ms [26.2 ms – 27.6 ms] | 3.75M [3.62M – 3.82M] | 1.7 MiB | 103 KiB |
| NestedSet | category-100k | 28.2 ms [28.0 ms – 28.4 ms] | 3.55M [3.52M – 3.57M] | 79.5 MiB | 103 KiB |
| AdjacencyList | balanced-37k | 14.5 ms [13.9 ms – 14.7 ms] | 2.58M [2.55M – 2.69M] | 34.1 MiB | 3.8 MiB |
| AdjacencyList | balanced-300k | 120.7 ms [119.5 ms – 125.3 ms] | 2.48M [2.39M – 2.51M] | 271.6 MiB | 30.0 MiB |
| AdjacencyList | wide-1k x100 | 32.1 ms [31.7 ms – 33.3 ms] | 3.12M [3.00M – 3.16M] | 1.7 MiB | 159 KiB |
| AdjacencyList | wide-100k | 37.8 ms [36.1 ms – 38.5 ms] | 2.65M [2.60M – 2.77M] | 86.6 MiB | 9.8 MiB |
| AdjacencyList | deep-10k | 4.5 ms [4.4 ms – 4.7 ms] | 2.24M [2.13M – 2.27M] | 11.2 MiB | 3.1 MiB |
| AdjacencyList | category-1k x100 | 40.0 ms [38.7 ms – 40.6 ms] | 2.50M [2.46M – 2.58M] | 1.8 MiB | 249 KiB |
| AdjacencyList | category-100k | 49.3 ms [48.1 ms – 51.0 ms] | 2.03M [1.96M – 2.08M] | 98.1 MiB | 18.8 MiB |
| PreorderedAdjacency | balanced-37k | 12.1 ms [11.6 ms – 12.6 ms] | 3.09M [2.98M – 3.23M] | 30.5 MiB | 103 KiB |
| PreorderedAdjacency | balanced-300k | 99.2 ms [96.3 ms – 103.1 ms] | 3.02M [2.91M – 3.11M] | 241.7 MiB | 103 KiB |
| PreorderedAdjacency | wide-1k x100 | 30.1 ms [28.7 ms – 31.4 ms] | 3.32M [3.19M – 3.49M] | 1.7 MiB | 103 KiB |
| PreorderedAdjacency | wide-100k | 32.0 ms [31.8 ms – 33.1 ms] | 3.12M [3.02M – 3.15M] | 76.9 MiB | 103 KiB |
| PreorderedAdjacency | deep-10k | 2.8 ms [2.7 ms – 2.8 ms] | 3.61M [3.52M – 3.67M] | 8.4 MiB | 279 KiB |
| PreorderedAdjacency | category-1k x100 | 31.8 ms [31.4 ms – 33.0 ms] | 3.15M [3.03M – 3.19M] | 1.7 MiB | 103 KiB |
| PreorderedAdjacency | category-100k | 34.0 ms [33.7 ms – 34.6 ms] | 2.94M [2.89M – 2.97M] | 79.5 MiB | 103 KiB |
| MaterializedPath | balanced-37k | 15.5 ms [15.3 ms – 15.6 ms] | 2.42M [2.40M – 2.45M] | 30.5 MiB | 103 KiB |
| MaterializedPath | balanced-300k | 142.3 ms [135.4 ms – 146.7 ms] | 2.11M [2.04M – 2.21M] | 241.7 MiB | 103 KiB |
| MaterializedPath | wide-1k x100 | 38.0 ms [36.7 ms – 39.5 ms] | 2.63M [2.53M – 2.73M] | 1.7 MiB | 103 KiB |
| MaterializedPath | wide-100k | 39.8 ms [38.9 ms – 40.0 ms] | 2.51M [2.50M – 2.57M] | 76.9 MiB | 103 KiB |
| MaterializedPath | category-1k x100 | 40.8 ms [39.4 ms – 42.7 ms] | 2.45M [2.34M – 2.54M] | 1.7 MiB | 103 KiB |
| MaterializedPath | category-100k | 42.5 ms [42.2 ms – 42.6 ms] | 2.35M [2.35M – 2.37M] | 79.5 MiB | 103 KiB |
| NestedArray | balanced-37k | 7.9 ms [7.7 ms – 8.3 ms] | 4.74M [4.50M – 4.83M] | 56.2 MiB | 64 KiB |
| NestedArray | balanced-300k | 62.1 ms [62.0 ms – 63.1 ms] | 4.82M [4.74M – 4.83M] | 448.2 MiB | 64 KiB |
| NestedArray | wide-1k x100 | 16.4 ms [15.9 ms – 16.7 ms] | 6.12M [5.98M – 6.31M] | 2.3 MiB | 64 KiB |
| NestedArray | wide-100k | 18.8 ms [18.6 ms – 19.0 ms] | 5.32M [5.26M – 5.39M] | 145.3 MiB | 64 KiB |
| NestedArray | deep-10k | 2.7 ms [2.6 ms – 2.8 ms] | 3.74M [3.51M – 3.82M] | 17.3 MiB | 538 KiB |
| NestedArray | category-1k x100 | 22.2 ms [22.0 ms – 22.3 ms] | 4.50M [4.49M – 4.54M] | 2.4 MiB | 64 KiB |
| NestedArray | category-100k | 29.7 ms [29.5 ms – 31.1 ms] | 3.37M [3.21M – 3.39M] | 154.3 MiB | 64 KiB |
| DepthList | balanced-37k | 6.1 ms [6.0 ms – 6.3 ms] | 6.12M [5.94M – 6.22M] | 39.2 MiB | 101 KiB |
| DepthList | balanced-300k | 48.3 ms [48.1 ms – 51.5 ms] | 6.20M [5.82M – 6.23M] | 311.4 MiB | 101 KiB |
| DepthList | wide-1k x100 | 15.2 ms [15.0 ms – 15.7 ms] | 6.57M [6.36M – 6.68M] | 1.9 MiB | 101 KiB |
| DepthList | wide-100k | 18.2 ms [17.6 ms – 18.6 ms] | 5.49M [5.39M – 5.68M] | 99.5 MiB | 101 KiB |
| DepthList | deep-10k | 1.7 ms [1.6 ms – 1.7 ms] | 6.03M [5.90M – 6.42M] | 10.5 MiB | 101 KiB |
| DepthList | category-1k x100 | 15.4 ms [15.1 ms – 16.2 ms] | 6.48M [6.17M – 6.63M] | 1.9 MiB | 101 KiB |
| DepthList | category-100k | 16.5 ms [16.5 ms – 17.6 ms] | 6.04M [5.68M – 6.08M] | 102.1 MiB | 101 KiB |

### Phase0PipelineBench

| subject | set | time | nodes/s | mem_peak | own peak |
|---|---|---|---|---|---|
| NaiveKeepAncestors | balanced-300k | 141.7 ms [138.8 ms – 143.3 ms] | 2.11M [2.09M – 2.16M] | 501.8 MiB | 260.3 MiB |
| NaiveKeepAncestors | category-100k | 56.2 ms [55.7 ms – 57.7 ms] | 1.78M [1.73M – 1.80M] | 175.0 MiB | 95.7 MiB |
| NaiveKeepAncestors | wide-100k | 38.4 ms [36.8 ms – 38.8 ms] | 2.60M [2.58M – 2.72M] | 155.1 MiB | 78.3 MiB |
| LibraryKeepAncestors | balanced-300k | 121.9 ms [116.9 ms – 126.7 ms] | 2.46M [2.37M – 2.56M] | 286.1 MiB | 44.6 MiB |
| LibraryKeepAncestors | category-100k | 43.8 ms [42.5 ms – 45.9 ms] | 2.28M [2.18M – 2.35M] | 97.1 MiB | 17.7 MiB |
| LibraryKeepAncestors | wide-100k | 34.0 ms [33.3 ms – 35.4 ms] | 2.94M [2.82M – 3.00M] | 83.7 MiB | 6.9 MiB |
| LibraryFastSinkKeepAncestors | balanced-300k | 117.3 ms [114.8 ms – 122.5 ms] | 2.55M [2.45M – 2.61M] | 286.1 MiB | 44.6 MiB |
| LibraryFastSinkKeepAncestors | category-100k | 43.6 ms [42.9 ms – 44.3 ms] | 2.30M [2.26M – 2.33M] | 97.1 MiB | 17.7 MiB |
| LibraryFastSinkKeepAncestors | wide-100k | 36.7 ms [36.2 ms – 37.5 ms] | 2.72M [2.67M – 2.76M] | 83.7 MiB | 6.9 MiB |
| CursorFastKeepAncestors | balanced-300k | 101.7 ms [96.8 ms – 106.2 ms] | 2.95M [2.82M – 3.09M] | 286.1 MiB | 44.6 MiB |
| CursorFastKeepAncestors | category-100k | 34.3 ms [33.9 ms – 36.4 ms] | 2.91M [2.75M – 2.95M] | 97.0 MiB | 17.7 MiB |
| CursorFastKeepAncestors | wide-100k | 28.5 ms [27.2 ms – 28.8 ms] | 3.51M [3.47M – 3.67M] | 83.7 MiB | 6.9 MiB |
| GeneratorKeepAncestors | balanced-300k | 56.3 ms [54.4 ms – 58.7 ms] | 5.32M [5.10M – 5.51M] | 286.1 MiB | 44.6 MiB |
| GeneratorKeepAncestors | category-100k | 21.0 ms [20.1 ms – 21.5 ms] | 4.77M [4.66M – 4.97M] | 97.0 MiB | 17.7 MiB |
| GeneratorKeepAncestors | wide-100k | 13.9 ms [13.8 ms – 14.5 ms] | 7.19M [6.89M – 7.26M] | 83.7 MiB | 6.9 MiB |
| NaivePrune | balanced-300k | 124.8 ms [123.5 ms – 128.2 ms] | 2.40M [2.34M – 2.43M] | 580.9 MiB | 339.4 MiB |
| NaivePrune | category-100k | 54.8 ms [53.9 ms – 57.6 ms] | 1.82M [1.73M – 1.86M] | 211.4 MiB | 132.1 MiB |
| NaivePrune | wide-100k | 40.3 ms [40.1 ms – 41.2 ms] | 2.48M [2.43M – 2.49M] | 210.0 MiB | 133.2 MiB |
| LibraryPrune | balanced-300k | 119.0 ms [115.9 ms – 125.8 ms] | 2.52M [2.38M – 2.58M] | 365.3 MiB | 123.7 MiB |
| LibraryPrune | category-100k | 48.8 ms [48.5 ms – 51.3 ms] | 2.05M [1.95M – 2.06M] | 133.6 MiB | 54.2 MiB |
| LibraryPrune | wide-100k | 54.9 ms [52.9 ms – 57.5 ms] | 1.82M [1.74M – 1.89M] | 138.6 MiB | 61.8 MiB |
| LibraryFastSinkPrune | balanced-300k | 115.5 ms [113.8 ms – 120.0 ms] | 2.59M [2.50M – 2.63M] | 365.3 MiB | 123.7 MiB |
| LibraryFastSinkPrune | category-100k | 49.5 ms [47.9 ms – 51.5 ms] | 2.02M [1.94M – 2.09M] | 133.6 MiB | 54.2 MiB |
| LibraryFastSinkPrune | wide-100k | 54.6 ms [53.3 ms – 56.2 ms] | 1.83M [1.78M – 1.88M] | 138.6 MiB | 61.8 MiB |
| CursorFastPrune | balanced-300k | 108.6 ms [107.3 ms – 115.7 ms] | 2.76M [2.59M – 2.79M] | 365.2 MiB | 123.7 MiB |
| CursorFastPrune | category-100k | 44.5 ms [44.4 ms – 46.3 ms] | 2.25M [2.16M – 2.25M] | 133.5 MiB | 54.2 MiB |
| CursorFastPrune | wide-100k | 48.8 ms [47.3 ms – 50.3 ms] | 2.05M [1.99M – 2.12M] | 138.5 MiB | 61.8 MiB |
| GeneratorPrune | balanced-300k | 72.0 ms [71.1 ms – 74.2 ms] | 4.16M [4.04M – 4.21M] | 365.2 MiB | 123.7 MiB |
| GeneratorPrune | category-100k | 32.1 ms [31.4 ms – 33.3 ms] | 3.12M [3.00M – 3.19M] | 133.5 MiB | 54.2 MiB |
| GeneratorPrune | wide-100k | 32.8 ms [31.3 ms – 33.7 ms] | 3.04M [2.96M – 3.20M] | 138.5 MiB | 61.8 MiB |

### Phase0GeneratorBench

| subject | set | time | nodes/s | mem_peak | own peak |
|---|---|---|---|---|---|
| Library | balanced-300k | 77.3 ms [76.0 ms – 80.3 ms] | 3.88M [3.73M – 3.94M] | 241.7 MiB | 120 KiB |
| Library | category-100k | 29.1 ms [28.6 ms – 30.6 ms] | 3.44M [3.27M – 3.50M] | 79.5 MiB | 120 KiB |
| Library | wide-100k | 33.2 ms [32.8 ms – 34.6 ms] | 3.01M [2.89M – 3.05M] | 77.0 MiB | 120 KiB |
| LibraryX2 | balanced-300k | 105.7 ms [104.4 ms – 108.2 ms] | 2.83M [2.77M – 2.87M] | 241.7 MiB | 120 KiB |
| LibraryX2 | category-100k | 41.1 ms [39.3 ms – 42.3 ms] | 2.43M [2.36M – 2.55M] | 79.5 MiB | 120 KiB |
| LibraryX2 | wide-100k | 47.8 ms [46.8 ms – 50.0 ms] | 2.09M [2.00M – 2.14M] | 77.0 MiB | 120 KiB |
| CursorIterator | balanced-300k | 77.8 ms [75.3 ms – 79.8 ms] | 3.85M [3.75M – 3.98M] | 249.6 MiB | 8.1 MiB |
| CursorIterator | category-100k | 29.6 ms [28.3 ms – 30.0 ms] | 3.38M [3.34M – 3.53M] | 81.4 MiB | 2.1 MiB |
| CursorIterator | wide-100k | 34.9 ms [34.7 ms – 35.3 ms] | 2.86M [2.83M – 2.88M] | 78.9 MiB | 2.1 MiB |
| CursorList | balanced-300k | 63.9 ms [63.2 ms – 66.0 ms] | 4.69M [4.54M – 4.74M] | 241.6 MiB | 109 KiB |
| CursorList | category-100k | 25.0 ms [24.2 ms – 26.3 ms] | 3.99M [3.81M – 4.14M] | 79.4 MiB | 109 KiB |
| CursorList | wide-100k | 30.2 ms [29.1 ms – 31.7 ms] | 3.31M [3.15M – 3.44M] | 76.9 MiB | 109 KiB |
| CursorListX2 | balanced-300k | 102.7 ms [101.8 ms – 104.1 ms] | 2.92M [2.88M – 2.94M] | 241.6 MiB | 109 KiB |
| CursorListX2 | category-100k | 42.2 ms [40.5 ms – 43.2 ms] | 2.37M [2.32M – 2.47M] | 79.4 MiB | 109 KiB |
| CursorListX2 | wide-100k | 48.7 ms [48.4 ms – 51.3 ms] | 2.05M [1.95M – 2.07M] | 76.9 MiB | 109 KiB |
| GeneratorSend | balanced-300k | 37.7 ms [36.3 ms – 38.9 ms] | 7.94M [7.70M – 8.24M] | 241.7 MiB | 178 KiB |
| GeneratorSend | category-100k | 14.7 ms [14.6 ms – 15.6 ms] | 6.80M [6.42M – 6.84M] | 79.5 MiB | 178 KiB |
| GeneratorSend | wide-100k | 17.6 ms [17.5 ms – 18.4 ms] | 5.67M [5.44M – 5.72M] | 76.9 MiB | 178 KiB |
| GeneratorSendX2 | balanced-300k | 51.0 ms [49.1 ms – 52.3 ms] | 5.88M [5.73M – 6.10M] | 241.7 MiB | 178 KiB |
| GeneratorSendX2 | category-100k | 20.3 ms [19.8 ms – 21.4 ms] | 4.92M [4.68M – 5.05M] | 79.5 MiB | 178 KiB |
| GeneratorSendX2 | wide-100k | 25.0 ms [24.0 ms – 25.8 ms] | 4.00M [3.88M – 4.17M] | 76.9 MiB | 178 KiB |
| GeneratorFlag | balanced-300k | 33.6 ms [33.0 ms – 36.1 ms] | 8.91M [8.29M – 9.08M] | 241.7 MiB | 178 KiB |
| GeneratorFlag | category-100k | 14.2 ms [13.4 ms – 14.6 ms] | 7.06M [6.84M – 7.44M] | 79.5 MiB | 178 KiB |
| GeneratorFlag | wide-100k | 14.8 ms [14.5 ms – 15.6 ms] | 6.76M [6.43M – 6.89M] | 76.9 MiB | 178 KiB |
| GeneratorFlagX2 | balanced-300k | 42.9 ms [42.2 ms – 45.5 ms] | 6.98M [6.58M – 7.10M] | 241.7 MiB | 178 KiB |
| GeneratorFlagX2 | category-100k | 17.2 ms [16.6 ms – 18.2 ms] | 5.82M [5.48M – 6.01M] | 79.5 MiB | 178 KiB |
| GeneratorFlagX2 | wide-100k | 20.9 ms [20.4 ms – 21.9 ms] | 4.78M [4.56M – 4.90M] | 76.9 MiB | 178 KiB |

### Phase0NestedArrayBench

| subject | set | time | nodes/s | mem_peak | own peak |
|---|---|---|---|---|---|
| Library | balanced-300k | 54.4 ms [54.0 ms – 55.6 ms] | 5.51M [5.39M – 5.54M] | 448.2 MiB | 106 KiB |
| Library | wide-100k | 14.9 ms [14.4 ms – 15.4 ms] | 6.70M [6.51M – 6.94M] | 145.3 MiB | 106 KiB |
| Library | deep-10k | 2.5 ms [2.4 ms – 2.6 ms] | 4.02M [3.84M – 4.19M] | 17.3 MiB | 537 KiB |
| Library | category-100k | 28.4 ms [26.9 ms – 28.8 ms] | 3.53M [3.48M – 3.72M] | 154.3 MiB | 106 KiB |
| Indexed | balanced-300k | 54.3 ms [53.5 ms – 57.5 ms] | 5.52M [5.21M – 5.60M] | 448.2 MiB | 106 KiB |
| Indexed | wide-100k | 15.2 ms [14.3 ms – 15.4 ms] | 6.59M [6.49M – 6.99M] | 145.3 MiB | 106 KiB |
| Indexed | deep-10k | 2.3 ms [2.2 ms – 2.4 ms] | 4.35M [4.16M – 4.53M] | 17.3 MiB | 537 KiB |
| Indexed | category-100k | 27.3 ms [26.5 ms – 27.7 ms] | 3.66M [3.61M – 3.78M] | 154.3 MiB | 106 KiB |

### Phase0AccessorBench

| subject | set | time | nodes/s | mem_peak | own peak |
|---|---|---|---|---|---|
| AutoAccessor | balanced-300k | 67.9 ms [66.9 ms – 71.4 ms] | 4.41M [4.19M – 4.48M] | 241.6 MiB | 119 KiB |
| AutoAccessor | category-100k | 23.6 ms [22.9 ms – 23.8 ms] | 4.23M [4.20M – 4.37M] | 79.4 MiB | 119 KiB |
| AutoAccessor | wide-100k | 24.2 ms [23.7 ms – 24.9 ms] | 4.13M [4.02M – 4.22M] | 76.9 MiB | 119 KiB |
| AutoAccessorGeneratorRows | balanced-300k | 76.4 ms [75.5 ms – 80.3 ms] | 3.92M [3.73M – 3.97M] | 241.6 MiB | 119 KiB |
| AutoAccessorGeneratorRows | category-100k | 27.3 ms [26.6 ms – 27.9 ms] | 3.66M [3.58M – 3.76M] | 79.4 MiB | 119 KiB |
| AutoAccessorGeneratorRows | wide-100k | 25.6 ms [24.6 ms – 26.0 ms] | 3.90M [3.85M – 4.07M] | 76.9 MiB | 119 KiB |
| ArrayKeyAccessor | balanced-300k | 67.0 ms [65.5 ms – 68.7 ms] | 4.47M [4.36M – 4.58M] | 241.6 MiB | 119 KiB |
| ArrayKeyAccessor | category-100k | 24.5 ms [23.3 ms – 25.2 ms] | 4.09M [3.97M – 4.29M] | 79.4 MiB | 119 KiB |
| ArrayKeyAccessor | wide-100k | 21.8 ms [21.5 ms – 23.0 ms] | 4.58M [4.34M – 4.65M] | 76.9 MiB | 119 KiB |
| InlineKey | balanced-300k | 74.8 ms [74.1 ms – 78.0 ms] | 4.01M [3.84M – 4.04M] | 249.6 MiB | 8.1 MiB |
| InlineKey | category-100k | 25.6 ms [25.3 ms – 26.5 ms] | 3.90M [3.77M – 3.96M] | 81.4 MiB | 2.1 MiB |
| InlineKey | wide-100k | 24.1 ms [23.9 ms – 25.7 ms] | 4.15M [3.89M – 4.18M] | 78.9 MiB | 2.1 MiB |
| InlineKeyInt | balanced-300k | 65.4 ms [62.5 ms – 67.1 ms] | 4.58M [4.47M – 4.79M] | 249.5 MiB | 8.0 MiB |
| InlineKeyInt | category-100k | 22.4 ms [21.4 ms – 22.9 ms] | 4.46M [4.36M – 4.68M] | 81.3 MiB | 2.0 MiB |
| InlineKeyInt | wide-100k | 20.9 ms [20.4 ms – 21.8 ms] | 4.79M [4.58M – 4.90M] | 78.8 MiB | 2.0 MiB |
| InlineKeyIntList | balanced-300k | 52.8 ms [50.8 ms – 54.2 ms] | 5.67M [5.53M – 5.90M] | 241.6 MiB | 110 KiB |
| InlineKeyIntList | category-100k | 19.2 ms [19.0 ms – 19.9 ms] | 5.21M [5.04M – 5.25M] | 79.4 MiB | 110 KiB |
| InlineKeyIntList | wide-100k | 17.5 ms [16.7 ms – 18.0 ms] | 5.72M [5.55M – 6.00M] | 76.9 MiB | 110 KiB |

### SinkBench

| subject | set | time | nodes/s | mem_peak | own peak |
|---|---|---|---|---|---|
| Count | balanced-37k | 8.0 ms [7.6 ms – 8.2 ms] | 4.67M [4.55M – 4.91M] | 30.5 MiB | 103 KiB |
| Count | balanced-300k | 60.5 ms [59.3 ms – 61.3 ms] | 4.95M [4.89M – 5.05M] | 241.7 MiB | 103 KiB |
| Count | wide-1k x100 | 18.4 ms [17.9 ms – 19.4 ms] | 5.43M [5.16M – 5.58M] | 1.7 MiB | 103 KiB |
| Count | wide-100k | 19.6 ms [19.4 ms – 19.9 ms] | 5.09M [5.02M – 5.14M] | 76.9 MiB | 103 KiB |
| Count | deep-10k | 2.0 ms [1.9 ms – 2.1 ms] | 4.91M [4.81M – 5.20M] | 8.4 MiB | 278 KiB |
| Count | category-1k x100 | 19.8 ms [19.4 ms – 20.3 ms] | 5.05M [4.93M – 5.16M] | 1.7 MiB | 103 KiB |
| Count | category-100k | 23.8 ms [23.3 ms – 24.3 ms] | 4.20M [4.12M – 4.29M] | 79.5 MiB | 103 KiB |
| Iterate | balanced-37k | 10.2 ms [9.9 ms – 10.4 ms] | 3.67M [3.59M – 3.78M] | 30.5 MiB | 103 KiB |
| Iterate | balanced-300k | 82.6 ms [81.3 ms – 83.0 ms] | 3.63M [3.61M – 3.69M] | 241.7 MiB | 103 KiB |
| Iterate | wide-1k x100 | 24.0 ms [23.4 ms – 24.5 ms] | 4.16M [4.08M – 4.28M] | 1.7 MiB | 103 KiB |
| Iterate | wide-100k | 24.5 ms [24.1 ms – 25.6 ms] | 4.08M [3.91M – 4.14M] | 76.9 MiB | 103 KiB |
| Iterate | deep-10k | 2.2 ms [2.2 ms – 2.3 ms] | 4.48M [4.34M – 4.54M] | 8.4 MiB | 279 KiB |
| Iterate | category-1k x100 | 26.3 ms [25.9 ms – 26.8 ms] | 3.80M [3.73M – 3.86M] | 1.7 MiB | 103 KiB |
| Iterate | category-100k | 25.8 ms [25.6 ms – 27.6 ms] | 3.88M [3.62M – 3.90M] | 79.5 MiB | 103 KiB |
| ToNestedArray | balanced-37k | 16.4 ms [16.0 ms – 17.4 ms] | 2.29M [2.16M – 2.34M] | 56.2 MiB | 25.8 MiB |
| ToNestedArray | balanced-300k | 146.6 ms [144.3 ms – 148.5 ms] | 2.04M [2.02M – 2.08M] | 448.2 MiB | 206.6 MiB |
| ToNestedArray | wide-1k x100 | 38.3 ms [37.5 ms – 39.0 ms] | 2.61M [2.57M – 2.67M] | 2.3 MiB | 719 KiB |
| ToNestedArray | wide-100k | 45.0 ms [44.4 ms – 46.5 ms] | 2.22M [2.15M – 2.25M] | 145.2 MiB | 68.4 MiB |
| ToNestedArray | deep-10k | 4.1 ms [4.0 ms – 4.2 ms] | 2.41M [2.37M – 2.50M] | 17.6 MiB | 9.5 MiB |
| ToNestedArray | category-1k x100 | 42.0 ms [41.3 ms – 42.3 ms] | 2.38M [2.36M – 2.42M] | 2.4 MiB | 788 KiB |
| ToNestedArray | category-100k | 50.1 ms [49.6 ms – 52.8 ms] | 2.00M [1.89M – 2.02M] | 154.2 MiB | 74.9 MiB |
| ToNestedSet | balanced-37k | 17.0 ms [16.6 ms – 18.1 ms] | 2.20M [2.07M – 2.25M] | 60.2 MiB | 29.9 MiB |
| ToNestedSet | balanced-300k | 157.5 ms [149.7 ms – 160.1 ms] | 1.90M [1.87M – 2.00M] | 480.5 MiB | 238.9 MiB |
| ToNestedSet | wide-1k x100 | 39.0 ms [37.6 ms – 40.2 ms] | 2.56M [2.49M – 2.66M] | 2.4 MiB | 799 KiB |
| ToNestedSet | wide-100k | 48.5 ms [48.0 ms – 51.2 ms] | 2.06M [1.95M – 2.08M] | 153.2 MiB | 76.4 MiB |
| ToNestedSet | deep-10k | 4.1 ms [3.9 ms – 4.1 ms] | 2.47M [2.43M – 2.57M] | 16.5 MiB | 8.4 MiB |
| ToNestedSet | category-1k x100 | 42.3 ms [40.5 ms – 43.7 ms] | 2.36M [2.29M – 2.47M] | 2.4 MiB | 799 KiB |
| ToNestedSet | category-100k | 52.2 ms [49.6 ms – 53.5 ms] | 1.92M [1.87M – 2.02M] | 155.8 MiB | 76.4 MiB |
| ToAdjacencyList | balanced-37k | 14.7 ms [14.4 ms – 15.4 ms] | 2.55M [2.43M – 2.60M] | 56.2 MiB | 25.9 MiB |
| ToAdjacencyList | balanced-300k | 132.6 ms [128.7 ms – 134.9 ms] | 2.26M [2.22M – 2.33M] | 448.4 MiB | 206.9 MiB |
| ToAdjacencyList | wide-1k x100 | 36.4 ms [36.0 ms – 37.1 ms] | 2.75M [2.70M – 2.78M] | 2.3 MiB | 720 KiB |
| ToAdjacencyList | wide-100k | 41.6 ms [41.3 ms – 43.0 ms] | 2.40M [2.32M – 2.42M] | 145.2 MiB | 68.4 MiB |
| ToAdjacencyList | deep-10k | 3.3 ms [3.2 ms – 3.4 ms] | 3.03M [2.97M – 3.10M] | 15.5 MiB | 7.4 MiB |
| ToAdjacencyList | category-1k x100 | 36.6 ms [36.0 ms – 37.3 ms] | 2.73M [2.68M – 2.78M] | 2.3 MiB | 719 KiB |
| ToAdjacencyList | category-100k | 44.3 ms [43.8 ms – 45.4 ms] | 2.26M [2.20M – 2.28M] | 147.8 MiB | 68.4 MiB |
| ToFlatList | balanced-37k | 10.6 ms [10.5 ms – 10.8 ms] | 3.54M [3.47M – 3.57M] | 44.8 MiB | 14.5 MiB |
| ToFlatList | balanced-300k | 93.9 ms [89.1 ms – 96.1 ms] | 3.19M [3.12M – 3.36M] | 357.0 MiB | 115.5 MiB |
| ToFlatList | wide-1k x100 | 24.3 ms [24.2 ms – 25.1 ms] | 4.12M [3.99M – 4.14M] | 2.0 MiB | 406 KiB |
| ToFlatList | wide-100k | 29.6 ms [29.1 ms – 31.3 ms] | 3.38M [3.20M – 3.44M] | 114.7 MiB | 37.9 MiB |
| ToFlatList | deep-10k | 2.5 ms [2.4 ms – 2.6 ms] | 3.98M [3.87M – 4.12M] | 12.2 MiB | 4.1 MiB |
| ToFlatList | category-1k x100 | 28.1 ms [27.2 ms – 28.5 ms] | 3.56M [3.51M – 3.68M] | 2.0 MiB | 406 KiB |
| ToFlatList | category-100k | 32.0 ms [30.3 ms – 33.0 ms] | 3.13M [3.03M – 3.30M] | 117.2 MiB | 37.9 MiB |
| KeepAncestorsToNestedArray | balanced-37k | 15.9 ms [15.9 ms – 16.1 ms] | 2.35M [2.33M – 2.36M] | 35.9 MiB | 5.6 MiB |
| KeepAncestorsToNestedArray | balanced-300k | 124.6 ms [122.2 ms – 130.2 ms] | 2.40M [2.30M – 2.45M] | 286.1 MiB | 44.6 MiB |
| KeepAncestorsToNestedArray | wide-1k x100 | 35.3 ms [35.2 ms – 36.4 ms] | 2.84M [2.75M – 2.85M] | 1.7 MiB | 120 KiB |
| KeepAncestorsToNestedArray | wide-100k | 36.4 ms [34.7 ms – 38.1 ms] | 2.75M [2.62M – 2.88M] | 83.7 MiB | 6.9 MiB |
| KeepAncestorsToNestedArray | deep-10k | 6.3 ms [6.1 ms – 6.7 ms] | 1.59M [1.50M – 1.64M] | 17.8 MiB | 9.7 MiB |
| KeepAncestorsToNestedArray | category-1k x100 | 38.6 ms [37.8 ms – 38.7 ms] | 2.59M [2.58M – 2.65M] | 1.8 MiB | 208 KiB |
| KeepAncestorsToNestedArray | category-100k | 43.6 ms [42.9 ms – 45.6 ms] | 2.29M [2.19M – 2.33M] | 97.1 MiB | 17.7 MiB |

### Phase0AncestorsBench

| subject | set | time | nodes/s | mem_peak | own peak |
|---|---|---|---|---|---|
| LibraryPrune | balanced-300k | 88.6 ms [86.2 ms – 89.5 ms] | 3.38M [3.35M – 3.48M] | 241.7 MiB | 98 KiB |
| LibraryPrune | category-100k | 31.2 ms [30.8 ms – 33.0 ms] | 3.21M [3.03M – 3.25M] | 79.5 MiB | 98 KiB |
| LibraryPrune | wide-100k | 36.2 ms [34.9 ms – 37.8 ms] | 2.77M [2.64M – 2.87M] | 76.9 MiB | 98 KiB |
| NoAncestors | balanced-300k | 86.5 ms [83.9 ms – 90.7 ms] | 3.46M [3.30M – 3.57M] | 241.7 MiB | 98 KiB |
| NoAncestors | category-100k | 34.4 ms [33.9 ms – 35.3 ms] | 2.91M [2.83M – 2.95M] | 79.5 MiB | 98 KiB |
| NoAncestors | wide-100k | 40.1 ms [38.1 ms – 41.7 ms] | 2.49M [2.40M – 2.63M] | 76.9 MiB | 98 KiB |
| NoAncestorsDirectClosure | balanced-300k | 83.6 ms [81.1 ms – 87.8 ms] | 3.58M [3.41M – 3.69M] | 241.6 MiB | 54 KiB |
| NoAncestorsDirectClosure | category-100k | 31.3 ms [30.2 ms – 31.7 ms] | 3.19M [3.15M – 3.31M] | 79.4 MiB | 54 KiB |
| NoAncestorsDirectClosure | wide-100k | 36.0 ms [34.6 ms – 37.2 ms] | 2.78M [2.69M – 2.89M] | 76.9 MiB | 54 KiB |
| ListSourceLibraryPrune | balanced-300k | 69.6 ms [66.1 ms – 72.1 ms] | 4.30M [4.16M – 4.53M] | 241.6 MiB | 46 KiB |
| ListSourceLibraryPrune | category-100k | 24.8 ms [24.2 ms – 25.5 ms] | 4.04M [3.92M – 4.13M] | 79.4 MiB | 46 KiB |
| ListSourceLibraryPrune | wide-100k | 29.0 ms [28.3 ms – 30.0 ms] | 3.45M [3.33M – 3.54M] | 76.9 MiB | 46 KiB |
| ListSourceNoAncestors | balanced-300k | 73.0 ms [69.9 ms – 76.1 ms] | 4.11M [3.94M – 4.28M] | 241.6 MiB | 109 KiB |
| ListSourceNoAncestors | category-100k | 29.3 ms [28.6 ms – 30.0 ms] | 3.42M [3.34M – 3.50M] | 79.4 MiB | 109 KiB |
| ListSourceNoAncestors | wide-100k | 33.9 ms [32.1 ms – 34.6 ms] | 2.95M [2.89M – 3.12M] | 76.9 MiB | 109 KiB |
| ListSourceNoAncestorsDirectClosure | balanced-300k | 69.1 ms [67.2 ms – 71.4 ms] | 4.33M [4.20M – 4.46M] | 241.6 MiB | 109 KiB |
| ListSourceNoAncestorsDirectClosure | category-100k | 26.1 ms [25.2 ms – 27.2 ms] | 3.83M [3.67M – 3.96M] | 79.4 MiB | 109 KiB |
| ListSourceNoAncestorsDirectClosure | wide-100k | 29.9 ms [29.6 ms – 31.5 ms] | 3.34M [3.17M – 3.38M] | 76.9 MiB | 109 KiB |

### NaiveBaselineBench

| subject | set | time | nodes/s | mem_peak | own peak |
|---|---|---|---|---|---|
| NaiveAdjacencyKeepAncestors | balanced-37k | 14.8 ms [14.6 ms – 15.1 ms] | 2.53M [2.48M – 2.57M] | 61.7 MiB | 31.4 MiB |
| NaiveAdjacencyKeepAncestors | balanced-300k | 127.9 ms [125.6 ms – 129.4 ms] | 2.34M [2.32M – 2.38M] | 492.6 MiB | 251.1 MiB |
| NaiveAdjacencyKeepAncestors | wide-1k x100 | 20.8 ms [20.7 ms – 21.4 ms] | 4.81M [4.67M – 4.85M] | 2.3 MiB | 791 KiB |
| NaiveAdjacencyKeepAncestors | wide-100k | 31.7 ms [31.4 ms – 33.0 ms] | 3.16M [3.03M – 3.18M] | 152.1 MiB | 75.3 MiB |
| NaiveAdjacencyKeepAncestors | deep-10k | 5.1 ms [5.0 ms – 5.3 ms] | 1.95M [1.90M – 2.01M] | 25.4 MiB | 17.4 MiB |
| NaiveAdjacencyKeepAncestors | category-1k x100 | 29.2 ms [28.0 ms – 29.6 ms] | 3.42M [3.37M – 3.57M] | 2.5 MiB | 973 KiB |
| NaiveAdjacencyKeepAncestors | category-100k | 54.1 ms [53.9 ms – 55.4 ms] | 1.85M [1.81M – 1.85M] | 171.9 MiB | 92.6 MiB |
| LibraryAdjacencyKeepAncestors | balanced-37k | 18.3 ms [18.2 ms – 18.6 ms] | 2.05M [2.02M – 2.05M] | 38.2 MiB | 7.9 MiB |
| LibraryAdjacencyKeepAncestors | balanced-300k | 170.0 ms [164.8 ms – 179.2 ms] | 1.76M [1.67M – 1.82M] | 304.3 MiB | 62.8 MiB |
| LibraryAdjacencyKeepAncestors | wide-1k x100 | 36.3 ms [35.8 ms – 37.0 ms] | 2.76M [2.71M – 2.80M] | 1.7 MiB | 159 KiB |
| LibraryAdjacencyKeepAncestors | wide-100k | 45.6 ms [44.3 ms – 46.2 ms] | 2.19M [2.16M – 2.26M] | 87.8 MiB | 10.9 MiB |
| LibraryAdjacencyKeepAncestors | deep-10k | 8.5 ms [8.4 ms – 8.8 ms] | 1.17M [1.13M – 1.18M] | 20.6 MiB | 12.5 MiB |
| LibraryAdjacencyKeepAncestors | category-1k x100 | 54.6 ms [53.0 ms – 55.5 ms] | 1.83M [1.80M – 1.89M] | 1.9 MiB | 336 KiB |
| LibraryAdjacencyKeepAncestors | category-100k | 65.2 ms [62.0 ms – 68.1 ms] | 1.53M [1.47M – 1.61M] | 110.1 MiB | 30.7 MiB |
| NaiveNestedSetKeepAncestors | balanced-37k | 16.4 ms [16.3 ms – 16.6 ms] | 2.29M [2.26M – 2.30M] | 62.8 MiB | 32.5 MiB |
| NaiveNestedSetKeepAncestors | balanced-300k | 143.7 ms [141.4 ms – 152.3 ms] | 2.09M [1.97M – 2.12M] | 501.8 MiB | 260.3 MiB |
| NaiveNestedSetKeepAncestors | wide-1k x100 | 26.6 ms [26.2 ms – 27.1 ms] | 3.76M [3.69M – 3.82M] | 2.3 MiB | 822 KiB |
| NaiveNestedSetKeepAncestors | wide-100k | 37.2 ms [36.4 ms – 38.2 ms] | 2.69M [2.62M – 2.75M] | 155.1 MiB | 78.3 MiB |
| NaiveNestedSetKeepAncestors | deep-10k | 4.4 ms [4.3 ms – 4.6 ms] | 2.25M [2.17M – 2.33M] | 25.7 MiB | 17.7 MiB |
| NaiveNestedSetKeepAncestors | category-1k x100 | 32.8 ms [32.3 ms – 34.6 ms] | 3.05M [2.89M – 3.10M] | 2.5 MiB | 1004 KiB |
| NaiveNestedSetKeepAncestors | category-100k | 60.3 ms [58.6 ms – 62.6 ms] | 1.66M [1.60M – 1.71M] | 175.0 MiB | 95.7 MiB |
| LibraryNestedSetKeepAncestors | balanced-37k | 15.1 ms [14.8 ms – 16.0 ms] | 2.48M [2.35M – 2.54M] | 35.9 MiB | 5.6 MiB |
| LibraryNestedSetKeepAncestors | balanced-300k | 121.7 ms [117.9 ms – 123.4 ms] | 2.46M [2.43M – 2.54M] | 286.1 MiB | 44.6 MiB |
| LibraryNestedSetKeepAncestors | wide-1k x100 | 32.9 ms [32.0 ms – 34.9 ms] | 3.05M [2.87M – 3.13M] | 1.7 MiB | 120 KiB |
| LibraryNestedSetKeepAncestors | wide-100k | 35.7 ms [33.8 ms – 36.0 ms] | 2.80M [2.78M – 2.96M] | 83.7 MiB | 6.9 MiB |
| LibraryNestedSetKeepAncestors | deep-10k | 6.0 ms [5.9 ms – 6.2 ms] | 1.67M [1.61M – 1.70M] | 17.8 MiB | 9.7 MiB |
| LibraryNestedSetKeepAncestors | category-1k x100 | 38.7 ms [37.1 ms – 39.9 ms] | 2.58M [2.51M – 2.70M] | 1.8 MiB | 208 KiB |
| LibraryNestedSetKeepAncestors | category-100k | 43.5 ms [41.8 ms – 45.7 ms] | 2.30M [2.19M – 2.39M] | 97.1 MiB | 17.7 MiB |
| NaiveAdjacencyPrune | balanced-37k | 13.4 ms [13.2 ms – 14.2 ms] | 2.79M [2.64M – 2.84M] | 71.7 MiB | 41.4 MiB |
| NaiveAdjacencyPrune | balanced-300k | 116.8 ms [113.4 ms – 119.5 ms] | 2.57M [2.51M – 2.64M] | 571.8 MiB | 330.3 MiB |
| NaiveAdjacencyPrune | wide-1k x100 | 21.0 ms [20.2 ms – 21.1 ms] | 4.76M [4.74M – 4.96M] | 2.8 MiB | 1.3 MiB |
| NaiveAdjacencyPrune | wide-100k | 37.3 ms [36.8 ms – 38.0 ms] | 2.68M [2.63M – 2.71M] | 206.9 MiB | 130.1 MiB |
| NaiveAdjacencyPrune | deep-10k | 2.7 ms [2.6 ms – 2.7 ms] | 3.71M [3.65M – 3.92M] | 19.1 MiB | 11.0 MiB |
| NaiveAdjacencyPrune | category-1k x100 | 26.9 ms [26.2 ms – 27.6 ms] | 3.72M [3.62M – 3.82M] | 2.9 MiB | 1.3 MiB |
| NaiveAdjacencyPrune | category-100k | 55.5 ms [55.1 ms – 57.6 ms] | 1.80M [1.74M – 1.81M] | 208.4 MiB | 129.1 MiB |
| LibraryAdjacencyPrune | balanced-37k | 19.6 ms [19.4 ms – 19.7 ms] | 1.91M [1.90M – 1.93M] | 48.2 MiB | 17.9 MiB |
| LibraryAdjacencyPrune | balanced-300k | 161.2 ms [160.3 ms – 162.7 ms] | 1.86M [1.84M – 1.87M] | 383.5 MiB | 141.9 MiB |
| LibraryAdjacencyPrune | wide-1k x100 | 49.6 ms [48.7 ms – 50.2 ms] | 2.02M [1.99M – 2.06M] | 2.3 MiB | 692 KiB |
| LibraryAdjacencyPrune | wide-100k | 62.5 ms [61.9 ms – 63.1 ms] | 1.60M [1.59M – 1.61M] | 142.6 MiB | 65.8 MiB |
| LibraryAdjacencyPrune | deep-10k | 2.2 ms [2.1 ms – 2.3 ms] | 4.59M [4.39M – 4.80M] | 11.2 MiB | 3.1 MiB |
| LibraryAdjacencyPrune | category-1k x100 | 55.1 ms [53.5 ms – 55.3 ms] | 1.81M [1.81M – 1.87M] | 2.3 MiB | 728 KiB |
| LibraryAdjacencyPrune | category-100k | 70.8 ms [70.5 ms – 73.1 ms] | 1.41M [1.37M – 1.42M] | 146.6 MiB | 67.2 MiB |
| LibraryNestedSetPrune | balanced-37k | 14.5 ms [14.3 ms – 14.7 ms] | 2.59M [2.55M – 2.61M] | 45.9 MiB | 15.6 MiB |
| LibraryNestedSetPrune | balanced-300k | 123.6 ms [119.3 ms – 129.5 ms] | 2.42M [2.31M – 2.51M] | 365.3 MiB | 123.7 MiB |
| LibraryNestedSetPrune | wide-1k x100 | 45.6 ms [45.2 ms – 46.7 ms] | 2.19M [2.14M – 2.22M] | 2.2 MiB | 653 KiB |
| LibraryNestedSetPrune | wide-100k | 53.1 ms [52.6 ms – 55.5 ms] | 1.88M [1.80M – 1.90M] | 138.6 MiB | 61.8 MiB |
| LibraryNestedSetPrune | deep-10k | 776 µs [764 µs – 826 µs] | 12.90M [12.11M – 13.10M] | 8.2 MiB | 120 KiB |
| LibraryNestedSetPrune | category-1k x100 | 44.7 ms [42.0 ms – 45.3 ms] | 2.24M [2.21M – 2.38M] | 2.2 MiB | 600 KiB |
| LibraryNestedSetPrune | category-100k | 52.6 ms [51.7 ms – 53.3 ms] | 1.90M [1.88M – 1.93M] | 133.6 MiB | 54.2 MiB |

### StreamingBench

| subject | set | time | nodes/s | mem_peak | own peak |
|---|---|---|---|---|---|
| GeneratorOnly | 11,111 | 4.5 ms [4.3 ms – 4.6 ms] | 2.48M [2.41M – 2.59M] | 972 KiB | 193 KiB |
| GeneratorOnly | 111,111 | 36.6 ms [36.4 ms – 37.7 ms] | 3.03M [2.95M – 3.05M] | 972 KiB | 193 KiB |
| GeneratorOnly | 1,111,111 | 357.5 ms [351.2 ms – 367.0 ms] | 3.11M [3.03M – 3.16M] | 972 KiB | 193 KiB |
| NestedSet | 11,111 | 8.6 ms [8.4 ms – 9.0 ms] | 1.30M [1.24M – 1.32M] | 1.0 MiB | 196 KiB |
| NestedSet | 111,111 | 67.9 ms [66.9 ms – 72.6 ms] | 1.64M [1.53M – 1.66M] | 1.0 MiB | 196 KiB |
| NestedSet | 1,111,111 | 734.5 ms [704.3 ms – 738.6 ms] | 1.51M [1.50M – 1.58M] | 1.0 MiB | 196 KiB |
| PreorderedAdjacency | 11,111 | 9.7 ms [9.6 ms – 10.0 ms] | 1.15M [1.12M – 1.16M] | 1.0 MiB | 196 KiB |
| PreorderedAdjacency | 111,111 | 72.3 ms [70.9 ms – 75.9 ms] | 1.54M [1.46M – 1.57M] | 1.0 MiB | 196 KiB |
| PreorderedAdjacency | 1,111,111 | 754.2 ms [725.0 ms – 777.3 ms] | 1.47M [1.43M – 1.53M] | 1.0 MiB | 196 KiB |
| MaterializedPath | 11,111 | 11.3 ms [10.9 ms – 11.4 ms] | 986k [971k – 1.02M] | 1.0 MiB | 195 KiB |
| MaterializedPath | 111,111 | 86.3 ms [84.6 ms – 91.5 ms] | 1.29M [1.21M – 1.31M] | 1.0 MiB | 195 KiB |
| MaterializedPath | 1,111,111 | 929.3 ms [903.8 ms – 970.1 ms] | 1.20M [1.15M – 1.23M] | 1.0 MiB | 195 KiB |
| NestedSetPrune | 11,111 | 11.7 ms [11.6 ms – 11.7 ms] | 953k [947k – 962k] | 1.0 MiB | 196 KiB |
| NestedSetPrune | 111,111 | 89.9 ms [86.0 ms – 92.2 ms] | 1.24M [1.21M – 1.29M] | 1.0 MiB | 196 KiB |
| NestedSetPrune | 1,111,111 | 795.0 ms [770.1 ms – 827.7 ms] | 1.40M [1.34M – 1.44M] | 1.0 MiB | 196 KiB |
| NestedSetKeepAncestors | 11,111 | 10.7 ms [10.4 ms – 11.2 ms] | 1.04M [993k – 1.07M] | 1.0 MiB | 196 KiB |
| NestedSetKeepAncestors | 111,111 | 80.1 ms [79.6 ms – 81.1 ms] | 1.39M [1.37M – 1.40M] | 1.0 MiB | 196 KiB |
| NestedSetKeepAncestors | 1,111,111 | 834.4 ms [795.8 ms – 857.6 ms] | 1.33M [1.30M – 1.40M] | 1.0 MiB | 196 KiB |
| NestedSetPromote | 11,111 | 10.4 ms [10.2 ms – 10.7 ms] | 1.07M [1.04M – 1.09M] | 1.0 MiB | 196 KiB |
| NestedSetPromote | 111,111 | 81.2 ms [80.1 ms – 85.2 ms] | 1.37M [1.30M – 1.39M] | 1.0 MiB | 196 KiB |
| NestedSetPromote | 1,111,111 | 799.1 ms [757.0 ms – 803.4 ms] | 1.39M [1.38M – 1.47M] | 1.0 MiB | 196 KiB |

### StrategyBench

| subject | set | time | nodes/s | mem_peak | own peak |
|---|---|---|---|---|---|
| NoFilter | balanced-37k | 7.8 ms [7.5 ms – 8.1 ms] | 4.83M [4.64M – 4.99M] | 30.5 MiB | 119 KiB |
| NoFilter | balanced-300k | 60.1 ms [59.8 ms – 62.1 ms] | 4.98M [4.83M – 5.01M] | 241.7 MiB | 119 KiB |
| NoFilter | wide-1k x100 | 18.3 ms [18.2 ms – 18.5 ms] | 5.46M [5.42M – 5.50M] | 1.7 MiB | 119 KiB |
| NoFilter | wide-100k | 19.9 ms [19.7 ms – 20.2 ms] | 5.02M [4.94M – 5.07M] | 76.9 MiB | 119 KiB |
| NoFilter | deep-10k | 1.9 ms [1.9 ms – 1.9 ms] | 5.20M [5.20M – 5.38M] | 8.4 MiB | 278 KiB |
| NoFilter | category-1k x100 | 19.4 ms [19.2 ms – 19.5 ms] | 5.16M [5.12M – 5.20M] | 1.7 MiB | 119 KiB |
| NoFilter | category-100k | 21.8 ms [21.5 ms – 22.0 ms] | 4.59M [4.54M – 4.65M] | 79.5 MiB | 119 KiB |
| PruneVisible | balanced-37k | 9.0 ms [8.9 ms – 9.3 ms] | 4.14M [4.05M – 4.19M] | 30.5 MiB | 120 KiB |
| PruneVisible | balanced-300k | 76.4 ms [71.2 ms – 77.2 ms] | 3.92M [3.88M – 4.21M] | 241.7 MiB | 120 KiB |
| PruneVisible | wide-1k x100 | 30.5 ms [29.8 ms – 31.4 ms] | 3.28M [3.19M – 3.36M] | 1.7 MiB | 120 KiB |
| PruneVisible | wide-100k | 33.5 ms [32.6 ms – 34.1 ms] | 2.99M [2.93M – 3.07M] | 77.0 MiB | 120 KiB |
| PruneVisible | deep-10k | 699 µs [666 µs – 716 µs] | 14.31M [13.96M – 15.03M] | 8.2 MiB | 120 KiB |
| PruneVisible | category-1k x100 | 27.3 ms [26.8 ms – 27.5 ms] | 3.66M [3.64M – 3.72M] | 1.7 MiB | 120 KiB |
| PruneVisible | category-100k | 28.3 ms [27.6 ms – 29.6 ms] | 3.54M [3.38M – 3.62M] | 79.5 MiB | 120 KiB |
| PruneHit | balanced-37k | 2.7 ms [2.7 ms – 2.9 ms] | 13.83M [12.97M – 13.93M] | 30.5 MiB | 120 KiB |
| PruneHit | balanced-300k | 22.4 ms [22.0 ms – 23.2 ms] | 13.40M [12.94M – 13.65M] | 241.7 MiB | 120 KiB |
| PruneHit | wide-1k x100 | 6.7 ms [6.7 ms – 7.2 ms] | 14.94M [13.94M – 15.02M] | 1.7 MiB | 120 KiB |
| PruneHit | wide-100k | 7.1 ms [7.0 ms – 7.1 ms] | 14.17M [14.14M – 14.29M] | 77.0 MiB | 120 KiB |
| PruneHit | deep-10k | 657 µs [640 µs – 690 µs] | 15.22M [14.50M – 15.61M] | 8.2 MiB | 120 KiB |
| PruneHit | category-1k x100 | 6.9 ms [6.8 ms – 7.3 ms] | 14.59M [13.79M – 14.77M] | 1.7 MiB | 120 KiB |
| PruneHit | category-100k | 7.3 ms [7.3 ms – 7.4 ms] | 13.62M [13.44M – 13.78M] | 79.5 MiB | 120 KiB |
| KeepAncestors | balanced-37k | 12.1 ms [11.8 ms – 12.5 ms] | 3.10M [3.00M – 3.17M] | 30.5 MiB | 120 KiB |
| KeepAncestors | balanced-300k | 103.5 ms [100.1 ms – 109.0 ms] | 2.89M [2.75M – 2.99M] | 241.7 MiB | 120 KiB |
| KeepAncestors | wide-1k x100 | 31.3 ms [30.4 ms – 32.5 ms] | 3.20M [3.08M – 3.29M] | 1.7 MiB | 120 KiB |
| KeepAncestors | wide-100k | 31.5 ms [30.5 ms – 33.2 ms] | 3.17M [3.01M – 3.28M] | 77.0 MiB | 120 KiB |
| KeepAncestors | deep-10k | 3.6 ms [3.6 ms – 3.7 ms] | 2.78M [2.68M – 2.81M] | 8.6 MiB | 539 KiB |
| KeepAncestors | category-1k x100 | 32.9 ms [31.4 ms – 33.4 ms] | 3.04M [3.00M – 3.19M] | 1.7 MiB | 120 KiB |
| KeepAncestors | category-100k | 34.0 ms [32.2 ms – 34.7 ms] | 2.95M [2.88M – 3.11M] | 79.5 MiB | 120 KiB |
| Promote | balanced-37k | 11.1 ms [10.8 ms – 11.4 ms] | 3.38M [3.29M – 3.48M] | 30.5 MiB | 120 KiB |
| Promote | balanced-300k | 91.7 ms [89.8 ms – 93.3 ms] | 3.27M [3.21M – 3.34M] | 241.7 MiB | 120 KiB |
| Promote | wide-1k x100 | 27.4 ms [27.0 ms – 27.8 ms] | 3.66M [3.60M – 3.70M] | 1.7 MiB | 120 KiB |
| Promote | wide-100k | 28.9 ms [27.9 ms – 29.9 ms] | 3.46M [3.35M – 3.59M] | 77.0 MiB | 120 KiB |
| Promote | deep-10k | 2.9 ms [2.9 ms – 3.0 ms] | 3.41M [3.31M – 3.44M] | 8.4 MiB | 299 KiB |
| Promote | category-1k x100 | 30.6 ms [30.1 ms – 31.7 ms] | 3.27M [3.16M – 3.32M] | 1.7 MiB | 120 KiB |
| Promote | category-100k | 30.6 ms [30.2 ms – 31.8 ms] | 3.27M [3.15M – 3.31M] | 79.5 MiB | 120 KiB |
| Annotate | balanced-37k | 26.5 ms [26.0 ms – 27.3 ms] | 1.41M [1.37M – 1.44M] | 30.5 MiB | 118 KiB |
| Annotate | balanced-300k | 216.7 ms [208.9 ms – 227.4 ms] | 1.38M [1.32M – 1.43M] | 241.7 MiB | 118 KiB |
| Annotate | wide-1k x100 | 71.4 ms [68.4 ms – 72.5 ms] | 1.40M [1.38M – 1.46M] | 1.7 MiB | 118 KiB |
| Annotate | wide-100k | 72.6 ms [69.7 ms – 75.4 ms] | 1.38M [1.33M – 1.43M] | 77.0 MiB | 118 KiB |
| Annotate | deep-10k | 7.0 ms [6.7 ms – 7.2 ms] | 1.44M [1.38M – 1.50M] | 9.1 MiB | 1.0 MiB |
| Annotate | category-1k x100 | 73.7 ms [70.7 ms – 74.9 ms] | 1.36M [1.34M – 1.41M] | 1.7 MiB | 118 KiB |
| Annotate | category-100k | 74.3 ms [70.8 ms – 77.7 ms] | 1.35M [1.29M – 1.41M] | 79.5 MiB | 118 KiB |
| ActiveTrailHit | balanced-37k | 19.9 ms [19.4 ms – 20.8 ms] | 1.88M [1.80M – 1.93M] | 30.5 MiB | 123 KiB |
| ActiveTrailHit | balanced-300k | 166.4 ms [163.4 ms – 173.9 ms] | 1.80M [1.72M – 1.83M] | 241.7 MiB | 123 KiB |
| ActiveTrailHit | wide-1k x100 | 50.1 ms [49.7 ms – 52.1 ms] | 2.00M [1.92M – 2.01M] | 1.7 MiB | 123 KiB |
| ActiveTrailHit | wide-100k | 53.1 ms [51.5 ms – 55.2 ms] | 1.88M [1.81M – 1.94M] | 77.0 MiB | 123 KiB |
| ActiveTrailHit | deep-10k | 5.8 ms [5.7 ms – 5.9 ms] | 1.72M [1.69M – 1.76M] | 9.2 MiB | 1.1 MiB |
| ActiveTrailHit | category-1k x100 | 52.1 ms [50.0 ms – 52.8 ms] | 1.92M [1.89M – 2.00M] | 1.7 MiB | 123 KiB |
| ActiveTrailHit | category-100k | 55.7 ms [52.8 ms – 57.1 ms] | 1.79M [1.75M – 1.89M] | 79.5 MiB | 123 KiB |
| ActiveTrailSingle | balanced-37k | 16.5 ms [15.4 ms – 16.6 ms] | 2.26M [2.25M – 2.43M] | 30.5 MiB | 124 KiB |
| ActiveTrailSingle | balanced-300k | 123.2 ms [121.5 ms – 125.7 ms] | 2.43M [2.38M – 2.47M] | 241.7 MiB | 124 KiB |
| ActiveTrailSingle | wide-1k x100 | 53.0 ms [50.2 ms – 55.0 ms] | 1.89M [1.82M – 1.99M] | 1.7 MiB | 124 KiB |
| ActiveTrailSingle | wide-100k | 55.8 ms [54.1 ms – 56.5 ms] | 1.79M [1.77M – 1.85M] | 82.9 MiB | 6.0 MiB |
| ActiveTrailSingle | deep-10k | 5.6 ms [5.4 ms – 5.7 ms] | 1.79M [1.75M – 1.84M] | 11.2 MiB | 3.1 MiB |
| ActiveTrailSingle | category-1k x100 | 41.6 ms [40.6 ms – 43.5 ms] | 2.41M [2.30M – 2.47M] | 1.7 MiB | 124 KiB |
| ActiveTrailSingle | category-100k | 43.6 ms [43.0 ms – 46.1 ms] | 2.29M [2.17M – 2.32M] | 79.5 MiB | 124 KiB |

