# Getting started

## Install

```bash
composer require ghispi/tree-transform
```

PHP 8.4 or newer. The library has no runtime dependencies. Doctrine ORM 3 and `gedmo/doctrine-extensions` 3 are
only needed for the [Gedmo bridge](gedmo.md).

The library is pre-1.0 (`0.x`): minor versions can still change the API.

## First filter

You have categories stored as a nested set, and want a menu of the visible ones. A hidden category hides
everything below it.

<!-- example: getting-started-quickstart -->
```php
use Ghispi\TreeTransform\Tree;

require __DIR__ . '/../vendor/autoload.php';

// Nested set rows, e.g. SELECT id, title, visible, lft, rgt FROM category ORDER BY lft
$rows = [
    ['id' => 1, 'title' => 'Shop',        'visible' => true,  'lft' => 1,  'rgt' => 12],
    ['id' => 2, 'title' => 'Clothing',    'visible' => true,  'lft' => 2,  'rgt' => 7],
    ['id' => 3, 'title' => 'Men',         'visible' => true,  'lft' => 3,  'rgt' => 4],
    ['id' => 4, 'title' => 'Women',       'visible' => false, 'lft' => 5,  'rgt' => 6],
    ['id' => 5, 'title' => 'Electronics', 'visible' => true,  'lft' => 8,  'rgt' => 11],
    ['id' => 6, 'title' => 'Phones',      'visible' => true,  'lft' => 9,  'rgt' => 10],
];

$menu = Tree::fromNestedSet($rows)
    ->filter(fn(array $category) => $category['visible'])
    ->map(fn(array $category) => ['title' => $category['title']])
    ->toNestedArray(omitEmptyChildren: true);

echo json_encode($menu, JSON_PRETTY_PRINT), "\n";
```

Output:

```json
[
    {
        "title": "Shop",
        "children": [
            {
                "title": "Clothing",
                "children": [
                    {
                        "title": "Men"
                    }
                ]
            },
            {
                "title": "Electronics",
                "children": [
                    {
                        "title": "Phones"
                    }
                ]
            }
        ]
    }
]
```

Source: [`examples/getting-started-quickstart.php`](../examples/getting-started-quickstart.php)
<!-- /example -->

What happened:

1. `Tree::fromNestedSet($rows)` reads the rows. They must be ordered by `lft`. Depth is computed from `lft`/`rgt`,
   so no level column is needed.
2. `filter()` tests each row. The default strategy, [`Prune`](strategies.md#prune), removes a non-matching node
   with its whole subtree: "Women" is hidden, and nothing below it is tested.
3. `map()` turns each kept row into the shape you want. It runs only for kept nodes.
4. `toNestedArray()` builds the result. Children go under `children`; `omitEmptyChildren: true` leaves the key out
   of leaves.

The predicate's result is cast to `bool`, so `"1"` / `"0"` from a database driver work as they are. See
[Predicates](predicates.md#closures).

## The same filter for every input format

The same tree in four formats gives the same result. Only the named constructor changes.

<!-- example: getting-started-formats -->
```php
use Ghispi\TreeTransform\Tree;

require __DIR__ . '/../vendor/autoload.php';

// The same category tree in four storage formats. Hidden: "Women" (and so its child "Dresses").

$nestedSet = [ // ORDER BY lft
    ['id' => 1, 'title' => 'Clothing', 'visible' => true,  'lft' => 1, 'rgt' => 8],
    ['id' => 2, 'title' => 'Men',      'visible' => true,  'lft' => 2, 'rgt' => 3],
    ['id' => 3, 'title' => 'Women',    'visible' => false, 'lft' => 4, 'rgt' => 7],
    ['id' => 4, 'title' => 'Dresses',  'visible' => true,  'lft' => 5, 'rgt' => 6],
];

$adjacencyList = [ // any order
    ['id' => 4, 'parent_id' => 3,    'title' => 'Dresses',  'visible' => true],
    ['id' => 1, 'parent_id' => null, 'title' => 'Clothing', 'visible' => true],
    ['id' => 2, 'parent_id' => 1,    'title' => 'Men',      'visible' => true],
    ['id' => 3, 'parent_id' => 1,    'title' => 'Women',    'visible' => false],
];

$materializedPath = [ // ORDER BY path
    ['id' => 1, 'path' => '1,',     'title' => 'Clothing', 'visible' => true],
    ['id' => 2, 'path' => '1,2,',   'title' => 'Men',      'visible' => true],
    ['id' => 3, 'path' => '1,3,',   'title' => 'Women',    'visible' => false],
    ['id' => 4, 'path' => '1,3,4,', 'title' => 'Dresses',  'visible' => true],
];

$nestedArray = [
    ['id' => 1, 'title' => 'Clothing', 'visible' => true, 'children' => [
        ['id' => 2, 'title' => 'Men', 'visible' => true],
        ['id' => 3, 'title' => 'Women', 'visible' => false, 'children' => [
            ['id' => 4, 'title' => 'Dresses', 'visible' => true],
        ]],
    ]],
];

$visible = fn(array $category) => $category['visible'];
$titles = fn(Tree $tree) => implode(', ', array_map(
    fn(array $entry) => $entry['node']['title'] . ' (depth ' . $entry['depth'] . ')',
    $tree->filter($visible)->toFlatList(),
));

echo 'nested set:        ', $titles(Tree::fromNestedSet($nestedSet)), "\n";
echo 'adjacency list:    ', $titles(Tree::fromAdjacencyList($adjacencyList)), "\n";
echo 'materialized path: ', $titles(Tree::fromMaterializedPath($materializedPath)), "\n";
echo 'nested array:      ', $titles(Tree::fromNestedArray($nestedArray)), "\n";
```

Output:

```text
nested set:        Clothing (depth 0), Men (depth 1)
adjacency list:    Clothing (depth 0), Men (depth 1)
materialized path: Clothing (depth 0), Men (depth 1)
nested array:      Clothing (depth 0), Men (depth 1)
```

Source: [`examples/getting-started-formats.php`](../examples/getting-started-formats.php)
<!-- /example -->

| Format | Constructor | Rows must be | Memory |
|---|---|---|---|
| Nested set | `Tree::fromNestedSet($rows, $fields)` | ordered by `lft` (by root, then `lft`, for several trees) | O(depth) |
| Adjacency list | `Tree::fromAdjacencyList($rows, $fields)` | in any order | O(n) |
| Adjacency list in pre-order | `Tree::fromPreorderedAdjacencyList($rows, $fields)` | parents directly followed by their subtrees | O(depth) |
| Materialized path | `Tree::fromMaterializedPath($rows, $fields)` | ordered by path, binary collation | O(depth) |
| Nested arrays / objects | `Tree::fromNestedArray($roots, 'children')` | — | O(depth) |
| `depth => node` stream | `Tree::fromDepthList($entries)` | pre-order | O(1) |
| Any `Source` | `Tree::from($source)`, `Tree::lazy(fn() => $source)` | — | — |

Field names, root handling, orphans and other options are on the [Sources](sources.md) page. Using Doctrine
entities with Gedmo? Start with [Doctrine and Gedmo](gedmo.md).

## Next steps

- Search in a tree and keep the path to each hit: [`KeepAncestors`](strategies.md#keepancestors).
- Build a navigation menu for the current page: [Recipes → Navigation menu](recipes.md#navigation-menu).
- Get a different output shape: [Outputs](outputs.md).
