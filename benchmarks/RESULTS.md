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

### SourceBench

| subject | set | time | nodes/s | mem_peak | own peak |
|---|---|---|---|---|---|
| NestedSet | balanced-37k | 12.4 ms [12.1 ms – 13.1 ms] | 3.01M [2.86M – 3.10M] | 31.4 MiB | 1.0 MiB |
| NestedSet | balanced-300k | 103.3 ms [99.3 ms – 110.4 ms] | 2.90M [2.71M – 3.02M] | 249.6 MiB | 8.0 MiB |
| NestedSet | wide-1k x100 | 32.6 ms [30.4 ms – 34.8 ms] | 3.07M [2.87M – 3.29M] | 1.7 MiB | 100 KiB |
| NestedSet | wide-100k | 34.5 ms [32.7 ms – 37.9 ms] | 2.90M [2.64M – 3.05M] | 78.9 MiB | 2.0 MiB |
| NestedSet | deep-10k | 3.4 ms [2.9 ms – 4.1 ms] | 2.93M [2.47M – 3.46M] | 8.6 MiB | 539 KiB |
| NestedSet | category-1k x100 | 32.2 ms [30.9 ms – 33.2 ms] | 3.11M [3.01M – 3.24M] | 1.7 MiB | 100 KiB |
| NestedSet | category-100k | 34.9 ms [33.8 ms – 35.3 ms] | 2.86M [2.83M – 2.95M] | 81.4 MiB | 2.0 MiB |
| AdjacencyList | balanced-37k | 14.5 ms [14.2 ms – 15.4 ms] | 2.58M [2.43M – 2.63M] | 35.1 MiB | 4.8 MiB |
| AdjacencyList | balanced-300k | 130.6 ms [124.6 ms – 136.2 ms] | 2.29M [2.20M – 2.40M] | 279.6 MiB | 38.0 MiB |
| AdjacencyList | wide-1k x100 | 33.3 ms [31.8 ms – 33.7 ms] | 3.00M [2.97M – 3.15M] | 1.8 MiB | 179 KiB |
| AdjacencyList | wide-100k | 41.0 ms [38.7 ms – 44.2 ms] | 2.44M [2.26M – 2.58M] | 88.6 MiB | 11.8 MiB |
| AdjacencyList | deep-10k | 4.9 ms [4.7 ms – 5.3 ms] | 2.02M [1.89M – 2.13M] | 11.4 MiB | 3.3 MiB |
| AdjacencyList | category-1k x100 | 43.2 ms [41.7 ms – 44.7 ms] | 2.31M [2.24M – 2.40M] | 1.9 MiB | 269 KiB |
| AdjacencyList | category-100k | 53.4 ms [52.7 ms – 55.1 ms] | 1.87M [1.81M – 1.90M] | 100.1 MiB | 20.8 MiB |
| PreorderedAdjacency | balanced-37k | 12.5 ms [12.0 ms – 12.9 ms] | 2.99M [2.90M – 3.12M] | 31.4 MiB | 1.0 MiB |
| PreorderedAdjacency | balanced-300k | 99.9 ms [95.9 ms – 105.8 ms] | 3.00M [2.83M – 3.13M] | 249.6 MiB | 8.0 MiB |
| PreorderedAdjacency | wide-1k x100 | 28.7 ms [27.8 ms – 30.7 ms] | 3.49M [3.26M – 3.61M] | 1.7 MiB | 100 KiB |
| PreorderedAdjacency | wide-100k | 31.6 ms [31.1 ms – 33.6 ms] | 3.16M [2.98M – 3.22M] | 78.9 MiB | 2.0 MiB |
| PreorderedAdjacency | deep-10k | 3.0 ms [2.9 ms – 3.1 ms] | 3.36M [3.27M – 3.50M] | 8.6 MiB | 539 KiB |
| PreorderedAdjacency | category-1k x100 | 30.5 ms [29.6 ms – 33.4 ms] | 3.28M [2.99M – 3.38M] | 1.7 MiB | 100 KiB |
| PreorderedAdjacency | category-100k | 33.1 ms [32.1 ms – 34.9 ms] | 3.02M [2.86M – 3.11M] | 81.4 MiB | 2.0 MiB |
| MaterializedPath | balanced-37k | 14.7 ms [13.5 ms – 15.8 ms] | 2.55M [2.37M – 2.77M] | 31.4 MiB | 1.0 MiB |
| MaterializedPath | balanced-300k | 126.8 ms [120.9 ms – 137.4 ms] | 2.36M [2.18M – 2.48M] | 249.6 MiB | 8.0 MiB |
| MaterializedPath | wide-1k x100 | 39.0 ms [32.6 ms – 46.4 ms] | 2.57M [2.16M – 3.07M] | 1.7 MiB | 100 KiB |
| MaterializedPath | wide-100k | 56.1 ms [36.1 ms – 80.8 ms] | 1.78M [1.24M – 2.77M] | 78.9 MiB | 2.0 MiB |
| MaterializedPath | category-1k x100 | 37.7 ms [34.8 ms – 40.5 ms] | 2.65M [2.47M – 2.87M] | 1.7 MiB | 100 KiB |
| MaterializedPath | category-100k | 41.5 ms [38.9 ms – 44.1 ms] | 2.41M [2.27M – 2.57M] | 81.4 MiB | 2.0 MiB |
| NestedArray | balanced-37k | 9.4 ms [9.1 ms – 10.2 ms] | 3.97M [3.68M – 4.11M] | 56.3 MiB | 100 KiB |
| NestedArray | balanced-300k | 79.6 ms [77.3 ms – 83.2 ms] | 3.76M [3.60M – 3.88M] | 448.2 MiB | 100 KiB |
| NestedArray | wide-1k x100 | 18.4 ms [17.7 ms – 19.6 ms] | 5.43M [5.11M – 5.67M] | 2.4 MiB | 100 KiB |
| NestedArray | wide-100k | 21.2 ms [20.2 ms – 22.5 ms] | 4.73M [4.45M – 4.96M] | 147.2 MiB | 2.0 MiB |
| NestedArray | deep-10k | 19.2 ms [18.8 ms – 20.5 ms] | 520k [489k – 532k] | 20.9 MiB | 3.9 MiB |
| NestedArray | category-1k x100 | 30.6 ms [29.3 ms – 32.0 ms] | 3.27M [3.12M – 3.41M] | 2.4 MiB | 100 KiB |
| NestedArray | category-100k | 39.0 ms [37.5 ms – 41.2 ms] | 2.57M [2.43M – 2.67M] | 154.3 MiB | 100 KiB |
| DepthList | balanced-37k | 6.0 ms [5.7 ms – 6.1 ms] | 6.29M [6.10M – 6.55M] | 40.0 MiB | 1.0 MiB |
| DepthList | balanced-300k | 50.0 ms [49.2 ms – 51.2 ms] | 5.99M [5.85M – 6.09M] | 319.2 MiB | 8.0 MiB |
| DepthList | wide-1k x100 | 14.9 ms [14.5 ms – 15.7 ms] | 6.71M [6.36M – 6.89M] | 1.9 MiB | 128 KiB |
| DepthList | wide-100k | 16.8 ms [15.8 ms – 18.1 ms] | 5.97M [5.51M – 6.31M] | 101.4 MiB | 2.0 MiB |
| DepthList | deep-10k | 1.5 ms [1.4 ms – 1.6 ms] | 6.71M [6.41M – 7.04M] | 10.6 MiB | 277 KiB |
| DepthList | category-1k x100 | 14.9 ms [14.5 ms – 15.4 ms] | 6.70M [6.48M – 6.89M] | 1.9 MiB | 128 KiB |
| DepthList | category-100k | 16.2 ms [15.8 ms – 16.5 ms] | 6.18M [6.05M – 6.34M] | 103.9 MiB | 2.0 MiB |

### Phase0PipelineBench

| subject | set | time | nodes/s | mem_peak | own peak |
|---|---|---|---|---|---|
| NaiveKeepAncestors | balanced-300k | 143.8 ms [140.3 ms – 149.7 ms] | 2.08M [2.00M – 2.13M] | 501.8 MiB | 260.3 MiB |
| NaiveKeepAncestors | category-100k | 60.1 ms [57.3 ms – 63.9 ms] | 1.66M [1.56M – 1.74M] | 175.0 MiB | 95.7 MiB |
| NaiveKeepAncestors | wide-100k | 38.1 ms [37.3 ms – 40.2 ms] | 2.62M [2.49M – 2.68M] | 155.1 MiB | 78.3 MiB |
| LibraryKeepAncestors | balanced-300k | 175.9 ms [161.6 ms – 189.2 ms] | 1.70M [1.58M – 1.85M] | 294.1 MiB | 52.6 MiB |
| LibraryKeepAncestors | category-100k | 56.6 ms [55.8 ms – 59.3 ms] | 1.77M [1.69M – 1.79M] | 99.1 MiB | 19.7 MiB |
| LibraryKeepAncestors | wide-100k | 49.0 ms [47.3 ms – 51.7 ms] | 2.04M [1.93M – 2.12M] | 85.8 MiB | 8.9 MiB |
| LibraryFastSinkKeepAncestors | balanced-300k | 161.2 ms [158.9 ms – 166.9 ms] | 1.86M [1.80M – 1.89M] | 294.1 MiB | 52.6 MiB |
| LibraryFastSinkKeepAncestors | category-100k | 58.0 ms [56.0 ms – 60.5 ms] | 1.72M [1.65M – 1.78M] | 99.1 MiB | 19.7 MiB |
| LibraryFastSinkKeepAncestors | wide-100k | 49.6 ms [48.0 ms – 51.7 ms] | 2.02M [1.94M – 2.09M] | 85.8 MiB | 8.9 MiB |
| CursorFastKeepAncestors | balanced-300k | 107.4 ms [97.9 ms – 115.7 ms] | 2.79M [2.59M – 3.06M] | 286.1 MiB | 44.6 MiB |
| CursorFastKeepAncestors | category-100k | 35.8 ms [35.1 ms – 37.8 ms] | 2.80M [2.64M – 2.85M] | 97.0 MiB | 17.7 MiB |
| CursorFastKeepAncestors | wide-100k | 28.4 ms [27.4 ms – 28.9 ms] | 3.52M [3.45M – 3.66M] | 83.7 MiB | 6.9 MiB |
| GeneratorKeepAncestors | balanced-300k | 56.6 ms [54.7 ms – 59.5 ms] | 5.29M [5.04M – 5.48M] | 286.1 MiB | 44.6 MiB |
| GeneratorKeepAncestors | category-100k | 22.9 ms [20.4 ms – 27.0 ms] | 4.37M [3.71M – 4.91M] | 97.0 MiB | 17.7 MiB |
| GeneratorKeepAncestors | wide-100k | 14.3 ms [13.8 ms – 15.1 ms] | 6.97M [6.62M – 7.23M] | 83.7 MiB | 6.9 MiB |
| NaivePrune | balanced-300k | 131.8 ms [127.4 ms – 141.2 ms] | 2.27M [2.12M – 2.35M] | 580.9 MiB | 339.4 MiB |
| NaivePrune | category-100k | 56.0 ms [54.5 ms – 58.4 ms] | 1.78M [1.71M – 1.83M] | 211.4 MiB | 132.1 MiB |
| NaivePrune | wide-100k | 44.1 ms [41.0 ms – 47.6 ms] | 2.27M [2.10M – 2.44M] | 210.0 MiB | 133.2 MiB |
| LibraryPrune | balanced-300k | 170.9 ms [154.2 ms – 183.0 ms] | 1.75M [1.64M – 1.94M] | 373.3 MiB | 131.7 MiB |
| LibraryPrune | category-100k | 71.1 ms [63.5 ms – 80.0 ms] | 1.41M [1.25M – 1.57M] | 135.6 MiB | 56.2 MiB |
| LibraryPrune | wide-100k | 77.4 ms [68.3 ms – 86.5 ms] | 1.29M [1.16M – 1.46M] | 140.6 MiB | 63.8 MiB |
| LibraryFastSinkPrune | balanced-300k | 154.6 ms [151.4 ms – 163.6 ms] | 1.94M [1.83M – 1.98M] | 373.3 MiB | 131.7 MiB |
| LibraryFastSinkPrune | category-100k | 63.4 ms [61.8 ms – 67.0 ms] | 1.58M [1.49M – 1.62M] | 135.6 MiB | 56.2 MiB |
| LibraryFastSinkPrune | wide-100k | 69.6 ms [66.7 ms – 73.6 ms] | 1.44M [1.36M – 1.50M] | 140.6 MiB | 63.8 MiB |
| CursorFastPrune | balanced-300k | 109.2 ms [106.2 ms – 115.2 ms] | 2.74M [2.60M – 2.82M] | 365.2 MiB | 123.7 MiB |
| CursorFastPrune | category-100k | 46.3 ms [45.6 ms – 49.7 ms] | 2.16M [2.01M – 2.19M] | 133.5 MiB | 54.2 MiB |
| CursorFastPrune | wide-100k | 49.1 ms [47.7 ms – 50.3 ms] | 2.04M [1.99M – 2.10M] | 138.5 MiB | 61.8 MiB |
| GeneratorPrune | balanced-300k | 79.8 ms [77.0 ms – 83.5 ms] | 3.76M [3.59M – 3.89M] | 365.2 MiB | 123.7 MiB |
| GeneratorPrune | category-100k | 34.4 ms [31.9 ms – 37.3 ms] | 2.91M [2.68M – 3.13M] | 133.5 MiB | 54.2 MiB |
| GeneratorPrune | wide-100k | 34.3 ms [31.6 ms – 36.2 ms] | 2.91M [2.76M – 3.16M] | 138.5 MiB | 61.8 MiB |

### Phase0GeneratorBench

| subject | set | time | nodes/s | mem_peak | own peak |
|---|---|---|---|---|---|
| Library | balanced-300k | 105.0 ms [101.1 ms – 110.2 ms] | 2.85M [2.72M – 2.96M] | 249.7 MiB | 8.1 MiB |
| Library | category-100k | 39.4 ms [38.4 ms – 42.7 ms] | 2.54M [2.34M – 2.60M] | 81.5 MiB | 2.1 MiB |
| Library | wide-100k | 45.6 ms [43.9 ms – 47.1 ms] | 2.19M [2.12M – 2.28M] | 78.9 MiB | 2.1 MiB |
| LibraryX2 | balanced-300k | 157.6 ms [147.0 ms – 167.4 ms] | 1.90M [1.79M – 2.04M] | 249.7 MiB | 8.1 MiB |
| LibraryX2 | category-100k | 57.0 ms [53.9 ms – 59.9 ms] | 1.76M [1.67M – 1.86M] | 81.5 MiB | 2.1 MiB |
| LibraryX2 | wide-100k | 66.2 ms [63.7 ms – 69.6 ms] | 1.51M [1.44M – 1.57M] | 78.9 MiB | 2.1 MiB |
| CursorIterator | balanced-300k | 77.2 ms [76.2 ms – 80.8 ms] | 3.88M [3.71M – 3.93M] | 249.6 MiB | 8.1 MiB |
| CursorIterator | category-100k | 30.3 ms [29.4 ms – 31.7 ms] | 3.30M [3.16M – 3.40M] | 81.4 MiB | 2.1 MiB |
| CursorIterator | wide-100k | 35.3 ms [33.5 ms – 37.0 ms] | 2.83M [2.71M – 2.98M] | 78.9 MiB | 2.1 MiB |
| CursorList | balanced-300k | 65.4 ms [64.3 ms – 68.3 ms] | 4.58M [4.39M – 4.66M] | 241.6 MiB | 109 KiB |
| CursorList | category-100k | 26.4 ms [25.5 ms – 27.5 ms] | 3.78M [3.64M – 3.92M] | 79.4 MiB | 109 KiB |
| CursorList | wide-100k | 30.1 ms [29.7 ms – 31.3 ms] | 3.33M [3.19M – 3.37M] | 76.9 MiB | 109 KiB |
| CursorListX2 | balanced-300k | 106.4 ms [103.6 ms – 111.3 ms] | 2.82M [2.69M – 2.89M] | 241.6 MiB | 109 KiB |
| CursorListX2 | category-100k | 44.3 ms [41.8 ms – 45.8 ms] | 2.26M [2.19M – 2.39M] | 79.4 MiB | 109 KiB |
| CursorListX2 | wide-100k | 50.7 ms [49.7 ms – 52.6 ms] | 1.97M [1.90M – 2.01M] | 76.9 MiB | 109 KiB |
| GeneratorSend | balanced-300k | 39.4 ms [37.0 ms – 43.3 ms] | 7.61M [6.93M – 8.10M] | 241.7 MiB | 178 KiB |
| GeneratorSend | category-100k | 16.2 ms [15.2 ms – 17.0 ms] | 6.18M [5.88M – 6.59M] | 79.5 MiB | 178 KiB |
| GeneratorSend | wide-100k | 18.3 ms [17.6 ms – 18.9 ms] | 5.46M [5.30M – 5.68M] | 76.9 MiB | 178 KiB |
| GeneratorSendX2 | balanced-300k | 50.2 ms [49.5 ms – 51.2 ms] | 5.97M [5.85M – 6.05M] | 241.7 MiB | 178 KiB |
| GeneratorSendX2 | category-100k | 22.0 ms [20.5 ms – 23.2 ms] | 4.54M [4.31M – 4.87M] | 79.5 MiB | 178 KiB |
| GeneratorSendX2 | wide-100k | 23.1 ms [22.6 ms – 23.7 ms] | 4.33M [4.22M – 4.42M] | 76.9 MiB | 178 KiB |
| GeneratorFlag | balanced-300k | 34.2 ms [33.6 ms – 36.1 ms] | 8.77M [8.29M – 8.91M] | 241.7 MiB | 178 KiB |
| GeneratorFlag | category-100k | 13.4 ms [13.3 ms – 14.3 ms] | 7.45M [7.00M – 7.54M] | 79.5 MiB | 178 KiB |
| GeneratorFlag | wide-100k | 14.8 ms [14.5 ms – 15.5 ms] | 6.75M [6.47M – 6.88M] | 76.9 MiB | 178 KiB |
| GeneratorFlagX2 | balanced-300k | 43.9 ms [42.3 ms – 46.3 ms] | 6.82M [6.47M – 7.08M] | 241.7 MiB | 178 KiB |
| GeneratorFlagX2 | category-100k | 17.3 ms [16.7 ms – 18.5 ms] | 5.76M [5.41M – 5.97M] | 79.5 MiB | 178 KiB |
| GeneratorFlagX2 | wide-100k | 19.5 ms [19.2 ms – 20.5 ms] | 5.12M [4.88M – 5.20M] | 76.9 MiB | 178 KiB |

### Phase0NestedArrayBench

| subject | set | time | nodes/s | mem_peak | own peak |
|---|---|---|---|---|---|
| Library | balanced-300k | 66.1 ms [64.4 ms – 69.3 ms] | 4.53M [4.32M – 4.66M] | 448.2 MiB | 102 KiB |
| Library | wide-100k | 17.6 ms [17.0 ms – 18.6 ms] | 5.69M [5.37M – 5.87M] | 147.2 MiB | 2.0 MiB |
| Library | deep-10k | 19.2 ms [18.6 ms – 20.0 ms] | 522k [499k – 538k] | 20.9 MiB | 3.9 MiB |
| Library | category-100k | 35.2 ms [34.4 ms – 37.9 ms] | 2.84M [2.64M – 2.91M] | 154.3 MiB | 102 KiB |
| Indexed | balanced-300k | 56.9 ms [53.7 ms – 60.0 ms] | 5.26M [5.00M – 5.57M] | 448.2 MiB | 106 KiB |
| Indexed | wide-100k | 14.5 ms [14.1 ms – 15.1 ms] | 6.92M [6.63M – 7.09M] | 145.3 MiB | 106 KiB |
| Indexed | deep-10k | 2.4 ms [2.3 ms – 2.5 ms] | 4.10M [3.98M – 4.40M] | 17.3 MiB | 537 KiB |
| Indexed | category-100k | 27.5 ms [26.7 ms – 28.6 ms] | 3.64M [3.50M – 3.75M] | 154.3 MiB | 106 KiB |

### Phase0AccessorBench

| subject | set | time | nodes/s | mem_peak | own peak |
|---|---|---|---|---|---|
| AutoAccessor | balanced-300k | 92.1 ms [90.8 ms – 97.4 ms] | 3.25M [3.08M – 3.30M] | 249.5 MiB | 8.0 MiB |
| AutoAccessor | category-100k | 32.6 ms [30.9 ms – 35.2 ms] | 3.07M [2.84M – 3.23M] | 81.3 MiB | 2.0 MiB |
| AutoAccessor | wide-100k | 33.0 ms [31.8 ms – 33.4 ms] | 3.03M [3.00M – 3.15M] | 78.8 MiB | 2.0 MiB |
| AutoAccessorGeneratorRows | balanced-300k | 92.0 ms [87.1 ms – 95.7 ms] | 3.26M [3.13M – 3.44M] | 241.6 MiB | 120 KiB |
| AutoAccessorGeneratorRows | category-100k | 32.9 ms [31.2 ms – 33.6 ms] | 3.04M [2.97M – 3.20M] | 79.4 MiB | 120 KiB |
| AutoAccessorGeneratorRows | wide-100k | 30.2 ms [27.8 ms – 33.4 ms] | 3.31M [2.99M – 3.60M] | 76.9 MiB | 120 KiB |
| ArrayKeyAccessor | balanced-300k | 99.4 ms [92.4 ms – 107.8 ms] | 3.02M [2.78M – 3.24M] | 249.5 MiB | 8.0 MiB |
| ArrayKeyAccessor | category-100k | 34.7 ms [32.0 ms – 36.6 ms] | 2.88M [2.73M – 3.13M] | 81.3 MiB | 2.0 MiB |
| ArrayKeyAccessor | wide-100k | 30.8 ms [29.5 ms – 32.0 ms] | 3.25M [3.13M – 3.39M] | 78.8 MiB | 2.0 MiB |
| InlineKey | balanced-300k | 78.4 ms [74.4 ms – 81.6 ms] | 3.82M [3.67M – 4.03M] | 249.6 MiB | 8.1 MiB |
| InlineKey | category-100k | 26.4 ms [25.7 ms – 27.9 ms] | 3.79M [3.59M – 3.90M] | 81.4 MiB | 2.1 MiB |
| InlineKey | wide-100k | 25.2 ms [24.1 ms – 26.2 ms] | 3.97M [3.81M – 4.16M] | 78.9 MiB | 2.1 MiB |
| InlineKeyInt | balanced-300k | 65.7 ms [63.7 ms – 67.2 ms] | 4.56M [4.46M – 4.70M] | 249.5 MiB | 8.0 MiB |
| InlineKeyInt | category-100k | 22.6 ms [21.8 ms – 23.7 ms] | 4.43M [4.23M – 4.59M] | 81.3 MiB | 2.0 MiB |
| InlineKeyInt | wide-100k | 21.6 ms [20.5 ms – 22.1 ms] | 4.64M [4.52M – 4.87M] | 78.8 MiB | 2.0 MiB |
| InlineKeyIntList | balanced-300k | 53.4 ms [51.5 ms – 56.2 ms] | 5.61M [5.33M – 5.82M] | 241.6 MiB | 110 KiB |
| InlineKeyIntList | category-100k | 18.7 ms [18.1 ms – 19.5 ms] | 5.34M [5.12M – 5.54M] | 79.4 MiB | 110 KiB |
| InlineKeyIntList | wide-100k | 17.4 ms [16.6 ms – 18.8 ms] | 5.73M [5.32M – 6.04M] | 76.9 MiB | 110 KiB |

### SinkBench

| subject | set | time | nodes/s | mem_peak | own peak |
|---|---|---|---|---|---|
| Count | balanced-37k | 10.8 ms [10.0 ms – 11.5 ms] | 3.48M [3.26M – 3.76M] | 31.4 MiB | 1.0 MiB |
| Count | balanced-300k | 86.1 ms [83.8 ms – 91.8 ms] | 3.48M [3.26M – 3.57M] | 249.6 MiB | 8.0 MiB |
| Count | wide-1k x100 | 25.4 ms [24.6 ms – 25.9 ms] | 3.94M [3.86M – 4.07M] | 1.7 MiB | 99 KiB |
| Count | wide-100k | 29.0 ms [27.4 ms – 29.9 ms] | 3.45M [3.35M – 3.65M] | 78.9 MiB | 2.0 MiB |
| Count | deep-10k | 2.5 ms [2.4 ms – 2.6 ms] | 4.05M [3.90M – 4.25M] | 8.6 MiB | 538 KiB |
| Count | category-1k x100 | 28.2 ms [27.1 ms – 31.5 ms] | 3.54M [3.17M – 3.69M] | 1.7 MiB | 99 KiB |
| Count | category-100k | 29.5 ms [28.8 ms – 30.9 ms] | 3.39M [3.23M – 3.47M] | 81.4 MiB | 2.0 MiB |
| Iterate | balanced-37k | 14.9 ms [13.2 ms – 16.4 ms] | 2.52M [2.29M – 2.84M] | 31.4 MiB | 1.0 MiB |
| Iterate | balanced-300k | 106.4 ms [101.3 ms – 109.1 ms] | 2.82M [2.75M – 2.96M] | 249.6 MiB | 8.0 MiB |
| Iterate | wide-1k x100 | 31.8 ms [29.8 ms – 32.6 ms] | 3.15M [3.07M – 3.36M] | 1.7 MiB | 100 KiB |
| Iterate | wide-100k | 34.0 ms [31.9 ms – 37.6 ms] | 2.94M [2.66M – 3.13M] | 78.9 MiB | 2.0 MiB |
| Iterate | deep-10k | 2.9 ms [2.8 ms – 3.1 ms] | 3.47M [3.27M – 3.54M] | 8.6 MiB | 539 KiB |
| Iterate | category-1k x100 | 31.6 ms [31.2 ms – 33.1 ms] | 3.16M [3.02M – 3.21M] | 1.7 MiB | 100 KiB |
| Iterate | category-100k | 34.0 ms [33.2 ms – 35.7 ms] | 2.94M [2.80M – 3.01M] | 81.4 MiB | 2.0 MiB |
| ToNestedArray | balanced-37k | 20.6 ms [19.6 ms – 21.5 ms] | 1.82M [1.74M – 1.91M] | 57.2 MiB | 26.8 MiB |
| ToNestedArray | balanced-300k | 179.5 ms [160.5 ms – 204.0 ms] | 1.67M [1.47M – 1.87M] | 456.2 MiB | 214.6 MiB |
| ToNestedArray | wide-1k x100 | 45.1 ms [41.5 ms – 49.9 ms] | 2.22M [2.01M – 2.41M] | 2.3 MiB | 740 KiB |
| ToNestedArray | wide-100k | 53.5 ms [49.5 ms – 56.8 ms] | 1.87M [1.76M – 2.02M] | 147.2 MiB | 70.4 MiB |
| ToNestedArray | deep-10k | 5.1 ms [4.6 ms – 5.4 ms] | 1.96M [1.84M – 2.18M] | 17.8 MiB | 9.7 MiB |
| ToNestedArray | category-1k x100 | 48.6 ms [45.8 ms – 51.1 ms] | 2.06M [1.96M – 2.18M] | 2.4 MiB | 808 KiB |
| ToNestedArray | category-100k | 61.1 ms [58.8 ms – 63.1 ms] | 1.64M [1.59M – 1.70M] | 156.3 MiB | 76.9 MiB |
| ToNestedSet | balanced-37k | 20.3 ms [19.2 ms – 21.7 ms] | 1.85M [1.73M – 1.95M] | 61.3 MiB | 30.9 MiB |
| ToNestedSet | balanced-300k | 179.2 ms [169.2 ms – 184.9 ms] | 1.67M [1.62M – 1.77M] | 488.5 MiB | 246.9 MiB |
| ToNestedSet | wide-1k x100 | 43.4 ms [42.4 ms – 44.7 ms] | 2.31M [2.24M – 2.36M] | 2.4 MiB | 819 KiB |
| ToNestedSet | wide-100k | 56.2 ms [54.4 ms – 58.8 ms] | 1.78M [1.70M – 1.84M] | 155.3 MiB | 78.4 MiB |
| ToNestedSet | deep-10k | 4.6 ms [4.4 ms – 4.8 ms] | 2.16M [2.08M – 2.30M] | 16.8 MiB | 8.7 MiB |
| ToNestedSet | category-1k x100 | 45.6 ms [43.7 ms – 48.1 ms] | 2.19M [2.08M – 2.29M] | 2.4 MiB | 819 KiB |
| ToNestedSet | category-100k | 58.8 ms [57.7 ms – 60.3 ms] | 1.70M [1.66M – 1.73M] | 157.8 MiB | 78.4 MiB |
| ToAdjacencyList | balanced-37k | 17.3 ms [16.6 ms – 18.0 ms] | 2.17M [2.08M – 2.26M] | 57.2 MiB | 26.9 MiB |
| ToAdjacencyList | balanced-300k | 163.1 ms [142.7 ms – 187.3 ms] | 1.84M [1.60M – 2.10M] | 456.4 MiB | 214.9 MiB |
| ToAdjacencyList | wide-1k x100 | 41.6 ms [38.2 ms – 43.9 ms] | 2.41M [2.28M – 2.62M] | 2.3 MiB | 740 KiB |
| ToAdjacencyList | wide-100k | 53.3 ms [44.7 ms – 62.5 ms] | 1.88M [1.60M – 2.24M] | 147.2 MiB | 70.4 MiB |
| ToAdjacencyList | deep-10k | 4.3 ms [4.2 ms – 4.5 ms] | 2.32M [2.22M – 2.39M] | 15.8 MiB | 7.7 MiB |
| ToAdjacencyList | category-1k x100 | 43.9 ms [41.4 ms – 47.2 ms] | 2.28M [2.12M – 2.42M] | 2.3 MiB | 739 KiB |
| ToAdjacencyList | category-100k | 49.2 ms [47.8 ms – 54.5 ms] | 2.03M [1.84M – 2.09M] | 149.8 MiB | 70.4 MiB |
| ToFlatList | balanced-37k | 13.7 ms [13.1 ms – 14.2 ms] | 2.73M [2.64M – 2.85M] | 45.8 MiB | 15.5 MiB |
| ToFlatList | balanced-300k | 125.4 ms [119.0 ms – 130.3 ms] | 2.39M [2.30M – 2.52M] | 365.0 MiB | 123.5 MiB |
| ToFlatList | wide-1k x100 | 33.7 ms [32.6 ms – 35.0 ms] | 2.97M [2.86M – 3.07M] | 2.0 MiB | 426 KiB |
| ToFlatList | wide-100k | 41.1 ms [40.3 ms – 42.2 ms] | 2.43M [2.37M – 2.48M] | 116.7 MiB | 39.9 MiB |
| ToFlatList | deep-10k | 3.5 ms [3.3 ms – 3.6 ms] | 2.87M [2.77M – 3.03M] | 12.5 MiB | 4.4 MiB |
| ToFlatList | category-1k x100 | 33.8 ms [32.8 ms – 35.9 ms] | 2.96M [2.79M – 3.05M] | 2.0 MiB | 426 KiB |
| ToFlatList | category-100k | 40.4 ms [37.9 ms – 43.7 ms] | 2.48M [2.29M – 2.64M] | 119.2 MiB | 39.9 MiB |
| KeepAncestorsToNestedArray | balanced-37k | 20.1 ms [19.1 ms – 21.6 ms] | 1.86M [1.73M – 1.96M] | 36.9 MiB | 6.6 MiB |
| KeepAncestorsToNestedArray | balanced-300k | 178.2 ms [170.7 ms – 188.0 ms] | 1.68M [1.59M – 1.76M] | 294.1 MiB | 52.6 MiB |
| KeepAncestorsToNestedArray | wide-1k x100 | 50.7 ms [48.9 ms – 52.9 ms] | 1.97M [1.89M – 2.05M] | 1.7 MiB | 130 KiB |
| KeepAncestorsToNestedArray | wide-100k | 53.0 ms [51.6 ms – 56.3 ms] | 1.89M [1.78M – 1.94M] | 85.8 MiB | 8.9 MiB |
| KeepAncestorsToNestedArray | deep-10k | 8.0 ms [7.4 ms – 8.8 ms] | 1.25M [1.14M – 1.35M] | 18.3 MiB | 10.2 MiB |
| KeepAncestorsToNestedArray | category-1k x100 | 56.8 ms [56.1 ms – 59.5 ms] | 1.76M [1.68M – 1.78M] | 1.8 MiB | 228 KiB |
| KeepAncestorsToNestedArray | category-100k | 64.8 ms [62.2 ms – 68.0 ms] | 1.54M [1.47M – 1.61M] | 99.1 MiB | 19.7 MiB |

### Phase0AncestorsBench

| subject | set | time | nodes/s | mem_peak | own peak |
|---|---|---|---|---|---|
| LibraryPrune | balanced-300k | 123.3 ms [110.3 ms – 136.5 ms] | 2.43M [2.19M – 2.72M] | 249.7 MiB | 8.1 MiB |
| LibraryPrune | category-100k | 46.5 ms [43.3 ms – 49.4 ms] | 2.15M [2.02M – 2.31M] | 81.5 MiB | 2.1 MiB |
| LibraryPrune | wide-100k | 52.7 ms [49.5 ms – 55.9 ms] | 1.90M [1.79M – 2.02M] | 78.9 MiB | 2.1 MiB |
| NoAncestors | balanced-300k | 104.5 ms [101.6 ms – 107.5 ms] | 2.87M [2.79M – 2.95M] | 249.6 MiB | 8.0 MiB |
| NoAncestors | category-100k | 41.0 ms [38.9 ms – 42.8 ms] | 2.44M [2.33M – 2.57M] | 81.4 MiB | 2.0 MiB |
| NoAncestors | wide-100k | 48.9 ms [45.8 ms – 52.9 ms] | 2.05M [1.89M – 2.18M] | 78.9 MiB | 2.0 MiB |
| NoAncestorsDirectClosure | balanced-300k | 105.5 ms [94.8 ms – 118.0 ms] | 2.84M [2.54M – 3.16M] | 249.6 MiB | 8.0 MiB |
| NoAncestorsDirectClosure | category-100k | 39.0 ms [37.2 ms – 41.7 ms] | 2.56M [2.40M – 2.69M] | 81.4 MiB | 2.0 MiB |
| NoAncestorsDirectClosure | wide-100k | 47.7 ms [46.1 ms – 50.5 ms] | 2.10M [1.98M – 2.17M] | 78.9 MiB | 2.0 MiB |
| ListSourceLibraryPrune | balanced-300k | 79.5 ms [74.7 ms – 83.5 ms] | 3.77M [3.59M – 4.01M] | 241.6 MiB | 110 KiB |
| ListSourceLibraryPrune | category-100k | 30.0 ms [29.4 ms – 32.2 ms] | 3.34M [3.10M – 3.40M] | 79.4 MiB | 110 KiB |
| ListSourceLibraryPrune | wide-100k | 36.2 ms [34.9 ms – 38.1 ms] | 2.76M [2.62M – 2.86M] | 76.9 MiB | 110 KiB |
| ListSourceNoAncestors | balanced-300k | 70.6 ms [68.5 ms – 72.8 ms] | 4.24M [4.11M – 4.37M] | 241.6 MiB | 109 KiB |
| ListSourceNoAncestors | category-100k | 27.4 ms [26.3 ms – 28.4 ms] | 3.65M [3.52M – 3.81M] | 79.4 MiB | 109 KiB |
| ListSourceNoAncestors | wide-100k | 32.4 ms [31.4 ms – 33.9 ms] | 3.08M [2.95M – 3.19M] | 76.9 MiB | 109 KiB |
| ListSourceNoAncestorsDirectClosure | balanced-300k | 65.9 ms [64.8 ms – 69.6 ms] | 4.55M [4.31M – 4.62M] | 241.6 MiB | 109 KiB |
| ListSourceNoAncestorsDirectClosure | category-100k | 25.7 ms [24.7 ms – 27.0 ms] | 3.89M [3.70M – 4.04M] | 79.4 MiB | 109 KiB |
| ListSourceNoAncestorsDirectClosure | wide-100k | 30.1 ms [28.7 ms – 31.6 ms] | 3.32M [3.17M – 3.48M] | 76.9 MiB | 109 KiB |

### NaiveBaselineBench

| subject | set | time | nodes/s | mem_peak | own peak |
|---|---|---|---|---|---|
| NaiveAdjacencyKeepAncestors | balanced-37k | 15.4 ms [14.5 ms – 16.5 ms] | 2.43M [2.27M – 2.58M] | 61.7 MiB | 31.4 MiB |
| NaiveAdjacencyKeepAncestors | balanced-300k | 131.5 ms [123.0 ms – 135.2 ms] | 2.28M [2.22M – 2.44M] | 492.6 MiB | 251.1 MiB |
| NaiveAdjacencyKeepAncestors | wide-1k x100 | 20.8 ms [20.1 ms – 22.4 ms] | 4.81M [4.47M – 4.99M] | 2.3 MiB | 791 KiB |
| NaiveAdjacencyKeepAncestors | wide-100k | 32.3 ms [31.6 ms – 34.3 ms] | 3.09M [2.91M – 3.16M] | 152.1 MiB | 75.3 MiB |
| NaiveAdjacencyKeepAncestors | deep-10k | 5.2 ms [4.6 ms – 5.9 ms] | 1.91M [1.71M – 2.17M] | 25.4 MiB | 17.4 MiB |
| NaiveAdjacencyKeepAncestors | category-1k x100 | 28.7 ms [27.6 ms – 29.3 ms] | 3.48M [3.41M – 3.62M] | 2.5 MiB | 973 KiB |
| NaiveAdjacencyKeepAncestors | category-100k | 57.1 ms [53.5 ms – 62.6 ms] | 1.75M [1.60M – 1.87M] | 171.9 MiB | 92.6 MiB |
| LibraryAdjacencyKeepAncestors | balanced-37k | 23.2 ms [22.3 ms – 24.4 ms] | 1.61M [1.53M – 1.68M] | 38.2 MiB | 7.9 MiB |
| LibraryAdjacencyKeepAncestors | balanced-300k | 195.8 ms [189.3 ms – 203.2 ms] | 1.53M [1.47M – 1.58M] | 304.3 MiB | 62.8 MiB |
| LibraryAdjacencyKeepAncestors | wide-1k x100 | 46.3 ms [43.4 ms – 47.3 ms] | 2.16M [2.12M – 2.30M] | 1.8 MiB | 180 KiB |
| LibraryAdjacencyKeepAncestors | wide-100k | 56.4 ms [53.0 ms – 60.2 ms] | 1.77M [1.66M – 1.89M] | 88.6 MiB | 11.8 MiB |
| LibraryAdjacencyKeepAncestors | deep-10k | 9.8 ms [9.0 ms – 10.2 ms] | 1.03M [981k – 1.11M] | 20.9 MiB | 12.8 MiB |
| LibraryAdjacencyKeepAncestors | category-1k x100 | 58.9 ms [57.8 ms – 61.5 ms] | 1.70M [1.63M – 1.73M] | 1.9 MiB | 336 KiB |
| LibraryAdjacencyKeepAncestors | category-100k | 76.7 ms [73.8 ms – 79.1 ms] | 1.30M [1.26M – 1.36M] | 110.1 MiB | 30.7 MiB |
| NaiveNestedSetKeepAncestors | balanced-37k | 17.1 ms [16.1 ms – 18.0 ms] | 2.19M [2.08M – 2.33M] | 62.8 MiB | 32.5 MiB |
| NaiveNestedSetKeepAncestors | balanced-300k | 157.1 ms [138.1 ms – 179.8 ms] | 1.91M [1.67M – 2.17M] | 501.8 MiB | 260.3 MiB |
| NaiveNestedSetKeepAncestors | wide-1k x100 | 31.5 ms [28.8 ms – 33.7 ms] | 3.17M [2.97M – 3.48M] | 2.3 MiB | 822 KiB |
| NaiveNestedSetKeepAncestors | wide-100k | 39.7 ms [36.5 ms – 43.2 ms] | 2.52M [2.31M – 2.74M] | 155.1 MiB | 78.3 MiB |
| NaiveNestedSetKeepAncestors | deep-10k | 4.8 ms [4.3 ms – 5.4 ms] | 2.10M [1.85M – 2.34M] | 25.7 MiB | 17.7 MiB |
| NaiveNestedSetKeepAncestors | category-1k x100 | 35.7 ms [33.6 ms – 36.7 ms] | 2.80M [2.73M – 2.98M] | 2.5 MiB | 1004 KiB |
| NaiveNestedSetKeepAncestors | category-100k | 61.1 ms [56.7 ms – 68.6 ms] | 1.64M [1.46M – 1.76M] | 175.0 MiB | 95.7 MiB |
| LibraryNestedSetKeepAncestors | balanced-37k | 20.2 ms [19.3 ms – 22.2 ms] | 1.85M [1.69M – 1.95M] | 36.9 MiB | 6.6 MiB |
| LibraryNestedSetKeepAncestors | balanced-300k | 168.7 ms [158.1 ms – 183.6 ms] | 1.78M [1.63M – 1.90M] | 294.1 MiB | 52.6 MiB |
| LibraryNestedSetKeepAncestors | wide-1k x100 | 47.3 ms [44.1 ms – 51.5 ms] | 2.11M [1.94M – 2.27M] | 1.7 MiB | 130 KiB |
| LibraryNestedSetKeepAncestors | wide-100k | 50.3 ms [47.1 ms – 55.5 ms] | 1.99M [1.80M – 2.12M] | 85.8 MiB | 8.9 MiB |
| LibraryNestedSetKeepAncestors | deep-10k | 8.2 ms [7.0 ms – 9.2 ms] | 1.22M [1.08M – 1.42M] | 18.3 MiB | 10.2 MiB |
| LibraryNestedSetKeepAncestors | category-1k x100 | 50.7 ms [49.3 ms – 52.5 ms] | 1.97M [1.90M – 2.03M] | 1.8 MiB | 228 KiB |
| LibraryNestedSetKeepAncestors | category-100k | 57.1 ms [55.0 ms – 59.3 ms] | 1.75M [1.69M – 1.82M] | 99.1 MiB | 19.7 MiB |
| NaiveAdjacencyPrune | balanced-37k | 13.9 ms [13.1 ms – 14.4 ms] | 2.69M [2.59M – 2.85M] | 71.7 MiB | 41.4 MiB |
| NaiveAdjacencyPrune | balanced-300k | 115.9 ms [111.9 ms – 121.9 ms] | 2.58M [2.46M – 2.68M] | 571.8 MiB | 330.3 MiB |
| NaiveAdjacencyPrune | wide-1k x100 | 20.5 ms [20.0 ms – 22.2 ms] | 4.87M [4.50M – 5.00M] | 2.8 MiB | 1.3 MiB |
| NaiveAdjacencyPrune | wide-100k | 37.8 ms [36.4 ms – 41.0 ms] | 2.64M [2.44M – 2.75M] | 206.9 MiB | 130.1 MiB |
| NaiveAdjacencyPrune | deep-10k | 2.4 ms [2.4 ms – 2.5 ms] | 4.10M [3.94M – 4.20M] | 19.1 MiB | 11.0 MiB |
| NaiveAdjacencyPrune | category-1k x100 | 26.5 ms [26.0 ms – 27.2 ms] | 3.77M [3.68M – 3.84M] | 2.9 MiB | 1.3 MiB |
| NaiveAdjacencyPrune | category-100k | 54.1 ms [52.3 ms – 58.1 ms] | 1.85M [1.72M – 1.91M] | 208.4 MiB | 129.1 MiB |
| LibraryAdjacencyPrune | balanced-37k | 22.9 ms [21.8 ms – 24.0 ms] | 1.64M [1.56M – 1.72M] | 48.2 MiB | 17.9 MiB |
| LibraryAdjacencyPrune | balanced-300k | 189.1 ms [180.8 ms – 200.8 ms] | 1.58M [1.49M – 1.66M] | 383.5 MiB | 141.9 MiB |
| LibraryAdjacencyPrune | wide-1k x100 | 62.5 ms [58.7 ms – 64.3 ms] | 1.60M [1.56M – 1.71M] | 2.3 MiB | 692 KiB |
| LibraryAdjacencyPrune | wide-100k | 76.3 ms [73.3 ms – 77.6 ms] | 1.31M [1.29M – 1.36M] | 142.6 MiB | 65.8 MiB |
| LibraryAdjacencyPrune | deep-10k | 2.7 ms [2.6 ms – 2.7 ms] | 3.71M [3.65M – 3.83M] | 11.4 MiB | 3.3 MiB |
| LibraryAdjacencyPrune | category-1k x100 | 63.2 ms [62.7 ms – 64.4 ms] | 1.58M [1.55M – 1.59M] | 2.3 MiB | 728 KiB |
| LibraryAdjacencyPrune | category-100k | 80.8 ms [78.5 ms – 83.7 ms] | 1.24M [1.19M – 1.27M] | 146.6 MiB | 67.2 MiB |
| LibraryNestedSetPrune | balanced-37k | 19.2 ms [18.0 ms – 20.4 ms] | 1.95M [1.83M – 2.08M] | 46.9 MiB | 16.6 MiB |
| LibraryNestedSetPrune | balanced-300k | 159.9 ms [154.1 ms – 167.1 ms] | 1.87M [1.79M – 1.94M] | 373.3 MiB | 131.7 MiB |
| LibraryNestedSetPrune | wide-1k x100 | 61.1 ms [59.3 ms – 64.1 ms] | 1.64M [1.56M – 1.69M] | 2.2 MiB | 673 KiB |
| LibraryNestedSetPrune | wide-100k | 72.2 ms [70.7 ms – 76.0 ms] | 1.38M [1.32M – 1.41M] | 140.6 MiB | 63.8 MiB |
| LibraryNestedSetPrune | deep-10k | 1.0 ms [970 µs – 1.1 ms] | 9.79M [9.47M – 10.30M] | 8.4 MiB | 359 KiB |
| LibraryNestedSetPrune | category-1k x100 | 56.8 ms [55.7 ms – 60.6 ms] | 1.76M [1.65M – 1.80M] | 2.2 MiB | 620 KiB |
| LibraryNestedSetPrune | category-100k | 64.8 ms [62.3 ms – 67.4 ms] | 1.54M [1.48M – 1.60M] | 135.6 MiB | 56.2 MiB |

### StreamingBench

| subject | set | time | nodes/s | mem_peak | own peak |
|---|---|---|---|---|---|
| GeneratorOnly | 11,111 | 4.1 ms [4.0 ms – 4.4 ms] | 2.71M [2.53M – 2.79M] | 972 KiB | 193 KiB |
| GeneratorOnly | 111,111 | 36.2 ms [35.3 ms – 40.2 ms] | 3.07M [2.76M – 3.15M] | 972 KiB | 193 KiB |
| GeneratorOnly | 1,111,111 | 359.6 ms [349.7 ms – 369.8 ms] | 3.09M [3.00M – 3.18M] | 972 KiB | 193 KiB |
| NestedSet | 11,111 | 9.3 ms [8.6 ms – 9.8 ms] | 1.20M [1.13M – 1.29M] | 1.0 MiB | 194 KiB |
| NestedSet | 111,111 | 76.4 ms [72.3 ms – 80.2 ms] | 1.45M [1.38M – 1.54M] | 1.0 MiB | 194 KiB |
| NestedSet | 1,111,111 | 749.5 ms [708.9 ms – 805.1 ms] | 1.48M [1.38M – 1.57M] | 1.0 MiB | 194 KiB |
| PreorderedAdjacency | 11,111 | 8.2 ms [7.9 ms – 8.9 ms] | 1.35M [1.24M – 1.40M] | 1.0 MiB | 194 KiB |
| PreorderedAdjacency | 111,111 | 73.0 ms [68.7 ms – 77.1 ms] | 1.52M [1.44M – 1.62M] | 1.0 MiB | 194 KiB |
| PreorderedAdjacency | 1,111,111 | 683.3 ms [664.0 ms – 727.5 ms] | 1.63M [1.53M – 1.67M] | 1.0 MiB | 194 KiB |
| MaterializedPath | 11,111 | 9.4 ms [9.2 ms – 9.6 ms] | 1.19M [1.16M – 1.21M] | 1.0 MiB | 194 KiB |
| MaterializedPath | 111,111 | 81.5 ms [76.6 ms – 86.8 ms] | 1.36M [1.28M – 1.45M] | 1.0 MiB | 194 KiB |
| MaterializedPath | 1,111,111 | 817.4 ms [785.7 ms – 845.0 ms] | 1.36M [1.31M – 1.41M] | 1.0 MiB | 194 KiB |
| NestedSetPrune | 11,111 | 12.0 ms [11.3 ms – 12.9 ms] | 923k [862k – 979k] | 1.0 MiB | 195 KiB |
| NestedSetPrune | 111,111 | 100.0 ms [96.5 ms – 104.1 ms] | 1.11M [1.07M – 1.15M] | 1.0 MiB | 195 KiB |
| NestedSetPrune | 1,111,111 | 902.0 ms [851.2 ms – 948.5 ms] | 1.23M [1.17M – 1.31M] | 1.0 MiB | 195 KiB |
| NestedSetKeepAncestors | 11,111 | 11.2 ms [11.0 ms – 11.4 ms] | 995k [971k – 1.01M] | 1.0 MiB | 195 KiB |
| NestedSetKeepAncestors | 111,111 | 89.7 ms [84.8 ms – 97.6 ms] | 1.24M [1.14M – 1.31M] | 1.0 MiB | 195 KiB |
| NestedSetKeepAncestors | 1,111,111 | 903.0 ms [865.9 ms – 937.2 ms] | 1.23M [1.19M – 1.28M] | 1.0 MiB | 195 KiB |
| NestedSetPromote | 11,111 | 10.9 ms [10.5 ms – 11.5 ms] | 1.02M [970k – 1.06M] | 1.0 MiB | 195 KiB |
| NestedSetPromote | 111,111 | 93.8 ms [87.2 ms – 97.8 ms] | 1.18M [1.14M – 1.27M] | 1.0 MiB | 195 KiB |
| NestedSetPromote | 1,111,111 | 925.1 ms [856.9 ms – 975.6 ms] | 1.20M [1.14M – 1.30M] | 1.0 MiB | 195 KiB |

### StrategyBench

| subject | set | time | nodes/s | mem_peak | own peak |
|---|---|---|---|---|---|
| NoFilter | balanced-37k | 10.4 ms [10.0 ms – 10.7 ms] | 3.61M [3.49M – 3.73M] | 31.4 MiB | 1.0 MiB |
| NoFilter | balanced-300k | 83.7 ms [81.0 ms – 88.4 ms] | 3.58M [3.39M – 3.70M] | 249.6 MiB | 8.0 MiB |
| NoFilter | wide-1k x100 | 25.3 ms [24.2 ms – 26.6 ms] | 3.95M [3.76M – 4.14M] | 1.7 MiB | 119 KiB |
| NoFilter | wide-100k | 27.7 ms [26.5 ms – 29.1 ms] | 3.62M [3.44M – 3.77M] | 78.9 MiB | 2.0 MiB |
| NoFilter | deep-10k | 2.5 ms [2.4 ms – 2.7 ms] | 4.06M [3.76M – 4.17M] | 8.6 MiB | 538 KiB |
| NoFilter | category-1k x100 | 27.7 ms [26.0 ms – 29.0 ms] | 3.61M [3.45M – 3.84M] | 1.7 MiB | 119 KiB |
| NoFilter | category-100k | 29.5 ms [28.6 ms – 30.7 ms] | 3.39M [3.26M – 3.50M] | 81.4 MiB | 2.0 MiB |
| PruneVisible | balanced-37k | 12.4 ms [12.1 ms – 12.9 ms] | 3.01M [2.91M – 3.09M] | 31.5 MiB | 1.1 MiB |
| PruneVisible | balanced-300k | 103.7 ms [100.4 ms – 107.6 ms] | 2.89M [2.78M – 2.98M] | 249.7 MiB | 8.1 MiB |
| PruneVisible | wide-1k x100 | 42.0 ms [41.4 ms – 44.6 ms] | 2.38M [2.24M – 2.42M] | 1.7 MiB | 119 KiB |
| PruneVisible | wide-100k | 47.3 ms [45.4 ms – 49.7 ms] | 2.11M [2.01M – 2.20M] | 78.9 MiB | 2.1 MiB |
| PruneVisible | deep-10k | 1.0 ms [1.0 ms – 1.1 ms] | 9.65M [9.30M – 9.90M] | 8.4 MiB | 359 KiB |
| PruneVisible | category-1k x100 | 40.2 ms [36.7 ms – 43.5 ms] | 2.49M [2.30M – 2.73M] | 1.7 MiB | 119 KiB |
| PruneVisible | category-100k | 38.7 ms [38.1 ms – 40.7 ms] | 2.59M [2.46M – 2.62M] | 81.5 MiB | 2.1 MiB |
| PruneHit | balanced-37k | 4.1 ms [4.0 ms – 4.2 ms] | 9.13M [8.90M – 9.47M] | 31.5 MiB | 1.1 MiB |
| PruneHit | balanced-300k | 38.0 ms [35.9 ms – 39.7 ms] | 7.88M [7.54M – 8.33M] | 249.7 MiB | 8.1 MiB |
| PruneHit | wide-1k x100 | 10.3 ms [10.0 ms – 10.8 ms] | 9.75M [9.28M – 10.02M] | 1.7 MiB | 119 KiB |
| PruneHit | wide-100k | 12.4 ms [11.5 ms – 13.7 ms] | 8.03M [7.31M – 8.69M] | 78.9 MiB | 2.1 MiB |
| PruneHit | deep-10k | 1.0 ms [1.0 ms – 1.1 ms] | 9.53M [9.21M – 9.89M] | 8.4 MiB | 359 KiB |
| PruneHit | category-1k x100 | 10.9 ms [10.2 ms – 11.9 ms] | 9.21M [8.38M – 9.84M] | 1.7 MiB | 119 KiB |
| PruneHit | category-100k | 12.3 ms [11.5 ms – 13.0 ms] | 8.15M [7.69M – 8.71M] | 81.5 MiB | 2.1 MiB |
| KeepAncestors | balanced-37k | 18.2 ms [16.9 ms – 19.6 ms] | 2.06M [1.91M – 2.21M] | 31.5 MiB | 1.1 MiB |
| KeepAncestors | balanced-300k | 139.4 ms [136.5 ms – 147.4 ms] | 2.15M [2.03M – 2.20M] | 249.7 MiB | 8.1 MiB |
| KeepAncestors | wide-1k x100 | 43.6 ms [41.3 ms – 47.4 ms] | 2.30M [2.11M – 2.42M] | 1.7 MiB | 130 KiB |
| KeepAncestors | wide-100k | 46.3 ms [44.4 ms – 47.5 ms] | 2.16M [2.11M – 2.25M] | 78.9 MiB | 2.1 MiB |
| KeepAncestors | deep-10k | 5.2 ms [4.7 ms – 5.6 ms] | 1.93M [1.79M – 2.13M] | 9.1 MiB | 1.0 MiB |
| KeepAncestors | category-1k x100 | 47.4 ms [44.2 ms – 50.6 ms] | 2.11M [1.98M – 2.26M] | 1.7 MiB | 130 KiB |
| KeepAncestors | category-100k | 48.3 ms [47.3 ms – 49.8 ms] | 2.07M [2.01M – 2.11M] | 81.5 MiB | 2.1 MiB |
| Promote | balanced-37k | 16.1 ms [15.7 ms – 16.8 ms] | 2.33M [2.22M – 2.38M] | 31.5 MiB | 1.1 MiB |
| Promote | balanced-300k | 134.9 ms [129.4 ms – 138.9 ms] | 2.22M [2.16M – 2.32M] | 249.7 MiB | 8.1 MiB |
| Promote | wide-1k x100 | 40.2 ms [39.2 ms – 41.7 ms] | 2.49M [2.40M – 2.55M] | 1.7 MiB | 119 KiB |
| Promote | wide-100k | 43.2 ms [41.9 ms – 46.3 ms] | 2.31M [2.16M – 2.39M] | 78.9 MiB | 2.1 MiB |
| Promote | deep-10k | 4.3 ms [4.0 ms – 4.7 ms] | 2.31M [2.13M – 2.51M] | 8.9 MiB | 819 KiB |
| Promote | category-1k x100 | 44.0 ms [42.2 ms – 47.1 ms] | 2.27M [2.12M – 2.37M] | 1.7 MiB | 119 KiB |
| Promote | category-100k | 45.2 ms [44.2 ms – 47.1 ms] | 2.21M [2.12M – 2.26M] | 81.5 MiB | 2.1 MiB |
| Annotate | balanced-37k | 36.8 ms [35.7 ms – 37.9 ms] | 1.02M [988k – 1.05M] | 31.5 MiB | 1.1 MiB |
| Annotate | balanced-300k | 268.4 ms [253.9 ms – 276.9 ms] | 1.12M [1.08M – 1.18M] | 249.7 MiB | 8.1 MiB |
| Annotate | wide-1k x100 | 86.7 ms [83.4 ms – 88.8 ms] | 1.15M [1.13M – 1.20M] | 1.7 MiB | 133 KiB |
| Annotate | wide-100k | 89.8 ms [84.8 ms – 94.9 ms] | 1.11M [1.05M – 1.18M] | 78.9 MiB | 2.1 MiB |
| Annotate | deep-10k | 8.1 ms [7.9 ms – 8.2 ms] | 1.24M [1.22M – 1.27M] | 9.6 MiB | 1.5 MiB |
| Annotate | category-1k x100 | 84.6 ms [81.7 ms – 90.0 ms] | 1.18M [1.11M – 1.22M] | 1.7 MiB | 133 KiB |
| Annotate | category-100k | 92.9 ms [88.1 ms – 100.0 ms] | 1.08M [1.00M – 1.13M] | 81.5 MiB | 2.1 MiB |
| ActiveTrailHit | balanced-37k | 27.4 ms [24.9 ms – 28.9 ms] | 1.37M [1.30M – 1.51M] | 31.5 MiB | 1.1 MiB |
| ActiveTrailHit | balanced-300k | 217.1 ms [211.5 ms – 228.5 ms] | 1.38M [1.31M – 1.42M] | 249.7 MiB | 8.1 MiB |
| ActiveTrailHit | wide-1k x100 | 64.0 ms [61.4 ms – 67.2 ms] | 1.56M [1.49M – 1.63M] | 1.7 MiB | 143 KiB |
| ActiveTrailHit | wide-100k | 69.4 ms [63.8 ms – 76.2 ms] | 1.44M [1.31M – 1.57M] | 79.0 MiB | 2.1 MiB |
| ActiveTrailHit | deep-10k | 8.2 ms [7.8 ms – 8.4 ms] | 1.22M [1.19M – 1.28M] | 9.7 MiB | 1.6 MiB |
| ActiveTrailHit | category-1k x100 | 69.5 ms [66.1 ms – 72.8 ms] | 1.44M [1.37M – 1.51M] | 1.7 MiB | 143 KiB |
| ActiveTrailHit | category-100k | 72.4 ms [67.3 ms – 76.4 ms] | 1.38M [1.31M – 1.49M] | 81.5 MiB | 2.1 MiB |
| ActiveTrailSingle | balanced-37k | 22.5 ms [21.0 ms – 23.5 ms] | 1.66M [1.59M – 1.79M] | 31.5 MiB | 1.1 MiB |
| ActiveTrailSingle | balanced-300k | 165.5 ms [163.4 ms – 171.9 ms] | 1.81M [1.74M – 1.83M] | 249.7 MiB | 8.1 MiB |
| ActiveTrailSingle | wide-1k x100 | 66.8 ms [63.2 ms – 70.8 ms] | 1.50M [1.41M – 1.58M] | 1.7 MiB | 143 KiB |
| ActiveTrailSingle | wide-100k | 69.2 ms [67.3 ms – 73.2 ms] | 1.45M [1.37M – 1.49M] | 84.9 MiB | 8.0 MiB |
| ActiveTrailSingle | deep-10k | 7.3 ms [6.8 ms – 7.9 ms] | 1.37M [1.27M – 1.47M] | 11.7 MiB | 3.6 MiB |
| ActiveTrailSingle | category-1k x100 | 56.6 ms [53.9 ms – 58.3 ms] | 1.77M [1.72M – 1.85M] | 1.7 MiB | 143 KiB |
| ActiveTrailSingle | category-100k | 59.6 ms [58.3 ms – 64.0 ms] | 1.68M [1.56M – 1.72M] | 81.5 MiB | 2.1 MiB |

