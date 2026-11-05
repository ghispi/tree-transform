# Public API

This page lists every public type of `ghispi/tree-transform` and what you do with it. Everything listed here, and
nothing else, is the API that 1.0 freezes. Signatures and option tables are on the linked pages; this page is the
checklist.

All classes are under `Ghispi\TreeTransform\`. **Role** says how you use a type:

- **use**: construct it or call its methods.
- **implement**: an extension interface you implement yourself ([Extending](extending.md)).
- **read**: you receive it from the library and read it; do not construct it.

## Compatibility promise

From 1.0.0 the library follows [Semantic Versioning](https://semver.org/): a minor or patch release does not break
code that uses the API on this page as documented. A break needs a new major version and an entry in
`UPGRADE.md`. Before 1.0, a minor release (0.x → 0.y) may break the API, and the changelog says how to migrate.

What the promise covers:

- **Names**: public types, methods, constants, enum cases and their string values, and **parameter names**.
  Named arguments make parameter names part of the API, so `toNestedSet(levelBase: 1)` keeps working.
- **Signatures**: parameter order, types and defaults, and return types. A minor release may add an optional
  parameter at the end, widen a parameter type, or narrow a return type.
- **Behaviour**: the nodes each source, strategy and stage yields and their order and depths, the shapes the sinks
  build, and which exception class is thrown for which problem. A bug fix may change output that contradicts the
  documentation, and the changelog says so.
- **Extension interfaces** (`Cursor`, `Source`, `FilterStrategy`, `Stage`, `Sink`, `Predicate`, `Accessor`): a minor
  release never adds a method to them, since that would break your implementations. New capabilities come as new
  interfaces you can opt into.
- **Value objects you read** (`TreeConfig`, `Annotated`, `TreeNode`, `Ancestors`, exceptions): a minor release may
  add properties or methods. Do not construct the ones marked **read** below.
- **Supported versions**: a minor release may add support for new PHP, Doctrine ORM or gedmo/doctrine-extensions
  versions. It may drop a version only once that version no longer gets security fixes upstream.
- **Final classes**: classes are `final`. Wrap or compose them, don't extend them. Removing `final` is not a break.

Two checks guard the promise on every push. `tests/Api/public-api.txt` records the API from reflection, so any
change to it, parameter renames included, shows up as a diff (`make api-snapshot` updates it). The
[Roave BC checker](https://github.com/Roave/BackwardCompatibilityCheck) compares with the latest release
(`make bc-check`).

## Not part of the API

- Everything in `Internal\`.
- Methods and constructors marked `@internal` in their docblock. On public classes these are:
  - `stream()` and `drain()` on the built-in sources, strategies, stages and sinks, and `DepthWindow::window()`.
  - `Ancestors::enter()`.
  - `AllOf::predicates()`, `AnyOf::predicates()`, `Callback::closure()` and `Not::inner()`.
  - `CycleDetectedException::forIds()` and `OrphanNodeException::forParent()`.
  - The `TreeConfig` constructor and `TreeConfig::read()`.
- Exception messages, benchmark numbers, and the order in which a strategy calls your predicate (beyond the "at
  most once per node" rule).

## Facade

| Type | Role | Notes |
|---|---|---|
| `Tree` | use | `from()`, `fromNestedSet()`, `fromAdjacencyList()`, `fromPreorderedAdjacencyList()`, `fromMaterializedPath()`, `fromNestedArray()`, `fromDepthList()`, `fromFlatList()`, `lazy()`; stages `filter()`, `activeTrail()`, `annotate()`, `map()`, `maxDepth()`, `depthWindow()`, `limit()`, `sortSiblings()`, `pipe()`; outputs `cursor()`, `iterate()` / `foreach`, `toNestedArray()`, `toFlatList()`, `toAdjacencyList()`, `toNestedSet()`, `count()`, `into()`. Immutable; every stage returns a new `Tree` |

## Cursor

| Type | Role | Notes |
|---|---|---|
| `Cursor\Cursor` | implement / use | `next()`, `node()`, `depth()`, `skipSubtree()`. Contract in [Extending → Source](extending.md#source). Free resources such as database cursors in `__destruct()`: a pipeline can stop reading early (`limit()`) |

## Sources ([Sources](sources.md))

| Type | Role | Notes |
|---|---|---|
| `Source\Source` | implement | `open(): Cursor` |
| `Source\NestedSetSource` | use | `rows`, `fields`, `accessor` |
| `Source\AdjacencyListSource` | use | `rows`, `fields`, `accessor`, `orphans`, `rootParent`, `siblingOrder` |
| `Source\PreorderedAdjacencySource` | use | `rows`, `fields`, `accessor`, `rootParent` |
| `Source\MaterializedPathSource` | use | `rows`, `fields`, `accessor`, `allowGaps` |
| `Source\NestedArraySource` | use | `roots`, `childrenField`, `accessor` |
| `Source\DepthListSource` | use | `pairs()` (`[depth, node]` or `toFlatList()` entries), `keyed()` (`depth => node`) |
| `Source\LazySource` | use | `factory` (closure returning a `Source`) |
| `Source\NestedSetFields` | use | `left`, `right`, `root`, `level`; `gedmo()`. Shared by `NestedSetSource` and `NestedSetSink` |
| `Source\AdjacencyListFields` | use | `id`, `parent`; `gedmo()`. Shared by the adjacency sources and `AdjacencyListSink` |
| `Source\MaterializedPathFields` | use | `path`, `separator`; `gedmo()` |
| `Source\OrphanPolicy` | use | `Throw`, `AsRoot`, `Drop` |

## Accessors ([Sources → Objects](sources.md))

| Type | Role | Notes |
|---|---|---|
| `Accessor\Accessor` | implement | `reader(string $field): Closure` |
| `Accessor\AutoAccessor` | use | default everywhere |
| `Accessor\ArrayKeyAccessor`, `PropertyAccessor`, `GetterAccessor` | use | |
| `Accessor\PrivatePropertyAccessor` | use | `class` |

## Predicates ([Predicates](predicates.md))

| Type | Role | Notes |
|---|---|---|
| `Predicate\Predicate` | implement | `matches(mixed $node, int $depth, Ancestors $ancestors): bool` |
| `Predicate\Callback` | use | `new Callback($closure)`, `Callback::of($predicateOrClosure)` |
| `Predicate\AllOf`, `AnyOf` | use | `...$predicates` |
| `Predicate\Not` | use | `predicate` |
| `Predicate\DepthRange` | use | `min`, `max` |
| `Predicate\FieldEquals` | use | `field`, `value`, `accessor` |
| `Predicate\FieldIn` | use | `field`, `values`, `accessor` |
| `Predicate\Ancestors` | read | `count()`, `parent()`, `at()`, `some()`, `every()`, `toArray()`, `foreach`; `Ancestors::of($path)` to test predicates |
| `Predicate\AncestorTracker` | use | for custom strategies: `enter()`, `ancestors()` |

## Strategies ([Strategies](strategies.md))

| Type | Role | Notes |
|---|---|---|
| `Strategy\FilterStrategy` | implement | `apply(Cursor $input, Predicate $predicate): Cursor` |
| `Strategy\Prune`, `KeepAncestors`, `Promote` | use | `onMatch` |
| `Strategy\Placeholder` | use | `factory`, `onMatch` |
| `Strategy\ActiveTrail` | use | `siblings`, `activeChildrenDepth`, `multiple` |
| `Strategy\MinMatches` | use | `min` |
| `Strategy\Annotate` | use | `combine` |
| `Strategy\OnMatch` | use | `EvaluateDescendants`, `KeepDescendants`, `DropDescendants` |
| `Strategy\MatchState` | read | `Match`, `AncestorOfMatch`, `DescendantOfMatch`, `None`; string values are part of the API |
| `Strategy\Annotated` | read | `node`, `state` |

## Stages ([Stages](stages.md))

| Type | Role | Notes |
|---|---|---|
| `Stage\Stage` | implement | `apply(Cursor $input): Cursor` |
| `Stage\Filter` | use | `predicate`, `strategy` |
| `Stage\Map` | use | `transform` |
| `Stage\MaxDepth` | use | `max` |
| `Stage\DepthWindow` | use | `min`, `max` |
| `Stage\Limit` | use | `count` |
| `Stage\SortSiblings` | use | `compare` |

## Sinks ([Outputs](outputs.md))

| Type | Role | Notes |
|---|---|---|
| `Sink\Sink` | implement | `consume(Cursor $cursor): mixed` |
| `Sink\NestedArraySink` | use | `childrenField`, `omitEmptyChildren`, `nodeFactory` |
| `Sink\FlatListSink` | use | |
| `Sink\AdjacencyListSink` | use | `fields`, `accessor`, `rootParent`, `nodeFactory` |
| `Sink\NestedSetSink` | use | `fields`, `levelBase`, `nodeFactory`, `idField`, `accessor` |
| `Sink\CountSink` | use | |
| `Sink\TreeNode` | read | `node`, `children`, `isLeaf()`, `count()`, `foreach` |

## Exceptions

Every exception the library throws implements `Exception\TreeTransformException`.

| Type | Extends | Notes |
|---|---|---|
| `Exception\TreeTransformException` | — | marker interface |
| `Exception\InvalidTreeException` | `\UnexpectedValueException` | `nodeId()`; `InvalidTreeException::at($id, $problem)` for custom sources |
| `Exception\CycleDetectedException` | `InvalidTreeException` | `ids()` |
| `Exception\OrphanNodeException` | `InvalidTreeException` | `parentId()`, `childCount()` |
| `Exception\InvalidArgumentException` | `\InvalidArgumentException` | bad options, unsupported payloads |
| `Exception\LogicException` | `\LogicException` | misuse, e.g. re-reading a used generator |
| `Exception\OutOfRangeException` | `\OutOfRangeException` | `Ancestors::at()` |

## Doctrine / Gedmo bridge ([Doctrine and Gedmo](gedmo.md))

| Type | Role | Notes |
|---|---|---|
| `Bridge\Gedmo\GedmoTree` | use | `tree()`, `activeTrail()`, `config()` |
| `Bridge\Gedmo\Hydration` | use | `Arrays`, `Entities` |
| `Bridge\Gedmo\TreeConfig` | read | readonly properties; new properties may be added in minor releases |
| `Bridge\Gedmo\TreeStrategy` | read | `Nested`, `Closure`, `MaterializedPath` (values are Gedmo's strategy names) |
