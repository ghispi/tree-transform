# Doctrine and Gedmo trees

`GedmoTree` loads trees managed by the [Gedmo Tree extension](https://github.com/doctrine-extensions/DoctrineExtensions/blob/main/doc/tree.md)
straight from Doctrine ORM and returns a regular [`Tree`](index.md), so every strategy, stage and output works
on it. It knows each Gedmo strategy's columns and ordering, streams the rows, and pushes structural limits
(subtree, depth, active trail) into SQL so fewer rows are loaded.

Supported: `doctrine/orm` 3.6+, `gedmo/doctrine-extensions` 3.20+, with the `nested`, `closure` and `materializedPath`
tree types. Both packages are optional dependencies of this library; install them in your application.

## Setup

```php
use Ghispi\TreeTransform\Bridge\Gedmo\GedmoTree;

$gedmo = new GedmoTree($entityManager);
```

`GedmoTree` finds the `TreeListener` registered with the entity manager. If you register it somewhere unusual,
pass it as the second argument: `new GedmoTree($entityManager, $treeListener)`.

## Load a tree

```php
$tree = $gedmo->tree(Category::class);

$menu = $tree
    ->filter(fn (array $category) => $category['published'])
    ->toNestedArray();
```

`tree()` returns a lazy `Tree`: the query runs when you call an output method, and runs again for every
output, so one configured tree can produce several results.

Options:

| Option | Default | Effect |
|---|---|---|
| `root` | `null` | Entity or id. Only its subtree is loaded, with that node as the only root. |
| `maxDepth` | `null` | Levels below the root(s) to load; `0` loads only the root(s). Uses the level column in SQL when the entity has one (not together with `modify`, because removed rows shift depths; depth then counts the tree as built). |
| `hydration` | `Hydration::Arrays` | `Hydration::Entities` returns managed entities instead of arrays. |
| `modify` | `null` | `fn (QueryBuilder $qb, string $alias)` to add your own conditions or joins. |

```php
// The "Clothing" branch, two levels deep, as entities:
$tree = $gedmo->tree(Category::class, root: $clothing, maxDepth: 2, hydration: Hydration::Entities);
```

### Arrays or entities

With `Hydration::Arrays` (the default) each node is an array of the entity's fields. The `parent` and `root`
associations are included as ids:

```php
['id' => 5, 'title' => 'Shirts', 'lft' => 4, 'rgt' => 9, 'lvl' => 2, 'parent' => 3, 'root' => 1]
```

Arrays are much faster and lighter than entities. Use them for menus, APIs and exports.

With `Hydration::Entities` nodes are managed entities, and outputs that cannot add children to an object wrap
them in `TreeNode` (see [outputs](outputs.md)). Every loaded entity stays in the entity manager's identity map
until you call `$entityManager->clear()`. For large trees, prefer arrays.

### Filtering in SQL with `modify`

Conditions added with `modify` remove rows before the tree is built:

```php
$tree = $gedmo->tree(
    Category::class,
    modify: fn (QueryBuilder $qb, string $alias) => $qb->andWhere("$alias.deletedAt IS NULL"),
);
```

A removed row does not take its subtree with it. For nested sets and materialized paths its children move up
to the nearest loaded ancestor, like the [`Promote`](strategies.md) strategy. For closure trees they lose their
parent and are dropped. If you want a removed node's whole subtree gone, filter with a strategy instead:
`->filter(fn ($c) => $c['deletedAt'] === null)` uses `Prune`.

## Navigation menus: `activeTrail()`

```php
$menu = $gedmo->activeTrail(Category::class, $currentCategoryId)->toNestedArray();
```

This is the [`ActiveTrail`](strategies.md) strategy: all roots, the ancestors of the active node with their
siblings, the active node and its children. For nested sets, `GedmoTree` loads only those rows, so a menu for a
category deep in a 100,000-node tree reads a few dozen rows, not the whole table.

| Option | Default | Effect |
|---|---|---|
| `siblings` | `true` | `false` gives a breadcrumb: only the active node and its ancestors. |
| `activeChildrenDepth` | `1` | Levels shown below the active node (`0` none). |
| `hydration` | `Hydration::Arrays` | As above. |

For closure and materialized path trees the whole tree is read and filtered while streaming.

## What happens per Gedmo strategy

| Gedmo type | Query order | Source used | Subtree | Max depth | Active trail |
|---|---|---|---|---|---|
| `nested` | `root, lft` | `NestedSetSource` (streams) | `lft`/`rgt` range | level column | only the needed rows |
| `materializedPath` | `path` | `MaterializedPathSource` (streams) | `path LIKE 'prefix%'` | level column | whole tree, streamed |
| `closure` | `id` | `AdjacencyListSource` (reads all rows) | join on the closure table | closure `depth` | whole tree |

Rows are read with `Query::toIterable()`, so with array hydration the library keeps memory proportional to the
tree's depth. The database driver may still buffer the whole result: MySQL's PDO driver does by default
(`PDO::MYSQL_ATTR_USE_BUFFERED_QUERY`), outside PHP's memory limit.

## Gotchas

- **Materialized path ordering.** Pre-order relies on sorting by path byte by byte. Locale collations
  (MySQL `utf8mb4_0900_ai_ci`, PostgreSQL ICU) may ignore punctuation such as the separator and produce a
  different order. Use a binary collation for the path column: `utf8mb4_bin` on MySQL, `COLLATE "C"` on
  PostgreSQL. If rows come out of order, the source throws an `InvalidTreeException` naming the path.
- **Sibling order** comes from the tree itself: nested set position, path order, or id order for closure trees.
  To order siblings by something else, use `->sortSiblings(...)` (it reads the whole tree), or order in your own
  query and use `Tree::fromAdjacencyList(..., siblingOrder: 'position')`.
- **Composite identifiers** are not supported; tree entities need a single id column.

## Without the bridge

The bridge only saves you the configuration. With your own query, pick the matching source:

```php
$rows = $em->createQuery(
    'SELECT c.id, c.title, c.lft, c.rgt, IDENTITY(c.root) AS root
     FROM App\Entity\Category c ORDER BY root, c.lft',
)->toIterable([], AbstractQuery::HYDRATE_ARRAY);

$tree = Tree::fromNestedSet($rows, NestedSetFields::gedmo());
```

Array hydration of `SELECT c` leaves associations out, so select the root (and parent, if you need it) with
`IDENTITY()`. Without a root value, rows of different trees look like one tree numbered twice and the source
reports an ordering error.

A query result can only be read once. Wrap it in `Tree::lazy(fn () => ...)` to run it again for each output.
