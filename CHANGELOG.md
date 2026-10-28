# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Changed

- Internal pipeline rewritten on generators (roadmap 0.4): built-in sources, strategies, stages and sinks pass nodes
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

[Unreleased]: https://github.com/ghispi/tree-transform/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/ghispi/tree-transform/releases/tag/v0.1.0
