# Benchmarks

The benchmark report for 0.3.0 (the API 1.0 is meant to freeze): speed and memory of every source, strategy and
sink, a comparison with the usual "build the whole tree, then filter it" code, and the targets from
[SPEC §10](SPEC.md#10-performance) with a verdict each. How the model works and how to make a pipeline cheap is in
[Performance](performance.md); this page is only measurements. The historical log of how the implementation got
here (prototypes, rejected ideas) is [`benchmarks/RESULTS.md`](../benchmarks/RESULTS.md).

## Summary

Medians, PHP 8.4, opcache on. "JIT off" is the default configuration of PHP; "JIT on" is `opcache.jit=tracing`.
Balanced-300k is a balanced tree of 299,593 nodes, category-100k a random shop tree of 100,000 nodes. Every number
has a range and a variance in the [full matrix](#full-matrix).

| Operation (nested set rows in memory) | balanced-300k, JIT off | category-100k, JIT off | balanced-300k, JIT on | category-100k, JIT on |
|---|---|---|---|---|
| read, `count()` | 28.3 ms (10.6M nodes/s) | 9.9 ms | 18.4 ms (16.3M nodes/s) | 6.9 ms |
| `Prune`, about 90 % kept | 40.4 ms (7.4M nodes/s) | 16.3 ms | 22.9 ms (13.1M nodes/s) | 9.7 ms |
| `Prune`, about 10 % kept | 10.2 ms (29.3M nodes/s) | 3.3 ms | 8.1 ms (36.9M nodes/s) | 2.8 ms |
| `KeepAncestors` | 51.3 ms | 17.9 ms | 26.4 ms | 9.5 ms |
| `Promote` | 48.5 ms | 18.6 ms | 27.1 ms | 10.4 ms |
| `Annotate` | 138.1 ms | 46.8 ms | 81.9 ms | 29.7 ms |
| `ActiveTrail`, one active node | 69.6 ms | 25.3 ms | 31.9 ms | 12.8 ms |
| `toNestedArray()`, every node | 92.4 ms | 35.6 ms | 69.8 ms | 27.4 ms |
| `KeepAncestors` → `toNestedArray()` | 68.7 ms | 24.8 ms | 44.2 ms | 17.3 ms |
| same, naive build-then-filter | 139.4 ms | 56.4 ms | 109.9 ms | 47.2 ms |

Streaming a generator of 1,111,111 rows (time includes generating the rows, about 370 ms of it, which is a
stand-in for a database cursor) through `Prune` into `iterate()`: **603 ms with the JIT off, 388 ms with it on, in
1.1 MiB of memory (2.2 MiB with the JIT, which allocates for itself), the same as at 11,111 rows.**

What to take from it:

- With the JIT off, reading a nested set costs 95 to 110 ns per node, `Prune` (90 % kept) 135 to 165 ns,
  `KeepAncestors` and `Promote` 165 to 190 ns. Building the nested array is the largest single cost of a typical pipeline.
- The JIT makes the library 1.2× to 1.75× faster on PHP 8.4 and 8.5 alike (geometric mean of the time ratio 0.57
  to 0.84, see [JIT effect](#jit-effect)); the biggest gains are on the strategies, the smallest on streaming, where
  generating the rows dominates.
- PHP 8.5 is within ±3 % of PHP 8.4 everywhere ([comparison](#php-84-compared-with-85)).

## Targets from SPEC §10

| Target | Verdict | Evidence |
|---|---|---|
| Streaming strategies: peak memory independent of n | **met** | 1.1 MiB at 11,111, 111,111 and 1,111,111 rows for every source and strategy streamed (2.2 MiB with the JIT, also flat); tests also assert under 2 MB growth over 1.1M nodes. [Table](#target-1-streaming-memory-does-not-grow-with-n) |
| ≥ 1M nodes/s for `Prune` over an array nested set | **met** | JIT off: 5.6M to 7.7M nodes/s with about 90 % kept (all shapes except the deep chain, which reaches 41M because the first hidden node cuts the chain), 29M to 47M with about 10 % kept; JIT on: 10.2M to 13.3M and 34M to 112M. All four configurations. [Table](#target-2-prune-over-an-array-nested-set-nodes-per-second) |
| Much lower memory than the naive baseline | **met** | Own peak 5.4× to 11.3× lower for nested set → `KeepAncestors` on every shape except the deep chain (1.9×), 2.9× to 6.9× for adjacency → `KeepAncestors`, 1.9× to 3.6× for adjacency → `Prune` (the nested array of the result dominates there). [Table](#targets-3-to-5-against-the-naive-build-then-filter-baseline) |
| Faster than the naive baseline for pre-ordered input (nested set → `KeepAncestors` → nested array) | **met** | 1.05× to 2.28× with the JIT off, 1.5× to 2.7× with it on, in all four configurations and on every shape; the smallest margin is the deep chain, which a naive code handles without much work. |
| ≥ 2× faster than the naive baseline | **partly met** | For nested set → `KeepAncestors` → nested array with the JIT **on**: 2.46× to 2.73× on balanced-37k, balanced-300k, wide-100k and category-100k. With the JIT **off**: 2.03× / 2.01× (balanced-300k, PHP 8.4 / 8.5), 2.04× / 1.97× (wide-100k), 2.28× / 2.14× (category-100k), but 1.93× / 1.86× on balanced-37k. **Not met** on the 1k shapes (1.43× to 1.96×) and the deep chain (1.05× to 1.70×). Adjacency lists in arbitrary order: 0.86× to 1.25× (`KeepAncestors`) and 0.57× to 0.97× (`Prune`; 1.9× to 2.0× on the deep chain) with the JIT off, no target. |

The adjacency list in arbitrary order has to build an index before it can emit the first node, so its speed is that
of the naive code (with the JIT on, `KeepAncestors` is 1.2× to 1.7× faster; `Prune` into a nested array stays at 0.7× to 1.3×). When
you control the query, order it by `lft` or by path and use the nested set or pre-ordered source.

## Environment

| | |
|---|---|
| CPU | Intel Core Ultra 7 155H (laptop, 16 cores / 22 threads, up to 4.8 GHz, 24 MiB L3), frequency governor `powersave` (the default, not pinned) |
| RAM | 62 GiB |
| OS | Ubuntu 24.04 (kernel 7.0.0-42-generic), Docker 29.9.3 (overlay2, cgroup v2), no CPU or memory limits on the container |
| Container | the repository's `docker/php/Dockerfile`: `php:8.4-cli-alpine` and `php:8.5-cli-alpine`, Alpine 3.24.3 |
| PHP | 8.4.27 and 8.5.12 (NTS, CLI), both built by the official Docker images on 2026-10-21 |
| Extensions in the benchmark processes | Core, ctype, curl, date, dom, fileinfo, filter, hash, iconv, json, libxml, mbstring, mysqlnd, openssl, pcre, PDO, pdo_sqlite, Phar, posix, random, readline, Reflection, session, SimpleXML, SPL, sqlite3, standard, tokenizer, xml, xmlreader, xmlwriter, zlib, Zend OPcache. The image also has bcmath, intl, pcov, pdo_mysql, pdo_pgsql and sodium; `benchmarks/run-report.sh` leaves them out, because pcov, even disabled, stops PHP from enabling the JIT |
| opcache | `opcache.enable_cli=1`, default `opcache.memory_consumption`; no preloading |
| JIT off | `opcache.jit=disable` |
| JIT on | `opcache.jit=tracing`, `opcache.jit_buffer_size=128M` (the other JIT settings at their defaults: `opcache.jit_hot_loop=61`, `jit_hot_func=127`, `jit_hot_return=8`, `jit_hot_side_exit=8`, `jit_max_root_traces=1024`) |
| Other settings | `zend.assertions=-1`, `memory_limit=2G`, garbage collection disabled by phpbench |
| Library | 0.3.0, commit `1932a81` (tag `v0.3.0`) |
| Benchmark tool | phpbench 1.7.0 |

The machine was otherwise idle (no other benchmarks, tests or containers running; a desktop session was open). It is
a laptop-class CPU, so absolute numbers on a server will differ; ratios between pipelines, and the memory figures,
are what carries over.

## Method

- **Harness.** phpbench, one process per iteration (a fresh PHP, so every iteration starts with a cold opcache and,
  with the JIT on, a cold JIT), `--iterations=8`, `--retry-threshold=5` (phpbench repeats a variant until its rstdev
  is under 5 %). `Revs` and warm-up come from the attributes in `benchmarks/*Bench.php`: two revolutions with one
  warm-up revolution for the in-memory benchmarks, one revolution for streaming. The configurations ran one after
  another, never in parallel, 7.6 to 8.5 minutes each (see [Reproduce](#reproduce)).
- **What is measured.** Time per revolution (phpbench `time-avg`), reported as the median of the iterations with the
  relative standard deviation (rstdev) next to it. Peak memory: `mem_peak`, which includes the fixture (the input
  rows are built before the measurement and the peak is reset afterwards), and "own peak" = `mem_peak − mem_final`,
  the working memory of the subject itself without the input (its result is freed by the time `mem_final` is read).
- **Fixtures.** `benchmarks/Support/Fixture.php` builds deterministic trees and renders them in every input format;
  all rows are PHP arrays already in memory, so the numbers are the library's cost without a database. Shapes:
  balanced (branching 8, depth 5 and 6: 37,449 and 299,593 nodes), wide (1 root with 1,000 or 100,000 children), a deep
  chain (depth 10,000, which also shows there is no recursion limit), and random category trees (1,000 and 100,000
  nodes). The 1k shapes run 100 passes per revolution (`… x100`; divide the time by 100) so that they stay well above
  timer noise.
- **Predicates.** `hit` matches about 10 % of the nodes (a search), `visible` about 90 % (hide unpublished; roots
  always visible).
- **Matrix.** Sources × shapes (`SourceBench`), strategies × shapes (`StrategyBench`, always from a nested set),
  sinks × shapes (`SinkBench`, always from a nested set), naive baseline × shapes (`NaiveBaselineBench`) and
  streaming × three sizes (`StreamingBench`). The three axes are measured separately, not as a full cross product:
  sources are compared through `count()`, strategies through `count()`, sinks with no strategy. `KeepAncestorsToNestedArray`
  and the naive pairs cover the combination that matters.
- **Naive baseline.** `benchmarks/Support/Naive.php`: build the full nested tree recursively (from adjacency rows, or from
  a nested set), then filter it recursively, with the same semantics as `KeepAncestors` or `Prune`. It is the code most
  projects write by hand. `benchmarks/verify.php` checks that every compared pair, the library and the baseline,
  produces identical output; run it before trusting any benchmark.
- **Size cut.** The in-memory shapes stop at about 300k nodes: the fixture is PHP arrays (about 240 MiB at 300k
  rows), the naive baseline needs 2× to 3× that again, and 1M-node fixtures would not fit the 2 GiB benchmark memory
  limit in every configuration. The 1.1M-node size exists where it matters, in the streaming benchmark (rows
  generated lazily, no fixture). Per-node costs are flat from 37k to 300k nodes, so the in-memory numbers extrapolate
  linearly (memory too, except for streaming, which stays constant).
- **Not measured.** A real database (query time dominates there; see [Doctrine and Gedmo](gedmo.md) for what
  the bridge pushes into SQL), and `sortSiblings()`, `MinMatches` and the other stages, which have no benchmark yet.

## Reproduce

```sh
make bench-report                                  # all four configurations, then rewrites the tables below
```

That is, for each of PHP 8.4 and 8.5 and `off` / `on`:

```sh
PHP_VERSION=8.4 docker compose run --rm php sh benchmarks/run-report.sh off var/bench-report/php8.4-jit-off.xml
PHP_VERSION=8.4 docker compose run --rm php sh benchmarks/run-report.sh on  var/bench-report/php8.4-jit-on.xml
PHP_VERSION=8.5 docker compose run --rm php sh benchmarks/run-report.sh off var/bench-report/php8.5-jit-off.xml
PHP_VERSION=8.5 docker compose run --rm php sh benchmarks/run-report.sh on  var/bench-report/php8.5-jit-on.xml
docker compose run --rm php php benchmarks/render-report.php var/bench-report docs/benchmarks.md
docker compose run --rm php php benchmarks/verify.php   # output equivalence of every compared pair
```

`benchmarks/run-report.sh` runs `vendor/bin/phpbench run` on `SourceBench`, `StrategyBench`, `SinkBench`,
`NaiveBaselineBench` and `StreamingBench` with `--iterations=8 --retry-threshold=5` and the PHP settings above, and
`benchmarks/render-report.php` turns the dumps into the tables below. Run it alone on a quiet machine; a full
`make bench-report` takes about 35 minutes. Only the block between the `results` markers is generated; the text around
it is edited by hand when the environment changes.

## Results

All tables in this section are generated from the four dumps of one run (2026-11-05). Cells read "median ±rstdev".

<!-- results:start -->

### Run quality

Relative standard deviation (rstdev) of the iterations of each variant, per configuration. Each iteration is a
fresh process; phpbench retried variants above 5 %.

| Configuration | PHP | Variants | Median rstdev | 90th percentile | Worst |
|---|---|---|---|---|---|
| PHP 8.4, JIT off | 8.4.27 | 216 | 2.3% | 3.0% | 3.8% |
| PHP 8.4, JIT on | 8.4.27 | 216 | 2.3% | 3.1% | 4.0% |
| PHP 8.5, JIT off | 8.5.12 | 216 | 2.3% | 3.1% | 3.6% |
| PHP 8.5, JIT on | 8.5.12 | 216 | 2.1% | 3.0% | 3.8% |

### Target 1: streaming memory does not grow with n

Rows come from a generator (balanced tree, branching 10), through the pipeline into `iterate()`. Peak memory of the
process (`mem_peak`, maximum over all iterations and both PHP versions), JIT off / JIT on (the JIT's compiler allocates
about 1.2 MiB of its own, independent of the tree):

| Pipeline | 11,111 nodes | 111,111 nodes | 1,111,111 nodes |
|---|---|---|---|
| NestedSet | 1.1 MiB / 2.2 MiB | 1.1 MiB / 2.1 MiB | 1.1 MiB / 2.2 MiB |
| PreorderedAdjacency | 1.1 MiB / 2.2 MiB | 1.1 MiB / 2.1 MiB | 1.1 MiB / 2.2 MiB |
| MaterializedPath | 1.1 MiB / 2.2 MiB | 1.1 MiB / 2.2 MiB | 1.1 MiB / 2.2 MiB |
| NestedSetPrune | 1.1 MiB / 2.2 MiB | 1.1 MiB / 2.2 MiB | 1.1 MiB / 2.2 MiB |
| NestedSetKeepAncestors | 1.1 MiB / 2.2 MiB | 1.1 MiB / 2.2 MiB | 1.1 MiB / 2.2 MiB |
| NestedSetPromote | 1.1 MiB / 2.2 MiB | 1.1 MiB / 2.2 MiB | 1.1 MiB / 2.2 MiB |

### Target 2: Prune over an array nested set, nodes per second

`StrategyBench`: nested set rows → `Prune` → `count()`; `Prune 90 %` keeps about 90 % of the nodes (the realistic
"hide unpublished" filter), `Prune 10 %` about 10 %. Nodes per second counts every input row. Median of the
iterations; the time table further down has the spread.

| Shape | Predicate | PHP 8.4, JIT off | PHP 8.4, JIT on | PHP 8.5, JIT off | PHP 8.5, JIT on |
|---|---|---|---|---|---|
| balanced-37k | Prune 90 % | 7.7M | 13.3M | 7.7M | 12.9M |
| balanced-37k | Prune 10 % | 32.7M | 37.7M | 34.9M | 39.3M |
| balanced-300k | Prune 90 % | 7.4M | 13.1M | 7.4M | 12.9M |
| balanced-300k | Prune 10 % | 29.3M | 36.9M | 30.8M | 36.5M |
| wide-1k x100 | Prune 90 % | 6.0M | 11.0M | 5.7M | 10.8M |
| wide-1k x100 | Prune 10 % | 47.4M | 112.3M | 44.0M | 112.5M |
| wide-100k | Prune 90 % | 5.7M | 10.6M | 5.6M | 10.4M |
| wide-100k | Prune 10 % | 32.9M | 37.8M | 33.0M | 39.3M |
| deep-10k | Prune 90 % | 41.3M | 37.2M | 41.0M | 34.8M |
| deep-10k | Prune 10 % | 38.8M | 56.3M | 42.3M | 59.4M |
| category-1k x100 | Prune 90 % | 6.4M | 10.8M | 6.7M | 11.1M |
| category-1k x100 | Prune 10 % | 44.3M | 103.1M | 43.0M | 105.5M |
| category-100k | Prune 90 % | 6.1M | 10.3M | 6.3M | 10.2M |
| category-100k | Prune 10 % | 29.9M | 35.8M | 31.6M | 33.9M |

### Targets 3 to 5: against the naive build-then-filter baseline

`NaiveBaselineBench`: same input rows, same output (checked by `benchmarks/verify.php`). Speed-up is naive time ÷
library time (above 1 means the library is faster); memory ratio is naive ÷ library own peak (`mem_peak − mem_final`).

**Speed-up**

| Pipeline | Shape | PHP 8.4, JIT off | PHP 8.4, JIT on | PHP 8.5, JIT off | PHP 8.5, JIT on |
|---|---|---|---|---|---|
| Nested set → `KeepAncestors` → nested array | balanced-37k | 1.93× | 2.60× | 1.86× | 2.57× |
| Nested set → `KeepAncestors` → nested array | balanced-300k | 2.03× | 2.49× | 2.01× | 2.46× |
| Nested set → `KeepAncestors` → nested array | wide-1k x100 | 1.48× | 1.84× | 1.54× | 1.96× |
| Nested set → `KeepAncestors` → nested array | wide-100k | 2.04× | 2.55× | 1.97× | 2.57× |
| Nested set → `KeepAncestors` → nested array | deep-10k | 1.08× | 1.70× | 1.05× | 1.49× |
| Nested set → `KeepAncestors` → nested array | category-1k x100 | 1.49× | 1.79× | 1.43× | 1.91× |
| Nested set → `KeepAncestors` → nested array | category-100k | 2.28× | 2.73× | 2.14× | 2.56× |
| Adjacency list (any order) → `KeepAncestors` → nested array | balanced-37k | 1.15× | 1.69× | 1.13× | 1.61× |
| Adjacency list (any order) → `KeepAncestors` → nested array | balanced-300k | 1.12× | 1.46× | 1.10× | 1.41× |
| Adjacency list (any order) → `KeepAncestors` → nested array | wide-1k x100 | 0.98× | 1.23× | 0.90× | 1.25× |
| Adjacency list (any order) → `KeepAncestors` → nested array | wide-100k | 1.01× | 1.52× | 1.03× | 1.35× |
| Adjacency list (any order) → `KeepAncestors` → nested array | deep-10k | 0.86× | 1.49× | 0.89× | 1.32× |
| Adjacency list (any order) → `KeepAncestors` → nested array | category-1k x100 | 1.00× | 1.26× | 0.97× | 1.31× |
| Adjacency list (any order) → `KeepAncestors` → nested array | category-100k | 1.24× | 1.57× | 1.25× | 1.46× |
| Adjacency list (any order) → `Prune` → nested array | balanced-37k | 0.87× | 1.27× | 0.94× | 1.27× |
| Adjacency list (any order) → `Prune` → nested array | balanced-300k | 0.91× | 1.18× | 0.93× | 1.19× |
| Adjacency list (any order) → `Prune` → nested array | wide-1k x100 | 0.62× | 0.74× | 0.57× | 0.71× |
| Adjacency list (any order) → `Prune` → nested array | wide-100k | 0.75× | 1.04× | 0.71× | 1.01× |
| Adjacency list (any order) → `Prune` → nested array | deep-10k | 1.92× | 3.15× | 2.02× | 2.58× |
| Adjacency list (any order) → `Prune` → nested array | category-1k x100 | 0.77× | 0.93× | 0.67× | 0.87× |
| Adjacency list (any order) → `Prune` → nested array | category-100k | 0.97× | 1.26× | 0.96× | 1.29× |

**Memory** (naive own peak / library own peak, PHP and JIT make no difference; shown for PHP 8.4, JIT off)

| Pipeline | Shape | Naive | Library | Ratio |
|---|---|---|---|---|
| Nested set → `KeepAncestors` → nested array | balanced-37k | 32.5 MiB | 5.6 MiB | 5.8× |
| Nested set → `KeepAncestors` → nested array | balanced-300k | 260.3 MiB | 44.6 MiB | 5.8× |
| Nested set → `KeepAncestors` → nested array | wide-1k x100 | 822 KiB | 107 KiB | 7.7× |
| Nested set → `KeepAncestors` → nested array | wide-100k | 78.3 MiB | 6.9 MiB | 11.3× |
| Nested set → `KeepAncestors` → nested array | deep-10k | 17.7 MiB | 9.2 MiB | 1.9× |
| Nested set → `KeepAncestors` → nested array | category-1k x100 | 1004 KiB | 207 KiB | 4.8× |
| Nested set → `KeepAncestors` → nested array | category-100k | 95.7 MiB | 17.7 MiB | 5.4× |
| Adjacency list (any order) → `KeepAncestors` → nested array | balanced-37k | 31.4 MiB | 7.9 MiB | 4.0× |
| Adjacency list (any order) → `KeepAncestors` → nested array | balanced-300k | 251.1 MiB | 62.8 MiB | 4.0× |
| Adjacency list (any order) → `KeepAncestors` → nested array | wide-1k x100 | 791 KiB | 150 KiB | 5.3× |
| Adjacency list (any order) → `KeepAncestors` → nested array | wide-100k | 75.3 MiB | 10.9 MiB | 6.9× |
| Adjacency list (any order) → `KeepAncestors` → nested array | deep-10k | 17.4 MiB | 9.2 MiB | 1.9× |
| Adjacency list (any order) → `KeepAncestors` → nested array | category-1k x100 | 973 KiB | 335 KiB | 2.9× |
| Adjacency list (any order) → `KeepAncestors` → nested array | category-100k | 92.6 MiB | 30.7 MiB | 3.0× |
| Adjacency list (any order) → `Prune` → nested array | balanced-37k | 41.4 MiB | 17.9 MiB | 2.3× |
| Adjacency list (any order) → `Prune` → nested array | balanced-300k | 330.3 MiB | 141.9 MiB | 2.3× |
| Adjacency list (any order) → `Prune` → nested array | wide-1k x100 | 1.3 MiB | 691 KiB | 2.0× |
| Adjacency list (any order) → `Prune` → nested array | wide-100k | 130.1 MiB | 65.8 MiB | 2.0× |
| Adjacency list (any order) → `Prune` → nested array | deep-10k | 11.0 MiB | 3.1 MiB | 3.6× |
| Adjacency list (any order) → `Prune` → nested array | category-1k x100 | 1.3 MiB | 727 KiB | 1.9× |
| Adjacency list (any order) → `Prune` → nested array | category-100k | 129.1 MiB | 67.2 MiB | 1.9× |

### JIT effect

Median time with the JIT on ÷ median time with it off, per class, as the geometric mean over all variants of
at least 1 ms (below that, timer noise dominates). Below 1.00 the JIT helps.

| PHP | Source | Strategy | Sink | Naive baseline | Streaming |
|---|---|---|---|---|---|
| PHP 8.4 | 0.62 | 0.57 | 0.68 | 0.74 | 0.84 |
| PHP 8.5 | 0.62 | 0.58 | 0.67 | 0.72 | 0.80 |

### PHP 8.4 compared with 8.5

Median time on 8.5 ÷ median time on 8.4 (geometric mean over variants of at least 1 ms). Below 1.00 means 8.5 is faster.

| JIT | Source | Strategy | Sink | Naive baseline | Streaming |
|---|---|---|---|---|---|
| JIT off | 1.00 | 0.99 | 1.01 | 1.02 | 1.02 |
| JIT on | 1.01 | 1.01 | 0.99 | 0.99 | 0.97 |

## Full matrix

Time per revolution, "median ±rstdev". Variants named `… x100` run 100 passes per revolution (divide by 100 for one
pass). `nodes/s` and the memory columns are for PHP 8.4, JIT off; mem_peak includes the fixture, own peak is the subject's own
working memory.

### Sources

Each source read to the end through `count()`; the rows are plain arrays in memory. "own peak" is the working memory of the read.

| Subject | Set | PHP 8.4, JIT off | PHP 8.4, JIT on | PHP 8.5, JIT off | PHP 8.5, JIT on | nodes/s (PHP 8.4, JIT off) | mem_peak | own peak |
|---|---|---|---|---|---|---|---|---|
| NestedSet | balanced-37k | 3.7 ms ±2.3% | 2.5 ms ±1.8% | 3.7 ms ±2.1% | 2.7 ms ±2.9% | 10.3M | 30.5 MiB | 105 KiB |
| NestedSet | balanced-300k | 31.3 ms ±1.7% | 19.6 ms ±1.6% | 30.7 ms ±1.7% | 21.3 ms ±2.0% | 9.6M | 241.7 MiB | 105 KiB |
| NestedSet | wide-1k x100 | 8.3 ms ±1.6% | 4.7 ms ±2.6% | 8.4 ms ±2.3% | 4.6 ms ±2.9% | 12.1M | 1.7 MiB | 105 KiB |
| NestedSet | wide-100k | 9.5 ms ±2.9% | 5.7 ms ±2.2% | 9.2 ms ±1.9% | 5.8 ms ±2.6% | 10.6M | 76.9 MiB | 105 KiB |
| NestedSet | deep-10k | 833 µs ±2.6% | 454 µs ±2.3% | 868 µs ±2.5% | 668 µs ±2.5% | 12.0M | 8.4 MiB | 279 KiB |
| NestedSet | category-1k x100 | 9.6 ms ±3.1% | 5.8 ms ±3.2% | 9.4 ms ±1.8% | 5.7 ms ±2.3% | 10.4M | 1.7 MiB | 105 KiB |
| NestedSet | category-100k | 10.9 ms ±1.2% | 7.5 ms ±1.7% | 10.8 ms ±0.5% | 7.8 ms ±1.8% | 9.1M | 79.5 MiB | 105 KiB |
| AdjacencyList | balanced-37k | 7.3 ms ±1.0% | 4.7 ms ±1.1% | 7.4 ms ±1.2% | 4.9 ms ±1.8% | 5.1M | 34.7 MiB | 4.3 MiB |
| AdjacencyList | balanced-300k | 67.3 ms ±1.7% | 42.9 ms ±2.7% | 67.5 ms ±1.3% | 44.2 ms ±2.0% | 4.5M | 276.1 MiB | 34.5 MiB |
| AdjacencyList | wide-1k x100 | 14.1 ms ±1.9% | 7.2 ms ±3.3% | 14.1 ms ±3.1% | 7.0 ms ±2.4% | 7.1M | 1.7 MiB | 150 KiB |
| AdjacencyList | wide-100k | 21.2 ms ±2.4% | 12.0 ms ±1.3% | 21.7 ms ±3.1% | 12.3 ms ±1.6% | 4.7M | 84.9 MiB | 8.0 MiB |
| AdjacencyList | deep-10k | 2.0 ms ±2.3% | 1.2 ms ±1.6% | 2.1 ms ±2.6% | 1.2 ms ±2.3% | 4.9M | 11.2 MiB | 3.1 MiB |
| AdjacencyList | category-1k x100 | 17.0 ms ±2.3% | 9.8 ms ±2.0% | 17.1 ms ±2.3% | 9.8 ms ±2.4% | 5.9M | 1.8 MiB | 239 KiB |
| AdjacencyList | category-100k | 26.5 ms ±1.9% | 17.4 ms ±3.0% | 26.5 ms ±1.6% | 18.5 ms ±1.2% | 3.8M | 96.4 MiB | 17.0 MiB |
| PreorderedAdjacency | balanced-37k | 5.6 ms ±0.6% | 2.8 ms ±3.1% | 5.7 ms ±1.9% | 3.1 ms ±2.4% | 6.7M | 30.5 MiB | 105 KiB |
| PreorderedAdjacency | balanced-300k | 48.0 ms ±3.2% | 24.7 ms ±2.2% | 47.5 ms ±2.6% | 25.9 ms ±1.0% | 6.2M | 241.7 MiB | 105 KiB |
| PreorderedAdjacency | wide-1k x100 | 12.0 ms ±0.9% | 6.8 ms ±2.7% | 12.1 ms ±2.5% | 6.5 ms ±2.2% | 8.3M | 1.7 MiB | 105 KiB |
| PreorderedAdjacency | wide-100k | 14.1 ms ±2.6% | 7.7 ms ±2.8% | 15.3 ms ±1.6% | 8.2 ms ±2.4% | 7.1M | 76.9 MiB | 105 KiB |
| PreorderedAdjacency | deep-10k | 1.3 ms ±2.4% | 668 µs ±1.4% | 1.2 ms ±2.8% | 680 µs ±2.4% | 7.9M | 8.4 MiB | 279 KiB |
| PreorderedAdjacency | category-1k x100 | 13.1 ms ±2.5% | 7.8 ms ±2.8% | 14.0 ms ±1.6% | 7.6 ms ±3.3% | 7.7M | 1.7 MiB | 105 KiB |
| PreorderedAdjacency | category-100k | 16.1 ms ±1.0% | 9.1 ms ±2.4% | 16.6 ms ±1.0% | 9.1 ms ±1.7% | 6.2M | 79.5 MiB | 105 KiB |
| MaterializedPath | balanced-37k | 9.0 ms ±2.5% | 5.3 ms ±3.1% | 9.0 ms ±3.1% | 5.2 ms ±2.6% | 4.2M | 30.5 MiB | 105 KiB |
| MaterializedPath | balanced-300k | 84.5 ms ±3.0% | 49.6 ms ±3.5% | 81.3 ms ±1.9% | 50.4 ms ±2.9% | 3.5M | 241.7 MiB | 105 KiB |
| MaterializedPath | wide-1k x100 | 21.5 ms ±3.8% | 12.3 ms ±2.8% | 20.7 ms ±2.4% | 12.0 ms ±2.6% | 4.7M | 1.7 MiB | 105 KiB |
| MaterializedPath | wide-100k | 23.9 ms ±1.3% | 12.6 ms ±2.5% | 22.9 ms ±3.1% | 12.7 ms ±2.7% | 4.2M | 76.9 MiB | 105 KiB |
| MaterializedPath | category-1k x100 | 23.0 ms ±3.2% | 14.2 ms ±2.1% | 23.1 ms ±3.4% | 13.3 ms ±3.3% | 4.4M | 1.7 MiB | 105 KiB |
| MaterializedPath | category-100k | 25.7 ms ±2.3% | 16.3 ms ±3.2% | 25.9 ms ±1.4% | 15.6 ms ±3.4% | 3.9M | 79.5 MiB | 105 KiB |
| NestedArray | balanced-37k | 4.2 ms ±1.6% | 2.9 ms ±2.0% | 4.0 ms ±0.7% | 3.1 ms ±2.0% | 8.9M | 56.3 MiB | 97 KiB |
| NestedArray | balanced-300k | 34.3 ms ±1.4% | 24.3 ms ±0.7% | 33.3 ms ±2.4% | 25.6 ms ±0.4% | 8.7M | 448.2 MiB | 97 KiB |
| NestedArray | wide-1k x100 | 7.5 ms ±2.4% | 5.0 ms ±2.2% | 7.5 ms ±2.6% | 5.3 ms ±3.7% | 13.3M | 2.4 MiB | 97 KiB |
| NestedArray | wide-100k | 8.3 ms ±1.3% | 5.1 ms ±2.6% | 7.8 ms ±2.1% | 5.1 ms ±1.5% | 12.1M | 145.3 MiB | 97 KiB |
| NestedArray | deep-10k | 1.4 ms ±0.8% | 872 µs ±2.9% | 1.4 ms ±2.8% | 826 µs ±2.3% | 7.0M | 17.3 MiB | 538 KiB |
| NestedArray | category-1k x100 | 12.1 ms ±3.5% | 8.1 ms ±2.7% | 11.7 ms ±1.2% | 8.0 ms ±3.0% | 8.3M | 2.4 MiB | 97 KiB |
| NestedArray | category-100k | 19.6 ms ±1.2% | 16.0 ms ±2.6% | 19.1 ms ±1.2% | 15.1 ms ±1.9% | 5.1M | 154.3 MiB | 97 KiB |
| DepthList | balanced-37k | 2.1 ms ±2.7% | 1.5 ms ±2.7% | 2.1 ms ±1.7% | 1.4 ms ±2.8% | 17.5M | 39.1 MiB | 134 KiB |
| DepthList | balanced-300k | 17.9 ms ±2.6% | 12.6 ms ±2.8% | 18.6 ms ±1.4% | 12.2 ms ±2.4% | 16.8M | 311.4 MiB | 134 KiB |
| DepthList | wide-1k x100 | 5.6 ms ±2.5% | 3.9 ms ±1.7% | 5.7 ms ±2.5% | 3.9 ms ±1.6% | 17.8M | 1.9 MiB | 134 KiB |
| DepthList | wide-100k | 5.9 ms ±3.2% | 3.9 ms ±1.7% | 5.8 ms ±2.5% | 3.9 ms ±3.5% | 16.8M | 99.5 MiB | 134 KiB |
| DepthList | deep-10k | 557 µs ±2.1% | 361 µs ±2.6% | 542 µs ±1.8% | 360 µs ±1.7% | 17.9M | 10.5 MiB | 134 KiB |
| DepthList | category-1k x100 | 5.7 ms ±2.2% | 4.0 ms ±2.3% | 5.6 ms ±3.4% | 4.0 ms ±2.7% | 17.7M | 1.9 MiB | 134 KiB |
| DepthList | category-100k | 6.1 ms ±2.5% | 4.4 ms ±1.3% | 6.7 ms ±3.0% | 4.3 ms ±2.1% | 16.3M | 102.0 MiB | 134 KiB |

### Strategies

Nested set rows → strategy → `count()`. `NoFilter` is the source alone; `Prune` and `ActiveTrailSingle` are the realistic cases, `PruneHit`, `ActiveTrailHit` and `Annotate` stress cases (see the benchmark file).

| Subject | Set | PHP 8.4, JIT off | PHP 8.4, JIT on | PHP 8.5, JIT off | PHP 8.5, JIT on | nodes/s (PHP 8.4, JIT off) | mem_peak | own peak |
|---|---|---|---|---|---|---|---|---|
| NoFilter | balanced-37k | 3.3 ms ±2.8% | 2.1 ms ±1.5% | 3.2 ms ±1.5% | 2.3 ms ±2.2% | 11.4M | 30.5 MiB | 104 KiB |
| NoFilter | balanced-300k | 27.2 ms ±0.9% | 18.5 ms ±2.4% | 27.8 ms ±2.1% | 20.6 ms ±1.6% | 11.0M | 241.7 MiB | 104 KiB |
| NoFilter | wide-1k x100 | 7.4 ms ±1.6% | 4.1 ms ±2.1% | 7.3 ms ±2.6% | 4.0 ms ±3.8% | 13.5M | 1.7 MiB | 104 KiB |
| NoFilter | wide-100k | 8.1 ms ±2.0% | 5.4 ms ±2.3% | 8.3 ms ±2.3% | 5.6 ms ±1.8% | 12.3M | 76.9 MiB | 104 KiB |
| NoFilter | deep-10k | 720 µs ±0.9% | 455 µs ±1.6% | 769 µs ±2.7% | 439 µs ±2.4% | 13.9M | 8.4 MiB | 279 KiB |
| NoFilter | category-1k x100 | 8.6 ms ±2.9% | 4.8 ms ±2.8% | 8.5 ms ±2.9% | 4.9 ms ±1.9% | 11.7M | 1.7 MiB | 104 KiB |
| NoFilter | category-100k | 9.8 ms ±2.3% | 7.2 ms ±0.9% | 10.1 ms ±2.1% | 7.1 ms ±1.9% | 10.2M | 79.5 MiB | 104 KiB |
| PruneVisible | balanced-37k | 4.8 ms ±2.0% | 2.8 ms ±2.8% | 4.8 ms ±1.4% | 2.9 ms ±3.0% | 7.7M | 30.5 MiB | 105 KiB |
| PruneVisible | balanced-300k | 40.4 ms ±2.9% | 22.9 ms ±2.5% | 40.7 ms ±3.0% | 23.3 ms ±1.6% | 7.4M | 241.7 MiB | 105 KiB |
| PruneVisible | wide-1k x100 | 16.6 ms ±1.9% | 9.1 ms ±1.7% | 17.4 ms ±1.7% | 9.3 ms ±1.5% | 6.0M | 1.7 MiB | 105 KiB |
| PruneVisible | wide-100k | 17.5 ms ±2.0% | 9.4 ms ±2.7% | 17.9 ms ±2.9% | 9.7 ms ±2.7% | 5.7M | 76.9 MiB | 105 KiB |
| PruneVisible | deep-10k | 242 µs ±2.3% | 269 µs ±2.7% | 244 µs ±2.3% | 288 µs ±2.3% | 41.3M | 8.2 MiB | 105 KiB |
| PruneVisible | category-1k x100 | 15.6 ms ±3.1% | 9.3 ms ±2.1% | 14.9 ms ±2.5% | 9.0 ms ±1.3% | 6.4M | 1.7 MiB | 105 KiB |
| PruneVisible | category-100k | 16.3 ms ±2.0% | 9.7 ms ±2.8% | 16.0 ms ±2.4% | 9.9 ms ±1.8% | 6.1M | 79.5 MiB | 105 KiB |
| PruneHit | balanced-37k | 1.1 ms ±2.5% | 992 µs ±3.7% | 1.1 ms ±3.1% | 952 µs ±1.7% | 32.7M | 30.5 MiB | 105 KiB |
| PruneHit | balanced-300k | 10.2 ms ±2.0% | 8.1 ms ±1.1% | 9.7 ms ±2.1% | 8.2 ms ±1.3% | 29.3M | 241.7 MiB | 105 KiB |
| PruneHit | wide-1k x100 | 2.1 ms ±1.5% | 891 µs ±2.3% | 2.3 ms ±3.1% | 890 µs ±2.1% | 47.4M | 1.7 MiB | 105 KiB |
| PruneHit | wide-100k | 3.0 ms ±3.0% | 2.6 ms ±2.2% | 3.0 ms ±1.6% | 2.5 ms ±1.3% | 32.9M | 76.9 MiB | 105 KiB |
| PruneHit | deep-10k | 258 µs ±3.2% | 178 µs ±1.3% | 236 µs ±2.3% | 168 µs ±2.6% | 38.8M | 8.2 MiB | 105 KiB |
| PruneHit | category-1k x100 | 2.3 ms ±2.3% | 970 µs ±2.9% | 2.3 ms ±2.8% | 948 µs ±2.3% | 44.3M | 1.7 MiB | 105 KiB |
| PruneHit | category-100k | 3.3 ms ±1.9% | 2.8 ms ±2.5% | 3.2 ms ±1.7% | 3.0 ms ±2.9% | 29.9M | 79.5 MiB | 105 KiB |
| KeepAncestors | balanced-37k | 6.3 ms ±2.2% | 3.3 ms ±2.5% | 6.6 ms ±2.6% | 3.5 ms ±1.9% | 5.9M | 30.5 MiB | 107 KiB |
| KeepAncestors | balanced-300k | 51.3 ms ±2.7% | 26.4 ms ±0.8% | 51.4 ms ±1.5% | 28.0 ms ±2.7% | 5.8M | 241.7 MiB | 107 KiB |
| KeepAncestors | wide-1k x100 | 15.7 ms ±2.7% | 7.4 ms ±2.4% | 15.1 ms ±1.4% | 7.4 ms ±2.1% | 6.4M | 1.7 MiB | 107 KiB |
| KeepAncestors | wide-100k | 15.7 ms ±1.3% | 7.9 ms ±1.9% | 16.1 ms ±2.6% | 8.5 ms ±1.9% | 6.4M | 76.9 MiB | 107 KiB |
| KeepAncestors | deep-10k | 1.8 ms ±2.5% | 965 µs ±2.2% | 1.8 ms ±2.5% | 1.1 ms ±2.1% | 5.5M | 8.6 MiB | 540 KiB |
| KeepAncestors | category-1k x100 | 17.5 ms ±2.6% | 8.7 ms ±2.0% | 16.8 ms ±1.4% | 8.9 ms ±3.0% | 5.7M | 1.7 MiB | 107 KiB |
| KeepAncestors | category-100k | 17.9 ms ±0.7% | 9.5 ms ±2.5% | 17.8 ms ±1.8% | 10.5 ms ±2.4% | 5.6M | 79.5 MiB | 107 KiB |
| Promote | balanced-37k | 6.1 ms ±2.1% | 3.2 ms ±2.4% | 5.9 ms ±2.7% | 3.3 ms ±3.1% | 6.1M | 30.5 MiB | 105 KiB |
| Promote | balanced-300k | 48.5 ms ±2.5% | 27.1 ms ±2.5% | 47.8 ms ±2.8% | 28.7 ms ±2.5% | 6.2M | 241.7 MiB | 105 KiB |
| Promote | wide-1k x100 | 14.5 ms ±2.8% | 7.6 ms ±1.3% | 14.9 ms ±3.4% | 7.7 ms ±3.0% | 6.9M | 1.7 MiB | 105 KiB |
| Promote | wide-100k | 15.1 ms ±3.0% | 8.5 ms ±3.3% | 15.3 ms ±1.7% | 8.6 ms ±2.3% | 6.6M | 76.9 MiB | 105 KiB |
| Promote | deep-10k | 1.5 ms ±2.4% | 977 µs ±2.8% | 1.5 ms ±2.2% | 884 µs ±2.5% | 6.8M | 8.4 MiB | 300 KiB |
| Promote | category-1k x100 | 16.7 ms ±3.7% | 8.7 ms ±3.1% | 17.8 ms ±2.8% | 8.7 ms ±1.9% | 6.0M | 1.7 MiB | 105 KiB |
| Promote | category-100k | 18.6 ms ±2.8% | 10.4 ms ±2.6% | 17.3 ms ±3.2% | 9.7 ms ±2.4% | 5.4M | 79.5 MiB | 105 KiB |
| Annotate | balanced-37k | 16.5 ms ±2.1% | 11.4 ms ±1.2% | 15.7 ms ±2.3% | 11.5 ms ±2.0% | 2.3M | 30.5 MiB | 113 KiB |
| Annotate | balanced-300k | 138.1 ms ±2.6% | 81.9 ms ±3.0% | 126.5 ms ±0.9% | 81.3 ms ±3.3% | 2.2M | 241.7 MiB | 113 KiB |
| Annotate | wide-1k x100 | 41.6 ms ±2.1% | 25.2 ms ±2.0% | 40.8 ms ±2.7% | 24.9 ms ±2.3% | 2.4M | 1.7 MiB | 113 KiB |
| Annotate | wide-100k | 43.4 ms ±2.9% | 28.3 ms ±2.0% | 41.6 ms ±1.4% | 28.0 ms ±1.3% | 2.3M | 76.9 MiB | 113 KiB |
| Annotate | deep-10k | 4.1 ms ±2.8% | 2.4 ms ±2.1% | 4.0 ms ±2.3% | 2.4 ms ±2.0% | 2.4M | 9.1 MiB | 1.0 MiB |
| Annotate | category-1k x100 | 43.0 ms ±2.6% | 28.8 ms ±2.7% | 41.7 ms ±2.4% | 29.6 ms ±2.1% | 2.3M | 1.7 MiB | 113 KiB |
| Annotate | category-100k | 46.8 ms ±2.5% | 29.7 ms ±2.8% | 43.8 ms ±2.8% | 32.0 ms ±2.3% | 2.1M | 79.5 MiB | 113 KiB |
| ActiveTrailHit | balanced-37k | 11.5 ms ±0.9% | 5.9 ms ±2.0% | 10.3 ms ±2.2% | 5.9 ms ±2.9% | 3.3M | 30.5 MiB | 114 KiB |
| ActiveTrailHit | balanced-300k | 85.7 ms ±2.0% | 46.1 ms ±2.4% | 85.3 ms ±3.2% | 48.6 ms ±2.9% | 3.5M | 241.7 MiB | 114 KiB |
| ActiveTrailHit | wide-1k x100 | 26.8 ms ±3.2% | 15.4 ms ±3.4% | 27.0 ms ±3.1% | 15.3 ms ±2.5% | 3.7M | 1.7 MiB | 114 KiB |
| ActiveTrailHit | wide-100k | 27.6 ms ±2.0% | 15.6 ms ±2.7% | 28.0 ms ±3.2% | 15.4 ms ±3.2% | 3.6M | 76.9 MiB | 114 KiB |
| ActiveTrailHit | deep-10k | 3.3 ms ±1.9% | 1.9 ms ±2.1% | 3.3 ms ±2.7% | 1.8 ms ±2.5% | 3.1M | 9.2 MiB | 1.1 MiB |
| ActiveTrailHit | category-1k x100 | 27.9 ms ±2.9% | 15.2 ms ±3.3% | 29.4 ms ±2.1% | 14.5 ms ±1.7% | 3.6M | 1.7 MiB | 114 KiB |
| ActiveTrailHit | category-100k | 30.3 ms ±2.1% | 17.3 ms ±2.2% | 30.4 ms ±1.4% | 17.1 ms ±2.4% | 3.3M | 79.5 MiB | 114 KiB |
| ActiveTrailSingle | balanced-37k | 8.4 ms ±1.7% | 3.9 ms ±1.7% | 8.4 ms ±2.8% | 3.9 ms ±1.8% | 4.5M | 30.5 MiB | 114 KiB |
| ActiveTrailSingle | balanced-300k | 69.6 ms ±2.6% | 31.9 ms ±1.8% | 67.1 ms ±2.8% | 30.5 ms ±1.4% | 4.3M | 241.7 MiB | 114 KiB |
| ActiveTrailSingle | wide-1k x100 | 24.9 ms ±2.8% | 12.2 ms ±2.2% | 26.1 ms ±3.2% | 12.1 ms ±2.0% | 4.0M | 1.7 MiB | 114 KiB |
| ActiveTrailSingle | wide-100k | 28.4 ms ±2.5% | 14.7 ms ±3.5% | 28.3 ms ±3.1% | 14.2 ms ±2.5% | 3.5M | 82.9 MiB | 6.0 MiB |
| ActiveTrailSingle | deep-10k | 3.0 ms ±2.2% | 1.6 ms ±2.1% | 3.0 ms ±2.5% | 1.6 ms ±1.6% | 3.4M | 11.2 MiB | 3.1 MiB |
| ActiveTrailSingle | category-1k x100 | 23.9 ms ±3.8% | 11.8 ms ±3.5% | 23.6 ms ±3.3% | 10.9 ms ±2.6% | 4.2M | 1.7 MiB | 114 KiB |
| ActiveTrailSingle | category-100k | 25.3 ms ±2.1% | 12.8 ms ±2.6% | 24.8 ms ±3.4% | 12.8 ms ±3.1% | 3.9M | 79.5 MiB | 114 KiB |

### Sinks

Nested set rows → sink. `KeepAncestorsToNestedArray` adds the strategy in front of the nested array sink.

| Subject | Set | PHP 8.4, JIT off | PHP 8.4, JIT on | PHP 8.5, JIT off | PHP 8.5, JIT on | nodes/s (PHP 8.4, JIT off) | mem_peak | own peak |
|---|---|---|---|---|---|---|---|---|
| Count | balanced-37k | 3.3 ms ±2.2% | 2.2 ms ±3.1% | 3.5 ms ±2.3% | 2.3 ms ±3.0% | 11.5M | 30.5 MiB | 104 KiB |
| Count | balanced-300k | 28.3 ms ±2.5% | 18.4 ms ±2.6% | 27.3 ms ±1.5% | 18.6 ms ±1.3% | 10.6M | 241.7 MiB | 104 KiB |
| Count | wide-1k x100 | 7.1 ms ±1.7% | 4.1 ms ±1.7% | 7.7 ms ±3.1% | 4.0 ms ±1.9% | 14.2M | 1.7 MiB | 104 KiB |
| Count | wide-100k | 8.1 ms ±2.0% | 5.6 ms ±2.5% | 8.2 ms ±1.6% | 5.5 ms ±0.8% | 12.3M | 76.9 MiB | 104 KiB |
| Count | deep-10k | 735 µs ±2.5% | 489 µs ±3.3% | 755 µs ±3.0% | 409 µs ±2.5% | 13.6M | 8.4 MiB | 279 KiB |
| Count | category-1k x100 | 8.5 ms ±2.8% | 4.9 ms ±2.9% | 8.5 ms ±3.1% | 4.9 ms ±1.1% | 11.7M | 1.7 MiB | 104 KiB |
| Count | category-100k | 9.9 ms ±2.4% | 6.9 ms ±1.2% | 9.8 ms ±2.6% | 7.1 ms ±1.8% | 10.1M | 79.5 MiB | 104 KiB |
| Iterate | balanced-37k | 3.6 ms ±2.1% | 2.6 ms ±2.0% | 3.7 ms ±2.4% | 2.6 ms ±2.2% | 10.3M | 30.5 MiB | 105 KiB |
| Iterate | balanced-300k | 31.1 ms ±2.0% | 20.0 ms ±1.9% | 31.1 ms ±3.6% | 20.9 ms ±1.1% | 9.6M | 241.7 MiB | 105 KiB |
| Iterate | wide-1k x100 | 8.2 ms ±1.4% | 4.5 ms ±2.1% | 8.6 ms ±2.5% | 4.5 ms ±3.5% | 12.2M | 1.7 MiB | 105 KiB |
| Iterate | wide-100k | 9.4 ms ±2.8% | 5.7 ms ±2.5% | 9.5 ms ±3.3% | 5.8 ms ±2.0% | 10.6M | 76.9 MiB | 105 KiB |
| Iterate | deep-10k | 886 µs ±2.3% | 460 µs ±2.0% | 866 µs ±1.9% | 563 µs ±2.0% | 11.3M | 8.4 MiB | 279 KiB |
| Iterate | category-1k x100 | 9.4 ms ±2.2% | 5.7 ms ±2.1% | 9.6 ms ±3.5% | 5.8 ms ±1.6% | 10.6M | 1.7 MiB | 105 KiB |
| Iterate | category-100k | 11.2 ms ±2.7% | 7.8 ms ±1.9% | 11.1 ms ±2.3% | 7.8 ms ±1.1% | 9.0M | 79.5 MiB | 105 KiB |
| ToNestedArray | balanced-37k | 11.7 ms ±3.6% | 7.8 ms ±2.5% | 11.0 ms ±1.5% | 7.9 ms ±1.3% | 3.2M | 56.2 MiB | 25.8 MiB |
| ToNestedArray | balanced-300k | 92.4 ms ±1.6% | 69.8 ms ±1.9% | 93.7 ms ±2.0% | 67.8 ms ±0.7% | 3.2M | 448.2 MiB | 206.6 MiB |
| ToNestedArray | wide-1k x100 | 20.6 ms ±2.1% | 14.7 ms ±1.5% | 23.2 ms ±2.6% | 13.4 ms ±2.5% | 4.9M | 2.3 MiB | 718 KiB |
| ToNestedArray | wide-100k | 27.6 ms ±1.5% | 19.8 ms ±3.6% | 28.3 ms ±3.1% | 19.5 ms ±1.1% | 3.6M | 145.2 MiB | 68.4 MiB |
| ToNestedArray | deep-10k | 2.9 ms ±2.1% | 2.3 ms ±3.6% | 3.0 ms ±2.4% | 2.4 ms ±3.7% | 3.4M | 17.3 MiB | 9.2 MiB |
| ToNestedArray | category-1k x100 | 25.2 ms ±2.6% | 17.9 ms ±2.2% | 25.5 ms ±2.5% | 16.8 ms ±1.6% | 4.0M | 2.4 MiB | 788 KiB |
| ToNestedArray | category-100k | 35.6 ms ±0.7% | 27.4 ms ±2.0% | 36.0 ms ±1.3% | 28.4 ms ±2.3% | 2.8M | 154.2 MiB | 74.9 MiB |
| ToNestedSet | balanced-37k | 12.0 ms ±2.1% | 8.3 ms ±2.3% | 12.2 ms ±2.0% | 8.9 ms ±1.6% | 3.1M | 61.2 MiB | 30.9 MiB |
| ToNestedSet | balanced-300k | 113.8 ms ±1.2% | 86.2 ms ±1.6% | 118.9 ms ±1.5% | 88.5 ms ±1.5% | 2.6M | 488.5 MiB | 246.9 MiB |
| ToNestedSet | wide-1k x100 | 24.8 ms ±2.9% | 14.5 ms ±2.7% | 24.1 ms ±2.8% | 14.3 ms ±2.6% | 4.0M | 2.4 MiB | 818 KiB |
| ToNestedSet | wide-100k | 36.2 ms ±1.4% | 28.6 ms ±2.2% | 36.4 ms ±1.1% | 28.8 ms ±2.7% | 2.8M | 155.2 MiB | 78.4 MiB |
| ToNestedSet | deep-10k | 2.7 ms ±2.7% | 2.0 ms ±2.2% | 2.8 ms ±2.5% | 1.8 ms ±1.6% | 3.7M | 16.5 MiB | 8.4 MiB |
| ToNestedSet | category-1k x100 | 25.8 ms ±2.6% | 14.7 ms ±1.9% | 25.8 ms ±2.1% | 15.2 ms ±1.8% | 3.9M | 2.4 MiB | 817 KiB |
| ToNestedSet | category-100k | 39.0 ms ±2.3% | 29.2 ms ±2.8% | 39.1 ms ±1.3% | 29.2 ms ±1.1% | 2.6M | 157.8 MiB | 78.4 MiB |
| ToAdjacencyList | balanced-37k | 9.1 ms ±2.1% | 6.7 ms ±2.1% | 9.4 ms ±1.9% | 6.4 ms ±1.8% | 4.1M | 56.2 MiB | 25.9 MiB |
| ToAdjacencyList | balanced-300k | 78.0 ms ±0.8% | 55.2 ms ±1.9% | 81.8 ms ±2.2% | 54.7 ms ±1.9% | 3.8M | 448.4 MiB | 206.9 MiB |
| ToAdjacencyList | wide-1k x100 | 18.8 ms ±2.4% | 11.5 ms ±1.5% | 18.7 ms ±2.8% | 11.3 ms ±2.4% | 5.3M | 2.3 MiB | 721 KiB |
| ToAdjacencyList | wide-100k | 24.6 ms ±3.0% | 18.3 ms ±2.0% | 26.0 ms ±1.4% | 17.0 ms ±2.0% | 4.1M | 145.2 MiB | 68.4 MiB |
| ToAdjacencyList | deep-10k | 2.0 ms ±1.5% | 1.4 ms ±2.9% | 2.1 ms ±2.0% | 1.3 ms ±2.7% | 5.0M | 15.5 MiB | 7.4 MiB |
| ToAdjacencyList | category-1k x100 | 20.4 ms ±1.8% | 12.3 ms ±2.2% | 20.6 ms ±2.6% | 12.6 ms ±3.1% | 4.9M | 2.3 MiB | 720 KiB |
| ToAdjacencyList | category-100k | 26.8 ms ±2.8% | 18.6 ms ±1.7% | 28.2 ms ±2.2% | 20.3 ms ±1.9% | 3.7M | 147.8 MiB | 68.4 MiB |
| ToFlatList | balanced-37k | 5.2 ms ±2.9% | 4.1 ms ±2.7% | 5.2 ms ±2.5% | 4.8 ms ±2.2% | 7.2M | 44.8 MiB | 14.5 MiB |
| ToFlatList | balanced-300k | 48.7 ms ±2.6% | 40.2 ms ±2.9% | 48.0 ms ±1.2% | 40.6 ms ±2.0% | 6.2M | 357.0 MiB | 115.5 MiB |
| ToFlatList | wide-1k x100 | 11.9 ms ±1.6% | 7.9 ms ±1.9% | 11.5 ms ±1.7% | 7.7 ms ±1.7% | 8.4M | 2.0 MiB | 407 KiB |
| ToFlatList | wide-100k | 15.1 ms ±2.2% | 13.1 ms ±2.5% | 14.9 ms ±1.3% | 11.3 ms ±1.0% | 6.6M | 114.7 MiB | 37.9 MiB |
| ToFlatList | deep-10k | 1.3 ms ±1.7% | 1.1 ms ±0.8% | 1.3 ms ±2.4% | 976 µs ±2.3% | 7.8M | 12.2 MiB | 4.1 MiB |
| ToFlatList | category-1k x100 | 18.6 ms ±2.1% | 8.8 ms ±2.8% | 12.7 ms ±2.3% | 9.3 ms ±0.9% | 5.4M | 2.0 MiB | 406 KiB |
| ToFlatList | category-100k | 16.3 ms ±1.9% | 13.5 ms ±1.8% | 16.5 ms ±0.7% | 13.6 ms ±0.8% | 6.2M | 117.2 MiB | 37.9 MiB |
| KeepAncestorsToNestedArray | balanced-37k | 8.3 ms ±2.8% | 5.3 ms ±1.6% | 8.4 ms ±2.8% | 5.2 ms ±2.4% | 4.5M | 35.9 MiB | 5.6 MiB |
| KeepAncestorsToNestedArray | balanced-300k | 69.5 ms ±2.3% | 51.4 ms ±2.7% | 73.0 ms ±2.0% | 47.7 ms ±1.9% | 4.3M | 286.1 MiB | 44.6 MiB |
| KeepAncestorsToNestedArray | wide-1k x100 | 17.1 ms ±3.3% | 9.9 ms ±2.8% | 17.7 ms ±2.8% | 9.1 ms ±1.6% | 5.8M | 1.7 MiB | 107 KiB |
| KeepAncestorsToNestedArray | wide-100k | 17.7 ms ±1.8% | 10.7 ms ±3.7% | 19.2 ms ±2.4% | 10.6 ms ±1.7% | 5.7M | 83.7 MiB | 6.9 MiB |
| KeepAncestorsToNestedArray | deep-10k | 4.0 ms ±2.1% | 2.9 ms ±1.8% | 4.0 ms ±2.2% | 2.8 ms ±1.9% | 2.5M | 17.3 MiB | 9.2 MiB |
| KeepAncestorsToNestedArray | category-1k x100 | 22.1 ms ±2.2% | 12.2 ms ±3.2% | 21.2 ms ±2.7% | 12.7 ms ±2.8% | 4.5M | 1.8 MiB | 207 KiB |
| KeepAncestorsToNestedArray | category-100k | 26.3 ms ±2.3% | 16.8 ms ±2.2% | 25.9 ms ±2.0% | 17.5 ms ±1.8% | 3.8M | 97.1 MiB | 17.7 MiB |

### Naive baseline

`Naive*` rows are the recursive build-the-whole-tree-then-filter code in `benchmarks/Support/Naive.php`; `Library*` rows the same result through the library.

| Subject | Set | PHP 8.4, JIT off | PHP 8.4, JIT on | PHP 8.5, JIT off | PHP 8.5, JIT on | nodes/s (PHP 8.4, JIT off) | mem_peak | own peak |
|---|---|---|---|---|---|---|---|---|
| NaiveAdjacencyKeepAncestors | balanced-37k | 14.5 ms ±1.8% | 14.5 ms ±2.1% | 14.7 ms ±1.9% | 12.6 ms ±1.6% | 2.6M | 61.7 MiB | 31.4 MiB |
| NaiveAdjacencyKeepAncestors | balanced-300k | 126.3 ms ±1.8% | 112.5 ms ±1.4% | 128.4 ms ±1.5% | 110.8 ms ±2.0% | 2.4M | 492.6 MiB | 251.1 MiB |
| NaiveAdjacencyKeepAncestors | wide-1k x100 | 22.0 ms ±3.1% | 14.9 ms ±2.7% | 19.9 ms ±2.6% | 14.2 ms ±3.4% | 4.6M | 2.3 MiB | 791 KiB |
| NaiveAdjacencyKeepAncestors | wide-100k | 31.4 ms ±1.6% | 28.5 ms ±1.8% | 32.8 ms ±0.5% | 26.4 ms ±1.0% | 3.2M | 152.1 MiB | 75.3 MiB |
| NaiveAdjacencyKeepAncestors | deep-10k | 4.8 ms ±2.8% | 5.6 ms ±2.3% | 4.9 ms ±1.7% | 5.1 ms ±2.0% | 2.1M | 25.4 MiB | 17.4 MiB |
| NaiveAdjacencyKeepAncestors | category-1k x100 | 29.6 ms ±3.2% | 22.4 ms ±2.0% | 28.9 ms ±2.1% | 21.5 ms ±1.8% | 3.4M | 2.5 MiB | 973 KiB |
| NaiveAdjacencyKeepAncestors | category-100k | 54.0 ms ±2.2% | 48.6 ms ±0.6% | 55.5 ms ±0.5% | 47.4 ms ±1.6% | 1.9M | 171.9 MiB | 92.6 MiB |
| LibraryAdjacencyKeepAncestors | balanced-37k | 12.6 ms ±2.5% | 8.6 ms ±3.4% | 13.0 ms ±1.9% | 7.8 ms ±1.5% | 3.0M | 38.2 MiB | 7.9 MiB |
| LibraryAdjacencyKeepAncestors | balanced-300k | 112.8 ms ±1.7% | 77.1 ms ±2.3% | 116.8 ms ±1.5% | 78.6 ms ±1.8% | 2.7M | 304.3 MiB | 62.8 MiB |
| LibraryAdjacencyKeepAncestors | wide-1k x100 | 22.4 ms ±2.7% | 12.1 ms ±2.2% | 22.0 ms ±2.2% | 11.3 ms ±2.4% | 4.5M | 1.7 MiB | 150 KiB |
| LibraryAdjacencyKeepAncestors | wide-100k | 31.0 ms ±1.9% | 18.8 ms ±2.3% | 31.9 ms ±0.9% | 19.5 ms ±2.5% | 3.2M | 87.8 MiB | 10.9 MiB |
| LibraryAdjacencyKeepAncestors | deep-10k | 5.5 ms ±3.1% | 3.8 ms ±2.3% | 5.6 ms ±2.6% | 3.9 ms ±2.2% | 1.8M | 17.3 MiB | 9.2 MiB |
| LibraryAdjacencyKeepAncestors | category-1k x100 | 29.5 ms ±1.7% | 17.8 ms ±3.5% | 29.9 ms ±2.4% | 16.4 ms ±2.3% | 3.4M | 1.9 MiB | 335 KiB |
| LibraryAdjacencyKeepAncestors | category-100k | 43.6 ms ±1.9% | 30.9 ms ±2.5% | 44.4 ms ±2.1% | 32.4 ms ±2.1% | 2.3M | 110.1 MiB | 30.7 MiB |
| NaiveNestedSetKeepAncestors | balanced-37k | 15.7 ms ±2.1% | 13.2 ms ±1.2% | 16.0 ms ±1.5% | 13.5 ms ±2.8% | 2.4M | 62.8 MiB | 32.5 MiB |
| NaiveNestedSetKeepAncestors | balanced-300k | 139.4 ms ±0.7% | 109.9 ms ±0.8% | 140.5 ms ±1.3% | 110.7 ms ±2.1% | 2.1M | 501.8 MiB | 260.3 MiB |
| NaiveNestedSetKeepAncestors | wide-1k x100 | 26.1 ms ±2.1% | 17.8 ms ±2.1% | 26.6 ms ±2.0% | 18.0 ms ±2.2% | 3.8M | 2.3 MiB | 822 KiB |
| NaiveNestedSetKeepAncestors | wide-100k | 37.9 ms ±2.3% | 27.5 ms ±1.0% | 35.7 ms ±0.7% | 27.2 ms ±0.5% | 2.6M | 155.1 MiB | 78.3 MiB |
| NaiveNestedSetKeepAncestors | deep-10k | 4.3 ms ±2.7% | 4.7 ms ±3.0% | 4.4 ms ±2.6% | 4.3 ms ±2.0% | 2.3M | 25.7 MiB | 17.7 MiB |
| NaiveNestedSetKeepAncestors | category-1k x100 | 32.5 ms ±3.3% | 23.6 ms ±1.5% | 32.0 ms ±1.8% | 23.6 ms ±1.8% | 3.1M | 2.5 MiB | 1004 KiB |
| NaiveNestedSetKeepAncestors | category-100k | 56.4 ms ±2.3% | 47.2 ms ±1.8% | 56.4 ms ±1.4% | 46.8 ms ±2.1% | 1.8M | 175.0 MiB | 95.7 MiB |
| LibraryNestedSetKeepAncestors | balanced-37k | 8.1 ms ±1.7% | 5.1 ms ±1.5% | 8.6 ms ±2.6% | 5.2 ms ±2.7% | 4.6M | 35.9 MiB | 5.6 MiB |
| LibraryNestedSetKeepAncestors | balanced-300k | 68.7 ms ±2.7% | 44.2 ms ±1.4% | 69.8 ms ±1.7% | 45.1 ms ±1.9% | 4.4M | 286.1 MiB | 44.6 MiB |
| LibraryNestedSetKeepAncestors | wide-1k x100 | 17.6 ms ±2.4% | 9.7 ms ±1.8% | 17.3 ms ±2.5% | 9.2 ms ±1.9% | 5.7M | 1.7 MiB | 107 KiB |
| LibraryNestedSetKeepAncestors | wide-100k | 18.6 ms ±2.8% | 10.8 ms ±2.7% | 18.2 ms ±2.4% | 10.6 ms ±1.9% | 5.4M | 83.7 MiB | 6.9 MiB |
| LibraryNestedSetKeepAncestors | deep-10k | 3.9 ms ±2.5% | 2.7 ms ±2.6% | 4.1 ms ±2.5% | 2.9 ms ±2.5% | 2.5M | 17.3 MiB | 9.2 MiB |
| LibraryNestedSetKeepAncestors | category-1k x100 | 21.8 ms ±3.5% | 13.2 ms ±3.0% | 22.4 ms ±2.2% | 12.3 ms ±2.7% | 4.6M | 1.8 MiB | 207 KiB |
| LibraryNestedSetKeepAncestors | category-100k | 24.8 ms ±2.6% | 17.3 ms ±2.5% | 26.4 ms ±3.3% | 18.3 ms ±2.5% | 4.0M | 97.1 MiB | 17.7 MiB |
| NaiveAdjacencyPrune | balanced-37k | 13.0 ms ±2.4% | 12.5 ms ±2.1% | 13.7 ms ±0.9% | 12.7 ms ±3.1% | 2.9M | 71.7 MiB | 41.4 MiB |
| NaiveAdjacencyPrune | balanced-300k | 113.7 ms ±1.1% | 104.2 ms ±0.8% | 116.6 ms ±1.4% | 106.1 ms ±2.6% | 2.6M | 571.8 MiB | 330.3 MiB |
| NaiveAdjacencyPrune | wide-1k x100 | 21.6 ms ±2.7% | 15.2 ms ±2.7% | 20.4 ms ±1.9% | 14.6 ms ±2.4% | 4.6M | 2.8 MiB | 1.3 MiB |
| NaiveAdjacencyPrune | wide-100k | 36.8 ms ±1.7% | 33.0 ms ±2.1% | 36.8 ms ±0.8% | 33.3 ms ±0.7% | 2.7M | 206.9 MiB | 130.1 MiB |
| NaiveAdjacencyPrune | deep-10k | 2.4 ms ±3.0% | 2.7 ms ±2.9% | 2.5 ms ±2.1% | 2.6 ms ±2.3% | 4.2M | 19.1 MiB | 11.0 MiB |
| NaiveAdjacencyPrune | category-1k x100 | 28.8 ms ±2.7% | 21.6 ms ±1.8% | 26.5 ms ±2.7% | 20.7 ms ±2.6% | 3.5M | 2.9 MiB | 1.3 MiB |
| NaiveAdjacencyPrune | category-100k | 52.4 ms ±0.5% | 49.9 ms ±2.4% | 54.4 ms ±1.4% | 52.3 ms ±3.7% | 1.9M | 208.4 MiB | 129.1 MiB |
| LibraryAdjacencyPrune | balanced-37k | 14.9 ms ±3.1% | 9.9 ms ±1.8% | 14.5 ms ±1.7% | 10.0 ms ±2.0% | 2.5M | 48.2 MiB | 17.9 MiB |
| LibraryAdjacencyPrune | balanced-300k | 125.1 ms ±1.9% | 88.2 ms ±1.8% | 124.8 ms ±1.9% | 89.1 ms ±1.2% | 2.4M | 383.5 MiB | 141.9 MiB |
| LibraryAdjacencyPrune | wide-1k x100 | 34.6 ms ±1.5% | 20.6 ms ±2.9% | 35.8 ms ±2.5% | 20.4 ms ±2.0% | 2.9M | 2.3 MiB | 691 KiB |
| LibraryAdjacencyPrune | wide-100k | 48.9 ms ±2.3% | 31.8 ms ±1.5% | 51.8 ms ±0.8% | 33.0 ms ±3.1% | 2.0M | 142.6 MiB | 65.8 MiB |
| LibraryAdjacencyPrune | deep-10k | 1.2 ms ±2.4% | 848 µs ±2.9% | 1.2 ms ±2.4% | 1.0 ms ±2.6% | 8.2M | 11.2 MiB | 3.1 MiB |
| LibraryAdjacencyPrune | category-1k x100 | 37.5 ms ±3.0% | 23.2 ms ±3.7% | 39.4 ms ±2.5% | 23.8 ms ±1.0% | 2.7M | 2.3 MiB | 727 KiB |
| LibraryAdjacencyPrune | category-100k | 53.9 ms ±2.4% | 39.5 ms ±1.5% | 56.5 ms ±2.2% | 40.6 ms ±1.5% | 1.9M | 146.6 MiB | 67.2 MiB |
| LibraryNestedSetPrune | balanced-37k | 9.7 ms ±2.7% | 7.0 ms ±2.5% | 10.3 ms ±2.0% | 6.8 ms ±1.2% | 3.9M | 45.9 MiB | 15.6 MiB |
| LibraryNestedSetPrune | balanced-300k | 84.1 ms ±2.8% | 54.9 ms ±1.2% | 89.1 ms ±2.1% | 57.1 ms ±1.7% | 3.6M | 365.3 MiB | 123.7 MiB |
| LibraryNestedSetPrune | wide-1k x100 | 30.9 ms ±1.5% | 19.1 ms ±2.7% | 31.3 ms ±3.5% | 19.3 ms ±1.7% | 3.2M | 2.2 MiB | 652 KiB |
| LibraryNestedSetPrune | wide-100k | 36.9 ms ±2.9% | 23.8 ms ±2.6% | 38.5 ms ±2.0% | 23.4 ms ±1.7% | 2.7M | 138.6 MiB | 61.8 MiB |
| LibraryNestedSetPrune | deep-10k | 239 µs ±1.8% | 358 µs ±3.1% | 244 µs ±2.3% | 320 µs ±1.8% | 41.8M | 8.2 MiB | 105 KiB |
| LibraryNestedSetPrune | category-1k x100 | 27.9 ms ±1.3% | 20.3 ms ±2.3% | 28.9 ms ±3.5% | 19.6 ms ±2.9% | 3.6M | 2.2 MiB | 599 KiB |
| LibraryNestedSetPrune | category-100k | 37.4 ms ±2.9% | 25.6 ms ±2.6% | 37.3 ms ±2.1% | 25.8 ms ±2.3% | 2.7M | 133.6 MiB | 54.2 MiB |

### Streaming from a generator

Rows generated lazily (time includes generating them; `GeneratorOnly` is that part alone). Sizes are the node counts of balanced trees with branching 10.

| Subject | Set | PHP 8.4, JIT off | PHP 8.4, JIT on | PHP 8.5, JIT off | PHP 8.5, JIT on | nodes/s (PHP 8.4, JIT off) | mem_peak | own peak |
|---|---|---|---|---|---|---|---|---|
| GeneratorOnly | 11,111 | 4.2 ms ±3.0% | 5.2 ms ±2.7% | 4.2 ms ±3.0% | 4.2 ms ±3.0% | 2.6M | 972 KiB | 193 KiB |
| GeneratorOnly | 111,111 | 35.1 ms ±2.8% | 29.5 ms ±2.3% | 35.7 ms ±3.3% | 28.7 ms ±2.7% | 3.2M | 972 KiB | 193 KiB |
| GeneratorOnly | 1,111,111 | 369.4 ms ±2.9% | 308.2 ms ±3.0% | 370.4 ms ±3.3% | 279.8 ms ±3.0% | 3.0M | 972 KiB | 193 KiB |
| NestedSet | 11,111 | 6.7 ms ±3.0% | 7.4 ms ±2.9% | 7.7 ms ±0.4% | 7.5 ms ±1.4% | 1.7M | 1.0 MiB | 194 KiB |
| NestedSet | 111,111 | 51.4 ms ±2.3% | 41.3 ms ±2.8% | 46.5 ms ±3.0% | 35.6 ms ±2.5% | 2.2M | 1.0 MiB | 194 KiB |
| NestedSet | 1,111,111 | 467.9 ms ±2.8% | 340.7 ms ±2.2% | 494.0 ms ±2.5% | 331.1 ms ±2.2% | 2.4M | 1.0 MiB | 194 KiB |
| PreorderedAdjacency | 11,111 | 7.3 ms ±2.3% | 7.7 ms ±2.1% | 7.5 ms ±3.2% | 7.5 ms ±2.1% | 1.5M | 1.0 MiB | 194 KiB |
| PreorderedAdjacency | 111,111 | 52.1 ms ±3.8% | 40.5 ms ±2.0% | 56.2 ms ±1.8% | 39.4 ms ±2.5% | 2.1M | 1.0 MiB | 194 KiB |
| PreorderedAdjacency | 1,111,111 | 511.9 ms ±2.6% | 385.9 ms ±1.7% | 576.8 ms ±2.2% | 348.9 ms ±1.9% | 2.2M | 1.0 MiB | 194 KiB |
| MaterializedPath | 11,111 | 8.2 ms ±2.3% | 8.8 ms ±2.1% | 8.5 ms ±2.7% | 8.4 ms ±1.1% | 1.3M | 1.0 MiB | 194 KiB |
| MaterializedPath | 111,111 | 68.4 ms ±2.7% | 49.4 ms ±3.1% | 69.3 ms ±2.1% | 47.4 ms ±2.7% | 1.6M | 1.0 MiB | 194 KiB |
| MaterializedPath | 1,111,111 | 650.5 ms ±0.7% | 477.2 ms ±3.5% | 737.7 ms ±2.9% | 467.5 ms ±3.1% | 1.7M | 1.0 MiB | 194 KiB |
| NestedSetPrune | 11,111 | 8.6 ms ±3.0% | 8.8 ms ±3.7% | 8.7 ms ±2.6% | 9.1 ms ±1.7% | 1.3M | 1.0 MiB | 194 KiB |
| NestedSetPrune | 111,111 | 61.7 ms ±2.1% | 45.7 ms ±2.8% | 61.3 ms ±3.1% | 45.6 ms ±2.2% | 1.8M | 1.0 MiB | 194 KiB |
| NestedSetPrune | 1,111,111 | 603.0 ms ±3.0% | 387.9 ms ±2.0% | 555.9 ms ±2.8% | 380.6 ms ±1.9% | 1.8M | 1.0 MiB | 194 KiB |
| NestedSetKeepAncestors | 11,111 | 9.2 ms ±2.3% | 10.0 ms ±2.9% | 8.7 ms ±3.5% | 9.7 ms ±1.3% | 1.2M | 1.0 MiB | 194 KiB |
| NestedSetKeepAncestors | 111,111 | 58.2 ms ±1.7% | 45.8 ms ±4.0% | 62.3 ms ±3.6% | 43.7 ms ±3.4% | 1.9M | 1.0 MiB | 194 KiB |
| NestedSetKeepAncestors | 1,111,111 | 605.3 ms ±2.1% | 417.4 ms ±3.1% | 599.4 ms ±1.6% | 418.1 ms ±1.6% | 1.8M | 1.0 MiB | 194 KiB |
| NestedSetPromote | 11,111 | 8.8 ms ±2.3% | 8.9 ms ±2.6% | 8.7 ms ±3.2% | 9.2 ms ±2.6% | 1.3M | 1.0 MiB | 194 KiB |
| NestedSetPromote | 111,111 | 58.0 ms ±2.4% | 43.0 ms ±2.5% | 61.3 ms ±1.3% | 45.4 ms ±1.4% | 1.9M | 1.0 MiB | 194 KiB |
| NestedSetPromote | 1,111,111 | 588.3 ms ±2.3% | 382.8 ms ±2.3% | 601.1 ms ±1.8% | 390.3 ms ±3.3% | 1.9M | 1.0 MiB | 194 KiB |

<!-- results:end -->
