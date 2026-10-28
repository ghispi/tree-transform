# Stages

A stage wraps the node stream and changes it. Stages run in the order you add them. Filters are stages too.

| `Tree` method | Stage class | Effect | Memory |
|---|---|---|---|
| `filter($predicate, $strategy)` | `Filter` | Applies a [strategy](strategies.md) | see the strategy |
| `activeTrail(…)`, `annotate(…)` | `Filter` | Shortcuts for `ActiveTrail` and `Annotate` | see the strategy |
| [`map($fn)`](#map) | `Map` | Transforms each payload | O(1) |
| [`maxDepth($max)`](#maxdepth) | `MaxDepth` | Cuts everything deeper than `$max` | O(1) |
| [`depthWindow($min, $max)`](#depthwindow) | `DepthWindow` | Keeps depths `$min..$max`; nodes at `$min` become roots | O(1) |
| [`limit($count)`](#limit) | `Limit` | Stops after `$count` nodes | O(1) |
| [`sortSiblings($compare)`](#sortsiblings) | `SortSiblings` | Reorders the children of every node | **O(n)** |
| [`pipe($stage)`](#chaining-stages) | any `Stage` | Adds your own stage | — |

The examples on this page use a small shop category tree from
[`examples/support/shop.php`](../examples/support/shop.php):

```text
Shop
├─ Clothing
│  ├─ Men            4 products
│  │  ├─ Shirts     12 products
│  │  └─ Shoes
│  └─ Women
│     └─ Dresses
├─ Electronics
│  ├─ Phones
│  │  └─ Cases      30 products
│  └─ Laptops        7 products
└─ Garden
   └─ Tools
```

## map

Transforms each node: `fn(mixed $node, int $depth): mixed`. The structure stays the same. Children are attached
later by the output, so return the node without children.

<!-- example: stages-map -->
```php
use Ghispi\TreeTransform\Tree;

require __DIR__ . '/../vendor/autoload.php';

$rows = require __DIR__ . '/support/shop.php';

$calls = 0;
$menu = Tree::fromNestedSet($rows)
    ->maxDepth(1)
    ->map(function (array $category, int $depth) use (&$calls): array {
        ++$calls;

        return ['label' => $category['title'], 'url' => '/c/' . $category['slug'], 'level' => $depth];
    })
    ->toNestedArray(omitEmptyChildren: true);

print_r($menu);
echo "map() called {$calls} times for ", count($rows), " rows\n";
```

Output:

```text
Array
(
    [0] => Array
        (
            [label] => Shop
            [url] => /c/shop
            [level] => 0
            [children] => Array
                (
                    [0] => Array
                        (
                            [label] => Clothing
                            [url] => /c/clothing
                            [level] => 1
                        )

                    [1] => Array
                        (
                            [label] => Electronics
                            [url] => /c/electronics
                            [level] => 1
                        )

                    [2] => Array
                        (
                            [label] => Garden
                            [url] => /c/garden
                            [level] => 1
                        )

                )

        )

)
map() called 4 times for 13 rows
```

Source: [`examples/stages-map.php`](../examples/stages-map.php)
<!-- /example -->

Put `map()` after filters and depth limits, so it only runs for nodes that are kept: here 4 calls for 13 rows.
Filters after a `map()` see the mapped nodes.

## maxDepth

Cuts everything deeper than `$max`. Roots are depth 0, so `maxDepth(0)` keeps only the roots.

<!-- example: stages-max-depth -->
```php
use Ghispi\TreeTransform\Tree;

require __DIR__ . '/../vendor/autoload.php';

$rows = require __DIR__ . '/support/shop.php';
$printTree = require __DIR__ . '/support/print-tree.php';

// Roots are depth 0: maxDepth(1) keeps the root and its children.
echo $printTree(Tree::fromNestedSet($rows)->maxDepth(1), fn(array $c) => $c['title']);

echo "\nmaxDepth(0):\n";
echo $printTree(Tree::fromNestedSet($rows)->maxDepth(0), fn(array $c) => $c['title']);
```

Output:

```text
Shop
├─ Clothing
├─ Electronics
└─ Garden

maxDepth(0):
Shop
```

Source: [`examples/stages-max-depth.php`](../examples/stages-max-depth.php)
<!-- /example -->

The cut subtrees are skipped by the source (`skipSubtree()`), so their rows are not tested by later filters or
mapped. `$max` must be ≥ 0.

## depthWindow

Keeps depths `$min` to `$max`, both inclusive. Nodes at `$min` become roots; everything below `$max` is cut.
`$max` defaults to no limit. Use it to drop a site root, or to show "levels 2–3".

<!-- example: stages-depth-window -->
```php
use Ghispi\TreeTransform\Tree;

require __DIR__ . '/../vendor/autoload.php';

$rows = require __DIR__ . '/support/shop.php';
$printTree = require __DIR__ . '/support/print-tree.php';

// Levels 1..2: the shop root disappears, its children become roots, grandchildren of them are cut.
echo $printTree(Tree::fromNestedSet($rows)->depthWindow(1, 2), fn(array $c) => $c['title']);

echo "\ndepthWindow(2) (no upper limit):\n";
echo $printTree(Tree::fromNestedSet($rows)->depthWindow(2), fn(array $c) => $c['title']);
```

Output:

```text
Clothing
├─ Men
└─ Women
Electronics
├─ Phones
└─ Laptops
Garden
└─ Tools

depthWindow(2) (no upper limit):
Men
├─ Shirts
└─ Shoes
Women
└─ Dresses
Phones
└─ Cases
Laptops
Tools
```

Source: [`examples/stages-depth-window.php`](../examples/stages-depth-window.php)
<!-- /example -->

Nodes above `$min` are read but not returned; nodes below `$max` are skipped by the source. `depthWindow(0, $n)`
is the same as `maxDepth($n)`. See also [Recipes → Re-rooting](recipes.md#re-rooting-with-depthwindow).

## limit

Stops after `$count` nodes in pre-order, so the result is still a valid tree. It stops reading the input too: with
a database cursor, the remaining rows are never fetched.

<!-- example: stages-limit -->
```php
use Ghispi\TreeTransform\Tree;

require __DIR__ . '/../vendor/autoload.php';

$printTree = require __DIR__ . '/support/print-tree.php';

// A generator stands in for a database cursor; it reports how many rows were fetched.
$fetched = 0;
$rows = (function () use (&$fetched): Generator {
    foreach (require __DIR__ . '/support/shop.php' as $row) {
        ++$fetched;
        yield $row;
    }
})();

echo $printTree(Tree::fromNestedSet($rows)->limit(4), fn(array $c) => $c['title']);
echo "Rows fetched: {$fetched} of 13\n";
```

Output:

```text
Shop
└─ Clothing
   └─ Men
      └─ Shirts
Rows fetched: 4 of 13
```

Source: [`examples/stages-limit.php`](../examples/stages-limit.php)
<!-- /example -->

Pre-order means `limit()` goes deep before it goes wide. For "the first 3 children of every node", write a custom
stage ([Extending → Stage](extending.md#stage)).

## sortSiblings

Reorders the children of every node, and the roots, with a comparator `fn(mixed $a, mixed $b): int`. Subtrees move
with their root. Sorting is stable.

<!-- example: stages-sort-siblings -->
```php
use Ghispi\TreeTransform\Tree;

require __DIR__ . '/../vendor/autoload.php';

$rows = require __DIR__ . '/support/shop.php';
$printTree = require __DIR__ . '/support/print-tree.php';

// Alphabetical within every parent; subtrees move with their root.
$sorted = Tree::fromNestedSet($rows)
    ->sortSiblings(fn(array $a, array $b) => strcmp($a['title'], $b['title']));

echo $printTree($sorted, fn(array $c) => $c['title']);
```

Output:

```text
Shop
├─ Clothing
│  ├─ Men
│  │  ├─ Shirts
│  │  └─ Shoes
│  └─ Women
│     └─ Dresses
├─ Electronics
│  ├─ Laptops
│  └─ Phones
│     └─ Cases
└─ Garden
   └─ Tools
```

Source: [`examples/stages-sort-siblings.php`](../examples/stages-sort-siblings.php)
<!-- /example -->

`sortSiblings()` reads the whole input before it returns the first node: O(n) memory. Sort in the database
(`ORDER BY` for adjacency lists, or `siblingOrder` in [`fromAdjacencyList()`](sources.md#adjacency-list)) when you
can. Put it after filters, so fewer nodes are buffered.

## Chaining stages

Stages compose freely. Each `filter()` is its own stage with its own strategy, and runs on the output of the
previous ones.

<!-- example: stages-chaining -->
```php
use Ghispi\TreeTransform\Strategy\KeepAncestors;
use Ghispi\TreeTransform\Tree;

require __DIR__ . '/../vendor/autoload.php';

$rows = require __DIR__ . '/support/shop.php';
$printTree = require __DIR__ . '/support/print-tree.php';

// Stages run in the order they are added. Each filter() is its own stage with its own strategy.
$base = Tree::fromNestedSet($rows)
    ->filter(fn(array $c) => $c['slug'] !== 'garden'); // hide a whole section (Prune)

$search = $base
    ->filter(fn(array $c) => str_contains(strtolower($c['title']), 'es'), new KeepAncestors())
    ->depthWindow(1);

echo $printTree($search, fn(array $c) => $c['title']);

// Tree is immutable: $base is unchanged and can be reused.
echo "\n", $base->count(), " categories without Garden\n";
```

Output:

```text
Clothing
├─ Men
│  └─ Shoes
└─ Women
   └─ Dresses
Electronics
└─ Phones
   └─ Cases

11 categories without Garden
```

Source: [`examples/stages-chaining.php`](../examples/stages-chaining.php)
<!-- /example -->

Every method returns a new `Tree`, so a configured tree can be reused as a template.

Add your own stage with `pipe()`; see [Extending → Stage](extending.md#stage).
