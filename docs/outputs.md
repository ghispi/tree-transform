# Outputs

An output consumes the node stream and builds the result. Calling one runs the whole pipeline. Outputs are
implemented as sinks; `Tree` has a method for each built-in one, and `into($sink)` takes any `Sink`.

| `Tree` method | Sink | Result | Memory |
|---|---|---|---|
| [`iterate()`](#iterate), `foreach ($tree …)` | — | `Generator<int depth, mixed node>` | O(1) beyond the pipeline |
| [`toNestedArray()`](#nested-arrays) | `NestedArraySink` | list of roots with children | O(output) |
| [`toFlatList()`](#flat-list) | `FlatListSink` | `list<array{depth: int, node: mixed}>` | O(output) |
| [`toAdjacencyList()`](#adjacency-list) | `AdjacencyListSink` | pre-order list with rewritten parent ids | O(output) |
| [`toNestedSet()`](#nested-set) | `NestedSetSink` | pre-order list with recomputed `lft` / `rgt` / level | O(output) |
| [`count()`](#count) | `CountSink` | `int` | O(1) |
| [`into($sink)`](#your-own-sink) | any `Sink` | whatever the sink returns | — |
| `cursor()` | — | the raw `Cursor`, for code that drives it itself | — |

None of them modify your payloads. Array rows are copied when a key is added; objects are wrapped or passed to
your node factory.

## Nested arrays

`toNestedArray()` returns a list of roots, each with its children.

- Array payloads get a children key: `['id' => 1, …, 'children' => [...]]`.
- Object payloads are wrapped in a `TreeNode` (entities are never modified).
- A `nodeFactory` decides the shape itself.

<!-- example: outputs-nested-array -->
```php
use Ghispi\TreeTransform\Tree;

require __DIR__ . '/../vendor/autoload.php';

$rows = [
    ['id' => 1, 'title' => 'Clothing', 'lft' => 1, 'rgt' => 6],
    ['id' => 2, 'title' => 'Men',      'lft' => 2, 'rgt' => 3],
    ['id' => 3, 'title' => 'Women',    'lft' => 4, 'rgt' => 5],
];
$tree = Tree::fromNestedSet($rows)->map(fn(array $row) => ['id' => $row['id'], 'title' => $row['title']]);

echo "Default: every node gets a 'children' key\n";
echo json_encode($tree->toNestedArray()), "\n\n";

echo "omitEmptyChildren: true\n";
echo json_encode($tree->toNestedArray(omitEmptyChildren: true)), "\n\n";

echo "childrenKey: '__children' (same shape as Gedmo buildTree())\n";
echo json_encode($tree->toNestedArray(childrenKey: '__children')), "\n\n";

echo "nodeFactory: build any shape\n";
echo json_encode($tree->toNestedArray(nodeFactory: fn(array $node, array $children, int $depth) => [
    'text' => $node['title'],
    'level' => $depth,
    'nodes' => $children,
])), "\n";
```

Output:

```text
Default: every node gets a 'children' key
[{"id":1,"title":"Clothing","children":[{"id":2,"title":"Men","children":[]},{"id":3,"title":"Women","children":[]}]}]

omitEmptyChildren: true
[{"id":1,"title":"Clothing","children":[{"id":2,"title":"Men"},{"id":3,"title":"Women"}]}]

childrenKey: '__children' (same shape as Gedmo buildTree())
[{"id":1,"title":"Clothing","__children":[{"id":2,"title":"Men","__children":[]},{"id":3,"title":"Women","__children":[]}]}]

nodeFactory: build any shape
[{"text":"Clothing","level":0,"nodes":[{"text":"Men","level":1,"nodes":[]},{"text":"Women","level":1,"nodes":[]}]}]
```

Source: [`examples/outputs-nested-array.php`](../examples/outputs-nested-array.php)
<!-- /example -->

| Option | Type | Default | Effect |
|---|---|---|---|
| `childrenKey` | `string` | `'children'` | Key for the children of array payloads. `'__children'` matches Gedmo's `buildTree()` |
| `omitEmptyChildren` | `bool` | `false` | Leave the children key out of leaves |
| `nodeFactory` | `?Closure(mixed $node, list<mixed> $children, int $depth): mixed` | `null` | Builds each node. `$children` holds already-built children |

The result is built bottom-up without recursion or PHP references. Memory: the size of the result.

### Objects and TreeNode

Objects cannot get a `children` key, and the library does not modify them. Each one is wrapped in a readonly
`TreeNode` with public `node` (the original object) and `children` (a list of `TreeNode`).

<!-- example: outputs-objects -->
```php
use Ghispi\TreeTransform\Sink\TreeNode;
use Ghispi\TreeTransform\Tree;

require __DIR__ . '/../vendor/autoload.php';

final readonly class Page
{
    public function __construct(
        public string $title,
        public string $path,
    ) {}
}

$pages = [
    new Page('About', '/about'),
    new Page('Team', '/about/team'),
    new Page('Jobs', '/about/jobs'),
    new Page('Blog', '/blog'),
];

// Objects are never modified: each one is wrapped in a TreeNode with its children.
$roots = Tree::fromMaterializedPath($pages, new Ghispi\TreeTransform\Source\MaterializedPathFields(separator: '/'))
    ->toNestedArray();

foreach ($roots as $root) {
    assert($root instanceof TreeNode);
    echo $root->node->title, ' (', count($root), ' children, leaf: ', var_export($root->isLeaf(), true), ")\n";
    foreach ($root as $child) { // iterates the direct children
        assert($child instanceof TreeNode);
        echo '  ', $child->node->title, "\n";
    }
}
```

Output:

```text
About (2 children, leaf: false)
  Team
  Jobs
Blog (0 children, leaf: true)
```

Source: [`examples/outputs-objects.php`](../examples/outputs-objects.php)
<!-- /example -->

`TreeNode` is `Countable` (number of direct children) and iterable (the direct children), and has `isLeaf()`. For a
different shape (a DTO, a view model), pass a `nodeFactory`, or `map()` objects to arrays first.

## iterate

`iterate()` streams nodes in pre-order as `depth => node`. Nothing is collected, so it works for any tree size. A
`Tree` is itself iterable and does the same.

<!-- example: outputs-iterate -->
```php
use Ghispi\TreeTransform\Tree;

require __DIR__ . '/../vendor/autoload.php';

$rows = require __DIR__ . '/support/shop.php';

$tree = Tree::fromNestedSet($rows)->maxDepth(2);

// Keys are depths and repeat, so loop with foreach. iterator_to_array() would keep only one node per depth.
foreach ($tree->iterate() as $depth => $category) {
    echo str_repeat('    ', $depth), $category['title'], "\n";
}

// A Tree is iterable itself: foreach ($tree as $depth => $node) does the same.
echo "\nNodes: ", $tree->count(), "\n";
```

Output:

```text
Shop
    Clothing
        Men
        Women
    Electronics
        Phones
        Laptops
    Garden
        Tools

Nodes: 9
```

Source: [`examples/outputs-iterate.php`](../examples/outputs-iterate.php)
<!-- /example -->

Gotcha: keys are depths, so they repeat. `iterator_to_array($tree->iterate())` keeps one node per depth. Use
`iterator_to_array($tree->iterate(), false)` for a plain list of nodes, or `toFlatList()` to keep the depths.

The output of `iterate()` can feed another pipeline: `Tree::fromDepthList($tree->iterate())`. See
[Sources → Depth lists](sources.md#depth-lists).

## Flat list

`toFlatList()` collects the same pre-order entries into an array of `['depth' => int, 'node' => mixed]`, e.g. for
an indented `<select>` rendered by a template.

<!-- example: outputs-flat-list -->
```php
use Ghispi\TreeTransform\Tree;

require __DIR__ . '/../vendor/autoload.php';

$rows = require __DIR__ . '/support/shop.php';

$list = Tree::fromNestedSet($rows)
    ->maxDepth(1)
    ->map(fn(array $c) => $c['title'])
    ->toFlatList();

var_export($list);
echo "\n";
```

Output:

```text
array (
  0 => 
  array (
    'depth' => 0,
    'node' => 'Shop',
  ),
  1 => 
  array (
    'depth' => 1,
    'node' => 'Clothing',
  ),
  2 => 
  array (
    'depth' => 1,
    'node' => 'Electronics',
  ),
  3 => 
  array (
    'depth' => 1,
    'node' => 'Garden',
  ),
)
```

Source: [`examples/outputs-flat-list.php`](../examples/outputs-flat-list.php)
<!-- /example -->

## Adjacency list

`toAdjacencyList()` returns a pre-order list where each node's parent field points to its parent **in the
output**. After [`Promote`](strategies.md#promote) or [`depthWindow()`](stages.md#depthwindow) that differs from the
input.

<!-- example: outputs-adjacency-list -->
```php
use Ghispi\TreeTransform\Strategy\Promote;
use Ghispi\TreeTransform\Tree;

require __DIR__ . '/../vendor/autoload.php';

$rows = require __DIR__ . '/support/shop.php';

// Only categories with products, moved up to the nearest kept ancestor: parents are rewritten.
$list = Tree::fromNestedSet($rows)
    ->filter(fn(array $c) => $c['products'] > 0, new Promote())
    ->map(fn(array $c) => ['id' => $c['id'], 'title' => $c['title']])
    ->toAdjacencyList(idField: 'id', parentField: 'parent_id', rootParent: 0);

foreach ($list as $row) {
    printf("%-3d %-8s parent_id: %d\n", $row['id'], $row['title'], $row['parent_id']);
}

// Objects cannot get a parent field set; build the row with a factory instead.
$pairs = Tree::fromNestedSet($rows)
    ->maxDepth(1)
    ->toAdjacencyList(nodeFactory: fn(array $c, int|string|null $parentId, int $depth) => "{$c['id']}->" . ($parentId ?? 'null'));
echo "\n", implode(', ', $pairs), "\n";
```

Output:

```text
3   Men      parent_id: 0
4   Shirts   parent_id: 3
10  Cases    parent_id: 0
11  Laptops  parent_id: 0

1->null, 2->1, 8->1, 12->1
```

Source: [`examples/outputs-adjacency-list.php`](../examples/outputs-adjacency-list.php)
<!-- /example -->

| Option | Type | Default | Effect |
|---|---|---|---|
| `idField` | `string` | `'id'` | Field the id is read from |
| `parentField` | `string` | `'parent_id'` | Field the parent id is written to (array payloads) |
| `rootParent` | `mixed` | `null` | Parent value written for roots, e.g. `0` |
| `nodeFactory` | `?Closure(mixed $node, int\|string\|null $parentId, int $depth): mixed` | `null` | Builds each output row. Required for object payloads |

Object payloads without a `nodeFactory` throw `InvalidArgumentException`. The id is read with `AutoAccessor`; to
read it another way, use `new AdjacencyListSink(…, accessor: $accessor)` with `into()`.

## Nested set

`toNestedSet()` returns a pre-order list with recomputed left, right and level values — to store a filtered or
restructured tree, or to hand it to code that expects nested sets.

<!-- example: outputs-nested-set -->
```php
use Ghispi\TreeTransform\Tree;

require __DIR__ . '/../vendor/autoload.php';

$rows = require __DIR__ . '/support/shop.php';

// Drop the Electronics branch and renumber: left/right/level are recomputed for the result.
$rows = Tree::fromNestedSet($rows)
    ->filter(fn(array $c) => $c['slug'] !== 'electronics')
    ->maxDepth(1)
    ->map(fn(array $c) => ['id' => $c['id'], 'title' => $c['title']])
    ->toNestedSet(leftField: 'lft', rightField: 'rgt', levelField: 'lvl', levelBase: 0);

foreach ($rows as $row) {
    printf("%-2d %-9s lft %2d  rgt %2d  lvl %d\n", $row['id'], $row['title'], $row['lft'], $row['rgt'], $row['lvl']);
}

// Several roots numbered from 1 each (Gedmo with a TreeRoot column).
$forest = Tree::fromNestedSet(require __DIR__ . '/support/shop.php')
    ->depthWindow(1, 1)
    ->map(fn(array $c) => ['title' => $c['title']])
    ->toNestedSet(levelField: null, restartPerRoot: true);
echo "\n";
foreach ($forest as $row) {
    echo "{$row['title']}: {$row['lft']}..{$row['rgt']}\n";
}
```

Output:

```text
1  Shop      lft  1  rgt  6  lvl 0
2  Clothing  lft  2  rgt  3  lvl 1
12 Garden    lft  4  rgt  5  lvl 1

Clothing: 1..2
Electronics: 1..2
Garden: 1..2
```

Source: [`examples/outputs-nested-set.php`](../examples/outputs-nested-set.php)
<!-- /example -->

| Option | Type | Default | Effect |
|---|---|---|---|
| `leftField` | `string` | `'lft'` | Field for the left value |
| `rightField` | `string` | `'rgt'` | Field for the right value |
| `levelField` | `?string` | `'lvl'` | Field for the level; `null` leaves it out |
| `levelBase` | `int` | `0` | Level of roots (Gedmo uses 0; some schemas 1) |
| `restartPerRoot` | `bool` | `false` | Number every root's tree from 1 (Gedmo with a `TreeRoot` column) |
| `nodeFactory` | `?Closure(mixed $node, int $left, int $right, int $depth): mixed` | `null` | Builds each output row. Required for object payloads |

The right value of a node is known only when its subtree ends, so the whole result is collected first. Memory:
O(output).

## count

`count()` returns the number of nodes in the result without collecting them.

```php
$visibleCategories = Tree::fromNestedSet($rows)->filter($isVisible)->count();
```

## Your own sink

`into()` accepts any `Sink`, for example one that renders HTML in a single pass: see
[Extending → Sink](extending.md#sink).
