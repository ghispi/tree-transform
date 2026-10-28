---
name: gedmo-bridge
description: Working on the Doctrine/Gedmo bridge of tree-transform (src/Bridge/Gedmo, tests/Integration) — how queries are built per Gedmo strategy, pushdown rules that must match PHP semantics, DQL pitfalls, and how to write integration tests with Gedmo entities on SQLite. Use when changing GedmoTree/TreeConfig, adding a pushdown, or debugging bridge results.
---

# Gedmo bridge

## How it works

- `TreeConfig::read()` reads the TreeListener configuration (strategy, left/right/level/root/parent/path,
  separator, `path_ends_with_separator`, closure class) plus Doctrine metadata (single id, root as association).
- `GedmoTree::select()` selects the node plus scalar columns `tt_id`, `tt_parent`, `tt_root`, `tt_left`,
  `tt_right`, `tt_path`; the source reads those with `ArrayKeyAccessor`, and a `Map` stage (`unwrap()`) hands
  users the plain node (array with `parent`/`root` ids, or the entity).
- Queries run inside a `LazySource`, so a `Tree` from the bridge re-runs its query for every output.
- Per strategy: nested → `ORDER BY tt_root, lft` + `NestedSetSource`; materializedPath → `ORDER BY path` +
  `MaterializedPathSource`; closure → `AdjacencyListSource` (subtree via join on the closure table, the anchor's
  parent passed as `rootParent`).

## Rules

- **Pushdown must equal PHP semantics.** Every SQL restriction (subtree, maxDepth, active trail) needs an
  integration test comparing it with the same tree filtered in PHP. Skip a pushdown when it would change the
  meaning (e.g. no level pushdown together with `modify`, because removed rows shift depths).
- **DQL pitfalls:** associations need `IDENTITY(alias.assoc)`; array hydration of `SELECT n` omits associations;
  `toIterable()` with mixed entity/scalar results needs ORM ≥ 3.6; LIKE escaping uses `ESCAPE '!'` and escapes
  `!`, `%`, `_` (no reliance on a database's default escape character); materialized-path subtrees must match
  `path = :exact OR path LIKE prefix+separator+'%'` when paths don't end with the separator.
- Rows removed by `modify` disappear before the tree is built: nested set and materialized path promote their
  children (sources run with gaps allowed), closure drops orphans. Document any change in `docs/gedmo.md`.

## Integration tests

- `tests/Integration/GedmoTestCase` builds an SQLite in-memory EntityManager with `TreeListener` using
  `AttributeReader` (without it Gedmo falls back to the annotation reader and fails), and
  `enableNativeLazyObjects(true)`. `persistExample()` persists the A..I running example; `outline()` renders
  `A .B ..C`.
- Entities live in `tests/Integration/Entity` and implement `TestNode`. Closure entities need
  `repositoryClass: ClosureTreeRepository::class` and a closure class extending `AbstractClosure`.
- Cover each Gedmo strategy (data provider), both hydrations, root as association and as plain column, entities
  with and without a level field, and paths with special characters.
- Run: `docker compose run --rm php vendor/bin/phpunit --testsuite integration`.
