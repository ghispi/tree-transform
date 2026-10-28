# tree-transform

Streaming filtering and transformation of PHP trees — nested set, adjacency list, materialized path and nested
arrays — with pluggable filter strategies and Gedmo Doctrine support.

> Pre-1.0 (`0.x`): the API can still change between minor versions.

## Install

```bash
composer require ghispi/tree-transform
```

PHP 8.4+. No runtime dependencies. Doctrine ORM 3.6+ and `gedmo/doctrine-extensions` 3.20+ are optional, for the
[Gedmo bridge](docs/gedmo.md).

## 30-second example

Visible categories from a nested set table, as a nested array. A hidden category hides everything below it.

<!-- example: getting-started-quickstart -->
```php
use Ghispi\TreeTransform\Tree;

require 'vendor/autoload.php';

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

Source: [`examples/getting-started-quickstart.php`](examples/getting-started-quickstart.php)
<!-- /example -->

## Features

- **Any input shape**: nested set, adjacency list (any order or pre-ordered), materialized path, nested arrays,
  objects and Doctrine entities. Several roots, validation of the input, configurable field names.
- **Filter strategies** for what happens to subtrees and ancestors:
  - `Prune` — a non-matching node is removed with its subtree (the default),
  - `KeepAncestors` — matches with their path to the root (search in a tree),
  - `Promote` — remove non-matching nodes, move matching descendants up,
  - `ActiveTrail` — a navigation menu expanded down to the current page, or a breadcrumb,
  - `Annotate` — keep everything, mark hits, their ancestors and their descendants,
  - `MinMatches` — keep nodes whose subtree has at least *n* matches,
  - `Placeholder` — like `Prune`, with a stub for each run of hidden nodes,
  - `OnMatch` — keep, test or drop the descendants of a match.
- **Predicates**: closures with access to depth and a lazy view of the ancestors, `AllOf` / `AnyOf` / `Not`,
  `FieldEquals`, `FieldIn`, `DepthRange`.
- **Stages**: `map`, `maxDepth`, `depthWindow` (re-rooting), `limit`, `sortSiblings`.
- **Outputs**: nested arrays (Gedmo `__children` compatible), streaming `iterate()`, flat lists, adjacency lists with
  rewritten parents, nested sets with recomputed `lft` / `rgt`, counts.
- **Streaming**: for pre-ordered inputs, memory grows with tree depth, not size. Predicates never run inside a
  pruned subtree. Input is never modified.
- **Doctrine / Gedmo bridge**: reads the Gedmo tree configuration, streams rows with the right `ORDER BY`, and pushes
  subtree, depth and active-trail limits into SQL.
- **Extensible**: write your own Source, FilterStrategy, Stage, Sink, Predicate or Accessor.

## Documentation

- [Overview and concepts](docs/index.md) — and how to choose a strategy
- [Getting started](docs/getting-started.md)
- [Sources](docs/sources.md) · [Strategies](docs/strategies.md) · [Predicates](docs/predicates.md) ·
  [Stages](docs/stages.md) · [Outputs](docs/outputs.md)
- [Doctrine and Gedmo](docs/gedmo.md)
- [Recipes](docs/recipes.md) — menus, breadcrumbs, search, select boxes, empty categories
- [Extending](docs/extending.md) · [Performance](docs/performance.md)

Every example in the docs is a runnable script in [`examples/`](examples), checked by the test suite.

## Development

Everything runs in Docker — no PHP needed on the host.

```bash
make build install   # build the image, install dependencies
make ci              # code style, PHPStan, PHPUnit
make help            # all targets
```

## License

MIT, see [LICENSE](LICENSE).
