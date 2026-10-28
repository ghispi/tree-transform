# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

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
