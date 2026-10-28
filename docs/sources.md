# Sources

A source reads your data and produces nodes in pre-order: each node is followed by its subtree, then by its next
sibling. Every node comes with its depth (0 for roots). Payloads are passed through unchanged.

| Source | `Tree` constructor | Input must be | Memory |
|---|---|---|---|
| [`NestedSetSource`](#nested-set) | `fromNestedSet()` | ordered by (root,) `lft` | O(depth) |
| [`AdjacencyListSource`](#adjacency-list) | `fromAdjacencyList()` | any order | O(n) |
| [`PreorderedAdjacencySource`](#adjacency-list-in-pre-order) | `fromPreorderedAdjacencyList()` | pre-order | O(depth) |
| [`MaterializedPathSource`](#materialized-path) | `fromMaterializedPath()` | ordered by path | O(depth) |
| [`NestedArraySource`](#nested-arrays-and-objects) | `fromNestedArray()` | — | O(depth) |
| [`DepthListSource`](#depth-lists) | `fromDepthList()` | pre-order `depth => node` | O(1) |
| [`LazySource`](#reading-a-source-more-than-once) | `lazy()` | a factory that returns a source | — |

All sources accept any `iterable`: arrays, generators, `IteratorAggregate`, Doctrine's `toIterable()`. All of them
support several roots (a forest). Rows can be arrays, `ArrayAccess` objects or objects; see
[Field access](#field-access).

For Doctrine entities managed by Gedmo, [`GedmoTree`](gedmo.md) picks the right source, field names and
`ORDER BY` for you.

## Nested set

Rows with left and right values, ordered by left value. When several trees share one table and each is numbered
from 1 (Gedmo's `TreeRoot`), order by the root column first and name it in `NestedSetFields`.

<!-- example: sources-nested-set -->
```php
use Ghispi\TreeTransform\Source\NestedSetFields;
use Ghispi\TreeTransform\Tree;

require __DIR__ . '/../vendor/autoload.php';

$printTree = require __DIR__ . '/support/print-tree.php';

// Two trees in one table, each numbered from 1 and told apart by "tree_id".
// SELECT * FROM category ORDER BY tree_id, lft
$rows = [
    ['id' => 1, 'tree_id' => 1, 'title' => 'Clothing',    'lft' => 1, 'rgt' => 6],
    ['id' => 2, 'tree_id' => 1, 'title' => 'Men',         'lft' => 2, 'rgt' => 3],
    ['id' => 3, 'tree_id' => 1, 'title' => 'Women',       'lft' => 4, 'rgt' => 5],
    ['id' => 4, 'tree_id' => 4, 'title' => 'Electronics', 'lft' => 1, 'rgt' => 6],
    ['id' => 5, 'tree_id' => 4, 'title' => 'Phones',      'lft' => 2, 'rgt' => 5],
    ['id' => 6, 'tree_id' => 4, 'title' => 'Cases',       'lft' => 3, 'rgt' => 4],
];

$tree = Tree::fromNestedSet($rows, new NestedSetFields(left: 'lft', right: 'rgt', root: 'tree_id'));

echo $printTree($tree, fn(array $row) => $row['title']);

// Any subset of a valid nested set works too: depth comes from the intervals, not from a level column.
// SELECT * FROM category WHERE tree_id = 4 AND lft >= 2 AND rgt <= 5 ORDER BY lft
echo "\nOnly the Phones branch:\n";
echo $printTree(Tree::fromNestedSet(array_slice($rows, 4)), fn(array $row) => $row['title']);
```

Output:

```text
Clothing
├─ Men
└─ Women
Electronics
└─ Phones
   └─ Cases

Only the Phones branch:
Phones
└─ Cases
```

Source: [`examples/sources-nested-set.php`](../examples/sources-nested-set.php)
<!-- /example -->

Depth is computed from the right values of the open ancestors. A level column is not needed and not read, so any
subset of a valid nested set works: one branch (`WHERE lft >= ? AND rgt <= ?`), or a few branches.

| `NestedSetFields` option | Type | Default | Meaning |
|---|---|---|---|
| `left` | `string` | `'lft'` | Left value field |
| `right` | `string` | `'rgt'` | Right value field |
| `root` | `?string` | `null` | Field that tells trees apart when each is numbered from 1. `null`: one numbering for all rows |

`NestedSetFields::gedmo()` returns `lft`, `rgt`, `root`.

Checks while reading (always on): left and right values are integers (numeric strings from PDO are fine),
`lft < rgt`, left values increase within a tree, and each interval lies inside its parent's interval. A violation
throws `InvalidTreeException`.

`skipSubtree()` (used by `Prune`, `maxDepth()` and others) skips rows by comparing left values only, so skipped rows
are never tested by a predicate.

## Adjacency list

Rows with an id and a parent id, in any order. Roots are rows whose parent is `null`, or equal to `rootParent`
(for schemas that use `0`).

<!-- example: sources-adjacency-list -->
```php
use Ghispi\TreeTransform\Source\AdjacencyListFields;
use Ghispi\TreeTransform\Source\OrphanPolicy;
use Ghispi\TreeTransform\Tree;

require __DIR__ . '/../vendor/autoload.php';

$printTree = require __DIR__ . '/support/print-tree.php';

// Rows in any order; roots have parent 0 here, and "position" orders siblings.
$rows = [
    ['id' => 12, 'parent' => 10, 'title' => 'Shipping', 'position' => 2],
    ['id' => 10, 'parent' => 0,  'title' => 'Help',     'position' => 2],
    ['id' => 11, 'parent' => 10, 'title' => 'Returns',  'position' => 1],
    ['id' => 20, 'parent' => 0,  'title' => 'About us', 'position' => 1],
    ['id' => 21, 'parent' => 20, 'title' => 'Careers',  'position' => 1],
];

$tree = Tree::fromAdjacencyList(
    $rows,
    new AdjacencyListFields(id: 'id', parent: 'parent'),
    rootParent: 0,
    siblingOrder: 'position',
);
echo $printTree($tree, fn(array $row) => $row['title']);

// A branch loaded on its own: its top rows point at a parent that is not in the result.
$branch = [
    ['id' => 11, 'parent_id' => 10, 'title' => 'Returns'],
    ['id' => 12, 'parent_id' => 10, 'title' => 'Shipping'],
    ['id' => 13, 'parent_id' => 12, 'title' => 'Delivery times'],
];

echo "\nOrphanPolicy::AsRoot:\n";
echo $printTree(Tree::fromAdjacencyList($branch, orphans: OrphanPolicy::AsRoot), fn(array $row) => $row['title']);

echo "\nOrphanPolicy::Drop:\n";
echo $printTree(Tree::fromAdjacencyList($branch, orphans: OrphanPolicy::Drop), fn(array $row) => $row['title']);

echo "\nOrphanPolicy::Throw (default):\n";

try {
    Tree::fromAdjacencyList($branch)->count();
} catch (Ghispi\TreeTransform\Exception\OrphanNodeException $e) {
    echo $e->getMessage(), "\n";
}
```

Output:

```text
About us
└─ Careers
Help
├─ Returns
└─ Shipping

OrphanPolicy::AsRoot:
Returns
Shipping
└─ Delivery times

OrphanPolicy::Drop:
(empty)

OrphanPolicy::Throw (default):
Parent 10 of 2 node(s) does not exist. If this value marks root nodes, pass it as "rootParent"; otherwise choose an OrphanPolicy (AsRoot or Drop).
```

Source: [`examples/sources-adjacency-list.php`](../examples/sources-adjacency-list.php)
<!-- /example -->

| Option | Type | Default | Effect |
|---|---|---|---|
| `fields` | `AdjacencyListFields` | `id`, `parent_id` | Field names. `AdjacencyListFields::gedmo()` gives `id`, `parent` |
| `accessor` | `Accessor` | `AutoAccessor` | How fields are read, see [Field access](#field-access) |
| `orphans` | `OrphanPolicy` | `Throw` | What to do with rows whose parent id does not exist |
| `rootParent` | `mixed` | `null` | A parent value that marks a root besides `null`, e.g. `0` |
| `siblingOrder` | `?string` | `null` | Field to sort siblings by (ascending, `<=>`). `null` keeps input order |

`OrphanPolicy`:

- `Throw` (default) — throws `OrphanNodeException`. Missing parents usually mean incomplete data.
- `AsRoot` — orphans become roots, e.g. when you load one branch whose top rows point outside the result.
- `Drop` — orphans are removed with their descendants.

Rows can come in any order, so `open()` reads all of them first and builds an index of integer offsets. Memory is
O(n) — the rows plus a few integers per row. Duplicate ids throw `InvalidTreeException`; parent cycles throw
`CycleDetectedException` with the ids involved.

If your rows are already in pre-order, use the streaming source below instead.

## Adjacency list in pre-order

Id/parent rows where every node is directly followed by its subtree. A Gedmo nested set queried with its parent
column and `ORDER BY root, lft` is in this shape. This source streams: O(depth) memory.

<!-- example: sources-preordered-adjacency -->
```php
use Ghispi\TreeTransform\Source\AdjacencyListFields;
use Ghispi\TreeTransform\Tree;

require __DIR__ . '/../vendor/autoload.php';

$printTree = require __DIR__ . '/support/print-tree.php';

// Gedmo nested set queried with its parent column, ordered by lft: already pre-order, so it streams.
// SELECT id, title, IDENTITY(parent) AS parent FROM category ORDER BY root, lft
$rows = (function (): Generator {
    yield ['id' => 1, 'parent' => null, 'title' => 'Shop'];
    yield ['id' => 2, 'parent' => 1,    'title' => 'Clothing'];
    yield ['id' => 3, 'parent' => 2,    'title' => 'Men'];
    yield ['id' => 4, 'parent' => 2,    'title' => 'Women'];
    yield ['id' => 5, 'parent' => 1,    'title' => 'Electronics'];
})();

echo $printTree(
    Tree::fromPreorderedAdjacencyList($rows, AdjacencyListFields::gedmo()),
    fn(array $row) => $row['title'],
);
```

Output:

```text
Shop
├─ Clothing
│  ├─ Men
│  └─ Women
└─ Electronics
```

Source: [`examples/sources-preordered-adjacency.php`](../examples/sources-preordered-adjacency.php)
<!-- /example -->

Options: `fields`, `accessor` and `rootParent`, as for the adjacency list. A row whose parent is not one of the
currently open ancestors throws `InvalidTreeException`: the rows are not in pre-order.

## Materialized path

Rows with a path like `1,4,7,` or `/blog/2025`, ordered by path. Leading and trailing separators are optional.

<!-- example: sources-materialized-path -->
```php
use Ghispi\TreeTransform\Exception\InvalidTreeException;
use Ghispi\TreeTransform\Source\MaterializedPathFields;
use Ghispi\TreeTransform\Tree;

require __DIR__ . '/../vendor/autoload.php';

$printTree = require __DIR__ . '/support/print-tree.php';

// Site pages with slash-separated paths, ordered by path with a binary collation.
// SELECT * FROM page ORDER BY path COLLATE "C"
$pages = [
    ['path' => '/about',          'title' => 'About'],
    ['path' => '/about/team',     'title' => 'Team'],
    ['path' => '/blog',           'title' => 'Blog'],
    ['path' => '/blog/2025',      'title' => '2025'],
    ['path' => '/blog/2025/hello', 'title' => 'Hello world'],
    ['path' => '/blog/2026',      'title' => '2026'],
];

$tree = Tree::fromMaterializedPath($pages, new MaterializedPathFields(path: 'path', separator: '/'));
echo $printTree($tree, fn(array $page) => $page['title']);

// A subtree read on its own: WHERE path LIKE '/blog/%'. A row not below any open row starts a new root.
echo "\nOnly the blog posts:\n";
echo $printTree(
    Tree::fromMaterializedPath(array_slice($pages, 3), new MaterializedPathFields(separator: '/')),
    fn(array $page) => $page['title'],
);

// Below a root, a missing parent row is an error by default ...
$withGap = [
    ['path' => '/blog',            'title' => 'Blog'],
    ['path' => '/blog/2025/hello', 'title' => 'Hello world'], // "/blog/2025" is missing
];

try {
    Tree::fromMaterializedPath($withGap, new MaterializedPathFields(separator: '/'))->count();
} catch (InvalidTreeException $e) {
    echo "\n", $e->getMessage(), "\n";
}

// ... and with allowGaps the row attaches to its nearest present ancestor.
echo "\nallowGaps: true\n";
echo $printTree(
    Tree::fromMaterializedPath($withGap, new MaterializedPathFields(separator: '/'), allowGaps: true),
    fn(array $page) => $page['title'],
);
```

Output:

```text
About
└─ Team
Blog
├─ 2025
│  └─ Hello world
└─ 2026

Only the blog posts:
2025
└─ Hello world
2026

Invalid tree at node '/blog/2025/hello': its parent path is missing or comes later. Order rows by path (binary collation) and include every ancestor.

allowGaps: true
Blog
└─ Hello world
```

Source: [`examples/sources-materialized-path.php`](../examples/sources-materialized-path.php)
<!-- /example -->

| `MaterializedPathFields` option | Type | Default | Meaning |
|---|---|---|---|
| `path` | `string` | `'path'` | Path field |
| `separator` | `string` | `','` | Separator between segments (not empty) |

`MaterializedPathFields::gedmo()` returns `path`, `,`. `fromMaterializedPath()` also takes `accessor` and
`allowGaps`.

How rows are attached:

- A row that is not below any open row starts a new root. So a subtree read by prefix
  (`WHERE path LIKE '/blog/%'`) works on its own.
- Below a root, every row's parent path must come before it. A missing parent throws `InvalidTreeException`,
  and so does a duplicate path.
- With `allowGaps: true`, a row whose parent is missing attaches to its nearest present ancestor, as if the missing
  rows had been removed with [`Promote`](strategies.md#promote).

Depth comes from the open path prefixes, so no level column is needed. `skipSubtree()` skips rows by path prefix.
Memory: O(depth).

**Ordering gotcha.** When every path ends with the separator (`1,4,` rather than `1,4`), plain byte-wise ordering
always yields pre-order. The risk is the database collation: locale and ICU collations may ignore punctuation, so
`1,10,` and `11,` compare as if they were `110` and `11`, which can put a row between the descendants of another
node. Order with a binary collation:

```sql
SELECT * FROM page ORDER BY path COLLATE "C";            -- PostgreSQL
SELECT * FROM page ORDER BY path COLLATE utf8mb4_bin;    -- MySQL / MariaDB
```

Violations below a root are detected and reported.

## Nested arrays and objects

Arrays with a children key, or objects with a children property or getter (for example a Doctrine collection).
Children can be any iterable; a missing, `null` or empty children field means a leaf. The children field name is the
second argument.

<!-- example: sources-nested-array -->
```php
use Ghispi\TreeTransform\Tree;

require __DIR__ . '/../vendor/autoload.php';

$printTree = require __DIR__ . '/support/print-tree.php';

// Arrays from a config file or a JSON API. Children can be under any key, and may be missing or null.
$menu = [
    ['label' => 'Home'],
    ['label' => 'Products', 'items' => [
        ['label' => 'Laptops', 'items' => null],
        ['label' => 'Phones', 'items' => [
            ['label' => 'Android'],
            ['label' => 'iPhone'],
        ]],
    ]],
    ['label' => 'Contact', 'items' => []],
];

echo $printTree(Tree::fromNestedArray($menu, childrenField: 'items'), fn(array $item) => $item['label']);
```

Output:

```text
Home
Products
├─ Laptops
└─ Phones
   ├─ Android
   └─ iPhone
Contact
```

Source: [`examples/sources-nested-array.php`](../examples/sources-nested-array.php)
<!-- /example -->

Traversal is iterative, so deep trees do not hit a recursion limit. Memory: O(depth). `skipSubtree()` does not
descend at all, so a lazily loaded Doctrine collection under a pruned node is never initialised.

## Depth lists

Pre-order `(depth, node)` entries: the library's interchange format. `DepthListSource::pairs()` reads
`[depth, node]` pairs; `DepthListSource::keyed()` (and `Tree::fromDepthList()`) reads `depth => node` keys, as
produced by `Tree::iterate()`. That lets one pipeline feed another.

<!-- example: sources-depth-list -->
```php
use Ghispi\TreeTransform\Source\DepthListSource;
use Ghispi\TreeTransform\Tree;

require __DIR__ . '/../vendor/autoload.php';

$printTree = require __DIR__ . '/support/print-tree.php';

// [depth, node] pairs, e.g. parsed from an indented outline.
$outline = DepthListSource::pairs([
    [0, 'Docs'],
    [1, 'Getting started'],
    [1, 'Guides'],
    [2, 'Menus'],
    [2, 'Search'],
    [0, 'Changelog'],
]);
echo $printTree(Tree::from($outline), fn(string $title) => $title);

// depth => node entries: the output of iterate(), so one pipeline can feed another.
$guides = Tree::from($outline)->depthWindow(1)->iterate();
echo "\n", $printTree(Tree::fromDepthList($guides), fn(string $title) => $title);
```

Output:

```text
Docs
├─ Getting started
└─ Guides
   ├─ Menus
   └─ Search
Changelog

Getting started
Guides
├─ Menus
└─ Search
```

Source: [`examples/sources-depth-list.php`](../examples/sources-depth-list.php)
<!-- /example -->

Depths are validated: the first entry has depth 0, each next one at most the previous depth + 1. Memory: O(1).

## Reading a source more than once

A `Tree` can be read many times when its input is re-iterable (arrays, `IteratorAggregate`). A generator or a
database cursor can be read only once. `Tree::lazy()` (or `new LazySource(fn() => $source)`) builds the source on
every read, for example by running the query again:

<!-- example: sources-lazy -->
```php
use Ghispi\TreeTransform\Exception\TreeTransformException;
use Ghispi\TreeTransform\Source\NestedSetSource;
use Ghispi\TreeTransform\Tree;

require __DIR__ . '/../vendor/autoload.php';

// Stands in for a database query that streams rows (e.g. Doctrine's $query->toIterable()).
$queries = 0;
$query = function () use (&$queries): Generator {
    ++$queries;
    yield from require __DIR__ . '/support/shop.php';
};

// A generator can be read once: a second output fails.
$once = Tree::fromNestedSet($query())->maxDepth(1);
echo $once->count(), " categories\n";

try {
    $once->toNestedArray();
} catch (TreeTransformException $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

// Tree::lazy() builds the source on every read, so the same Tree can produce several outputs.
$queries = 0;
$menu = Tree::lazy(fn() => new NestedSetSource($query()))->maxDepth(1);
echo "\n", $menu->count(), " categories\n";
echo count($menu->toNestedArray()), " root\n";
echo "Queries run: {$queries}\n";
```

Output:

```text
4 categories
Ghispi\TreeTransform\Exception\LogicException: This source reads a generator, which can only be traversed once. Use Tree::lazy() or LazySource to read it again.

4 categories
1 root
Queries run: 2
```

Source: [`examples/sources-lazy.php`](../examples/sources-lazy.php)
<!-- /example -->

Every source detects a used generator and throws `LogicException`. Use `Tree::lazy()` whenever you need more than
one output from a one-shot input.

## Field access

Sources, field predicates and some outputs read fields through an `Accessor`. Each accessor compiles one reader
closure per field, not per node.

| Accessor | Reads | Use for |
|---|---|---|
| `AutoAccessor` (default) | arrays and `ArrayAccess` by key; objects by public property, then getter (`getX()`, `isX()`, `hasX()`, `x()`), then a property of any visibility. Decided once per class | almost everything |
| `ArrayKeyAccessor` | `$node[$field]` | array rows, slightly faster |
| `PropertyAccessor` | `$node->$field` (public) | DTOs |
| `GetterAccessor` | `$node->getField()` (or `isX` / `hasX` / `getX` named directly) | objects with getters only |
| `PrivatePropertyAccessor($class)` | properties of any visibility, via a closure bound to the class | Doctrine entities with private tree fields |

<!-- example: sources-objects -->
```php
use Ghispi\TreeTransform\Accessor\PrivatePropertyAccessor;
use Ghispi\TreeTransform\Tree;

require __DIR__ . '/../vendor/autoload.php';

$printTree = require __DIR__ . '/support/print-tree.php';

// An entity with private tree fields and a getter for the title.
final class Category
{
    public function __construct(
        private string $title,
        private int $lft,
        private int $rgt,
    ) {}

    public function getTitle(): string
    {
        return $this->title;
    }
}

$categories = [
    new Category('Garden', 1, 8),
    new Category('Tools', 2, 5),
    new Category('Shovels', 3, 4),
    new Category('Plants', 6, 7),
];

// The default AutoAccessor finds getters, public properties and, as a last resort, private properties.
echo $printTree(Tree::fromNestedSet($categories), fn(Category $c) => $c->getTitle());

// PrivatePropertyAccessor reads private properties directly, without getters (fast; used for Doctrine entities).
$tree = Tree::fromNestedSet($categories, accessor: new PrivatePropertyAccessor(Category::class));
echo "\n", $tree->count(), " categories\n";
```

Output:

```text
Garden
├─ Tools
│  └─ Shovels
└─ Plants

4 categories
```

Source: [`examples/sources-objects.php`](../examples/sources-objects.php)
<!-- /example -->

A missing field reads as `null` (except with `GetterAccessor`, which calls the method). To read fields some other
way, write your own accessor: see [Extending](extending.md#accessor).

## Errors

Every exception the library throws implements `Ghispi\TreeTransform\Exception\TreeTransformException`:

| Exception | When |
|---|---|
| `InvalidTreeException` | The input is not a valid tree: bad ordering, overlapping intervals, missing parent path, non-integer `lft`, bad depth sequence, duplicate id |
| `OrphanNodeException` (extends `InvalidTreeException`) | Adjacency list: a parent id does not exist (with `OrphanPolicy::Throw`) |
| `CycleDetectedException` (extends `InvalidTreeException`) | Adjacency list: nodes not reachable from any root |
| `InvalidArgumentException` | A component got an invalid option, e.g. `new MaxDepth(-1)` |
| `LogicException` | Unsupported use, e.g. reading a used generator a second time |

Errors are raised while reading, so they surface when you call an output method, not when you build the `Tree`.
