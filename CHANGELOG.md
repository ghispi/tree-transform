# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- `NestedSetFields` takes an `id` field name (default `'id'`, last parameter). `NestedSetSource` reads it only to
  name the offending row in an `InvalidTreeException`; the Gedmo bridge sets it to the entity id.

### Changed

- Nested set errors (left not below right, left values not increasing, overlapping intervals) now report the row's
  id in `nodeId()` and the message instead of `null`. Depth list errors still have no id to report (entries carry
  arbitrary nodes); their message now starts with the entry's position, e.g. `entry 3: depth 2 cannot follow …`.

## [1.0.0] - 2026-11-05

The first stable release. The public API ([docs/api.md](docs/api.md)) is frozen: minor and patch releases don't break
it. No breaking changes since 0.3.0; [UPGRADE.md](UPGRADE.md) covers upgrading from 0.1, 0.2 and 0.3.

### Added

- [UPGRADE.md](UPGRADE.md): moving from 0.1, 0.2 and 0.3 to 1.0, with before/after code for every break.
- [Benchmark report](docs/benchmarks.md): the full matrix on PHP 8.4 and 8.5, JIT off and on, with hardware, method,
  the SPEC §10 targets with a verdict each, and `make bench-report` to reproduce it
  (`benchmarks/run-report.sh`, `benchmarks/render-report.php`).
- Documentation site at https://ghispi.github.io/tree-transform/, built from `docs/` with Zensical (`make docs-site`)
  and deployed by `docs.yml` on release tags. `composer.json` `homepage` and `support.docs` point to it.

## [0.3.0] - 2026-11-05

The API review before 1.0 (roadmap phase 1.0, steps 1–4). This is the API 1.0 is meant to freeze; all public
types are listed in [docs/api.md](docs/api.md). Upgrading from 0.2 needs code changes: see the **Breaking** entries.

### Changed

- **Breaking:** the parameter for the children field is `childrenField` everywhere: `NestedArraySource` (was
  `children`), `NestedArraySink` and `Tree::toNestedArray()` (were `childrenKey`). Positional calls are unaffected.
- **Breaking:** `AdjacencyListSink` and `Tree::toAdjacencyList()` take an `AdjacencyListFields` object, as the
  adjacency sources do: `toAdjacencyList(new AdjacencyListFields('key', 'up'), rootParent: 0)` instead of
  `toAdjacencyList('key', 'up', 0)`. The parameter order is now `fields, accessor, rootParent, nodeFactory`.
- **Breaking:** `NestedSetSink` and `Tree::toNestedSet()` take a `NestedSetFields` object, as `NestedSetSource`
  does, and drop `restartPerRoot`. `NestedSetFields` has a new `level` field (sources ignore it). The sink's
  default is `new NestedSetFields(level: 'lvl')`, so the default output is unchanged. Migration:
  `leftField`/`rightField`/`levelField` → `new NestedSetFields(left: …, right: …, level: …)`, and
  `restartPerRoot: true` → set a `root` field and keep `level: 'lvl'` if you wrote levels (or use
  `NestedSetFields::gedmo()`); `levelField: null` → leave `level` out.
- With a `root` field, `NestedSetSink` numbers every root's tree from 1 **and writes each node's root id** (read from
  `idField`, default `'id'` — set it when your id column has another name). Before, the root column kept its input
  value, which was wrong after `Promote` or `depthWindow()`. A root without an int or string id, or two consecutive
  roots with the same id, throw `InvalidArgumentException`. A `nodeFactory` receives the root id as a fifth argument. `NestedSetFields::gedmo()` now also
  sets `level: 'lvl'`.
- **Breaking:** `Ancestors` is read-only for user code: its constructor is private and `enter()` is `@internal`.
  Custom strategies use the new `AncestorTracker` (`enter()`, `ancestors()`). `Ancestors::of($path)` builds a view
  for testing predicates.
- **Breaking:** `Ancestors::at()` throws `Exception\OutOfRangeException` (a subclass of `\OutOfRangeException` that
  implements `TreeTransformException`), so `catch (TreeTransformException)` catches it.
- **Breaking:** `Tree::activeTrail()`'s first parameter is named `predicate` (was `isActive`), as in `filter()` and
  `annotate()`.
- `FieldEquals` and `FieldIn` default `accessor` to `new AutoAccessor()` instead of `null`. Passing `null` no longer
  works.
- `Tree::depthWindow()` defaults `min` to 0, like `DepthWindow`.
- `AllOf::predicates()`, `AnyOf::predicates()`, `Callback::closure()`, `Not::inner()`,
  `CycleDetectedException::forIds()` and `OrphanNodeException::forParent()` are `@internal`.
- **Breaking (Gedmo bridge):** `TreeConfig::$strategy` is a `Bridge\Gedmo\TreeStrategy` enum (`Nested`,
  `Closure`, `MaterializedPath`; values are Gedmo's strategy names). The `TreeConfig::NESTED`, `CLOSURE` and
  `MATERIALIZED_PATH` constants are removed: compare with `TreeStrategy::Nested`, or use
  `$config->strategy->value`.
- The `TreeConfig` constructor and `TreeConfig::read()` are `@internal`: get the configuration from
  `GedmoTree::config()` and only read it. New properties may be added in minor releases.

### Added

- `Tree::fromFlatList()`, and `DepthListSource::pairs()` accepts `['depth' => …, 'node' => …]` entries: the output of
  `toFlatList()` can be read back.
- `InvalidTreeException::nodeId()`, `CycleDetectedException::ids()`, `OrphanNodeException::parentId()` and
  `childCount()`.
- `GedmoTree::activeTrail()` accepts `modify` (last parameter), with the same meaning as in `tree()`. With `modify`
  set, the result equals `tree(modify: …)` followed by `ActiveTrail`, and the nested set row pushdown is not used.
- `docs/api.md`: the public API at a glance and the compatibility promise from 1.0 (what SemVer covers).
- CI guards the public API: a reflection snapshot test (`tests/Api`, `make api-snapshot`) and the Roave BC checker
  against the latest release (`make bc-check`, CI job `Backward compatibility`). The Docker image gains `bcmath` and
  `intl` for the checker.
- The Gedmo bridge's integration tests also run on MySQL 8.4 and PostgreSQL 17 (`make test-db`, CI job
  `Integration (MySQL, PostgreSQL)`). Benchmarks can be run on demand in GitHub Actions (`benchmarks.yml`).
- Mutation testing covers the strategies with an exhaustive test (every forest of up to five nodes, every set of
  matches). The surviving mutants were reviewed; the spec (§11) lists why they cannot change output.

## [0.2.0] - 2026-10-28

### Changed

- Internal pipeline rewritten on generators (roadmap phase 0.4): built-in sources, strategies, stages and sinks pass nodes
  as one chain of generators instead of cursor objects. Pipelines run about 1.5–3× faster; the public `Cursor`,
  `Source`, `Stage`, `FilterStrategy` and `Sink` contracts are unchanged, and user implementations keep working.
- An exception thrown while reading (by a predicate, a callback or input validation) now ends the traversal: a
  caller that catches it and calls `next()` again gets `false`, instead of the remaining nodes.
- `NestedArraySource` rewinds its input when it is opened, like the other sources: a used generator throws
  `LogicException` from `open()` instead of from the first `next()`.
- `AdjacencyListSource` builds its index faster: inline reads of array rows, children resolved in a second pass in
  input order, and the cycle check runs only when a child comes before its parent.

### Added

- `Tree::iterate()`: `send(true)` on the returned generator skips the current node's subtree.

### Fixed

- `NestedSetSource` rejects a child whose right value equals its parent's (e.g. `1..4` containing `2..4`): a
  child's interval must lie strictly inside its parent's.
- `AdjacencyListSource` with `OrphanPolicy::Drop`: the cycle error no longer counts and names dropped orphans as
  cycle members.

### Removed

- The internal `*Cursor` classes (`NestedSetCursor`, `PruneCursor`, …). They were marked `@internal`; use the
  sources' `open()`, the strategies' and stages' `apply()`, or `Tree::cursor()` for a `Cursor`.

## [0.1.0] - 2026-10-28

First release.

### Added

- `Tree` facade: fluent, immutable and lazy; `from…()` sources, stages, and `to…()` / `iterate()` / `count()` outputs.
- Sources: `NestedSetSource`, `MaterializedPathSource` (subtrees and `allowGaps`), `NestedArraySource`,
  `AdjacencyListSource` (any order, orphan policies, cycle detection, sibling order), `PreorderedAdjacencySource`,
  `DepthListSource`, and `LazySource` / `Tree::lazy()` to re-read one-shot inputs such as database queries.
- Filter strategies: `Prune`, `KeepAncestors`, `Promote`, `ActiveTrail`, `Annotate`, `MinMatches`, `Placeholder`,
  each with `OnMatch` (`EvaluateDescendants`, `KeepDescendants`, `DropDescendants`) where it applies.
- Predicates: closures, `Callback`, `AllOf`, `AnyOf`, `Not`, `DepthRange`, `FieldEquals`, `FieldIn`, and a lazy
  `Ancestors` view passed to every predicate.
- Stages: `Map`, `MaxDepth`, `DepthWindow`, `Limit`, `SortSiblings`, and `pipe()` for custom stages.
- Outputs: nested arrays (with `TreeNode` for objects, or a node factory), flat list, adjacency list with rewritten
  parents, nested set with recomputed values, count, and streaming `iterate()`.
- Accessors: `AutoAccessor` (default), `ArrayKeyAccessor`, `PropertyAccessor`, `GetterAccessor`,
  `PrivatePropertyAccessor`.
- Doctrine/Gedmo bridge `GedmoTree`: nested set, closure and materialized path trees as arrays or entities,
  subtree and depth pushdown, custom query conditions, and an `activeTrail()` query that loads only the rows a menu
  needs. Requires Doctrine ORM 3.6+ and gedmo/doctrine-extensions 3.20+ (both optional).
- Documentation with 52 tested examples, a design specification, benchmarks, and a Docker-based development setup.

[Unreleased]: https://github.com/ghispi/tree-transform/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/ghispi/tree-transform/compare/v0.3.0...v1.0.0
[0.3.0]: https://github.com/ghispi/tree-transform/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/ghispi/tree-transform/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/ghispi/tree-transform/releases/tag/v0.1.0
