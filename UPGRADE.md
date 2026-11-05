# Upgrading to 1.0

1.0 freezes the API listed in [docs/api.md](docs/api.md). From 1.0 on, minor and patch releases do not break it
(see the [compatibility promise](docs/api.md#compatibility-promise)), and a major release comes with a section in
this file.

1.0.0 has no breaking changes since 0.3.0. If you are on 0.3, update the constraint and you are done:

```bash
composer require ghispi/tree-transform:^1.0
```

From 0.2, work through [0.2 → 0.3](#from-02-to-03). From 0.1, start with [0.1 → 0.2](#from-01-to-02). Every
change is also in the [changelog](CHANGELOG.md).

## From 0.2 to 0.3

The API review before 1.0 renamed parameters and replaced field-name arguments with field objects. Most code that
only uses defaults keeps working. Named arguments are where most breaks show up, because parameter names are part of
the API from 1.0 on.

### Children field: `childrenField` everywhere

```php
// 0.2
new NestedArraySource($roots, children: 'items');
$tree->toNestedArray(childrenKey: 'items');
new NestedArraySink(childrenKey: 'items');

// 0.3 and 1.0
new NestedArraySource($roots, childrenField: 'items');
$tree->toNestedArray(childrenField: 'items');
new NestedArraySink(childrenField: 'items');
```

Positional calls are unaffected.

### `toAdjacencyList()` and `AdjacencyListSink` take `AdjacencyListFields`

The same object the adjacency sources use. The parameter order is now `fields, accessor, rootParent, nodeFactory`.

```php
// 0.2
$tree->toAdjacencyList('key', 'up', 0);

// 0.3 and 1.0
use Ghispi\TreeTransform\Source\AdjacencyListFields;

$tree->toAdjacencyList(new AdjacencyListFields('key', 'up'), rootParent: 0);
```

### `toNestedSet()` and `NestedSetSink` take `NestedSetFields`

The same object `NestedSetSource` uses, with a new `level` field (sources ignore it). `restartPerRoot` is gone:
setting a `root` field restarts the numbering for every root. The parameters are now
`fields, levelBase, nodeFactory, idField, accessor`.

| 0.2 | 0.3 and 1.0 |
|---|---|
| `toNestedSet()` | `toNestedSet()` (default `new NestedSetFields(level: 'lvl')`, same output) |
| `toNestedSet(leftField: 'l', rightField: 'r', levelField: 'depth')` | `toNestedSet(new NestedSetFields(left: 'l', right: 'r', level: 'depth'))` |
| `toNestedSet(levelField: null)` | `toNestedSet(new NestedSetFields())` (no `level`: no level written) |
| `toNestedSet(restartPerRoot: true)` | `toNestedSet(new NestedSetFields(root: 'root', level: 'lvl'))`, or `NestedSetFields::gedmo()` |
| `toNestedSet(levelBase: 1)` | unchanged |

With a `root` field, the sink also **writes each node's root id**, read from the root node's `idField` (default
`'id'`; set it if your id column has another name). In 0.2 the root column kept its input value, which was wrong
after `Promote` or `depthWindow()`. A root without an int or string id, or two consecutive roots with the same id,
throw `InvalidArgumentException`. A `nodeFactory` receives the root id as a fifth argument; existing four-argument
factories keep working.

`NestedSetFields::gedmo()` now also sets `level: 'lvl'`.

### `Ancestors` is read-only

```php
// 0.2, in a test of your predicate
$ancestors = new Ancestors();
$ancestors->enter($root, 0);

// 0.3 and 1.0
$ancestors = Ancestors::of([$root]);
```

A custom `FilterStrategy` that tracked ancestors itself uses `Predicate\AncestorTracker` (`enter($node, $depth)`,
`ancestors()`). `Ancestors::at()` with an index out of range throws `Exception\OutOfRangeException`, which
implements `TreeTransformException`; it extends `\OutOfRangeException`, so existing `catch` blocks still work.

### Smaller renames and defaults

- `Tree::activeTrail()`: the first parameter is `predicate` (was `isActive`), as in `filter()` and `annotate()`.
- `FieldEquals` and `FieldIn`: `accessor` defaults to `new AutoAccessor()`; passing `null` is no longer accepted.
  Leave it out instead.
- `Tree::depthWindow()`: `min` defaults to 0.
- Marked `@internal` (not covered by the promise; don't call them): `AllOf::predicates()`, `AnyOf::predicates()`,
  `Callback::closure()`, `Not::inner()`, `CycleDetectedException::forIds()`, `OrphanNodeException::forParent()`.
  The exceptions have public getters instead: `InvalidTreeException::nodeId()`, `CycleDetectedException::ids()`,
  `OrphanNodeException::parentId()` and `childCount()`.

### Doctrine / Gedmo bridge

`TreeConfig::$strategy` is a `Bridge\Gedmo\TreeStrategy` enum; the string constants are removed.

```php
// 0.2
if ($config->strategy === TreeConfig::NESTED) { … }

// 0.3 and 1.0
use Ghispi\TreeTransform\Bridge\Gedmo\TreeStrategy;

if ($config->strategy === TreeStrategy::Nested) { … }
$name = $config->strategy->value; // 'nested', 'closure' or 'materializedPath', as before
```

Get a `TreeConfig` from `GedmoTree::config()`; its constructor and `TreeConfig::read()` are `@internal`.

## From 0.1 to 0.2

0.2 rewrote the internals on generators. The public contracts did not change, and your own sources, strategies,
stages and sinks keep working. Check these behaviour changes:

- **Exceptions end the traversal.** If a predicate, a callback or input validation throws, the cursor is finished:
  catching the exception and calling `next()` again returns `false` instead of the remaining nodes.
- **`NestedArraySource` rewinds on `open()`**, like the other sources: a generator that was already used throws
  `LogicException` from `open()` instead of from the first `next()`.
- **`NestedSetSource` is stricter**: a child whose right value equals its parent's (`1..4` containing `2..4`) is
  rejected as an invalid tree.
- **The internal `*Cursor` classes are gone** (`NestedSetCursor`, `PruneCursor`, …). They were `@internal`. Get a
  `Cursor` from a source's `open()`, a strategy's or stage's `apply()`, or `Tree::cursor()`.

Then continue with [0.2 → 0.3](#from-02-to-03).
