# Public API

This page lists every public type of `ghispi/tree-transform` and what you do with it. Everything listed here, and
nothing else, is the API that 1.0 freezes. Signatures and option tables are on the linked pages; this page is the
checklist.

All classes are under `Ghispi\TreeTransform\`. **Role** says how you use a type:

- **use**: construct it or call its methods.
- **implement**: an extension interface you implement yourself ([Extending](extending.md)).
- **read**: you receive it from the library and read it; do not construct it.

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
