# Predicates

A predicate decides whether a node matches. What happens to matching and non-matching nodes is up to the
[strategy](strategies.md).

```php
interface Predicate
{
    public function matches(mixed $node, int $depth, Ancestors $ancestors): bool;
}
```

Everywhere a predicate is expected you can pass a `Predicate` object or a closure.

## Closures

A closure can take one, two or three parameters: the node, its depth (0 for roots), and a lazy view of its
ancestors. PHP ignores the arguments a closure does not declare.

<!-- example: predicates-closures -->
```php
use Ghispi\TreeTransform\Predicate\Ancestors;
use Ghispi\TreeTransform\Tree;

require __DIR__ . '/../vendor/autoload.php';

$printTree = require __DIR__ . '/support/print-tree.php';

$categories = [
    ['title' => 'Shop', 'active' => true, 'children' => [
        ['title' => 'Clothing', 'active' => true, 'children' => [
            ['title' => 'Men', 'active' => true, 'children' => [
                ['title' => 'Shirts', 'active' => true],
            ]],
            ['title' => 'Sale', 'active' => false],
        ]],
    ]],
];
$tree = Tree::fromNestedArray($categories);
$title = fn(array $c) => $c['title'];

// One parameter: the node.
echo $printTree($tree->filter(fn(array $c) => $c['active']), $title), "\n";

// Two parameters: the node and its depth (0 for roots).
echo $printTree($tree->filter(fn(array $c, int $depth) => $depth <= 2), $title), "\n";

// Three parameters: the node, its depth and a lazy view of its ancestors.
echo $printTree(
    $tree->filter(fn(array $c, int $depth, Ancestors $ancestors) => !$ancestors->some(fn(array $a) => $a['title'] === 'Men')),
    $title,
), "\n";

// The result is cast to bool, so database values such as "1" / "0" work as they are.
$rows = [['title' => 'Shop', 'active' => '1', 'children' => [['title' => 'Sale', 'active' => '0']]]];
echo $printTree(Tree::fromNestedArray($rows)->filter(fn(array $c) => $c['active']), $title);
```

Output:

```text
Shop
└─ Clothing
   └─ Men
      └─ Shirts

Shop
└─ Clothing
   ├─ Men
   └─ Sale

Shop
└─ Clothing
   ├─ Men
   └─ Sale

Shop
```

Source: [`examples/predicates-closures.php`](../examples/predicates-closures.php)
<!-- /example -->

The result is cast to `bool`, so `"1"` / `"0"` from a database work as they are. Mind PHP's casting rules:
`"0.0"`, `"false"` and `" "` are truthy.

## Built-in predicates

| Predicate | Matches when | Notes |
|---|---|---|
| `new Callback($closure)` | the closure returns true | What closures are wrapped in. `Callback::of($p)` wraps a closure or returns a `Predicate` unchanged |
| `new FieldEquals($field, $value, ?$accessor)` | the field equals `$value` (`===`, but `5` and `'5'` are equal) | Arrays and objects, via [`AutoAccessor`](sources.md#field-access) by default |
| `new FieldIn($field, $values, ?$accessor)` | the field is one of `$values` (ints/strings) | Hash lookup, O(1) per node. `"5"` matches `5`, like array keys |
| `new DepthRange($min, $max)` | `$min <= depth <= $max` | Roots are depth 0. `$max` defaults to `PHP_INT_MAX` |
| `new AllOf(...$predicates)` | every inner predicate matches (AND) | Short-circuits. Empty: always matches |
| `new AnyOf(...$predicates)` | at least one matches (OR) | Short-circuits. Empty: never matches |
| `new Not($predicate)` | the inner predicate does not match | |

The combinators accept closures and `Predicate` objects mixed.

<!-- example: predicates-combinators -->
```php
use Ghispi\TreeTransform\Predicate\AllOf;
use Ghispi\TreeTransform\Predicate\AnyOf;
use Ghispi\TreeTransform\Predicate\DepthRange;
use Ghispi\TreeTransform\Predicate\FieldEquals;
use Ghispi\TreeTransform\Predicate\FieldIn;
use Ghispi\TreeTransform\Predicate\Not;
use Ghispi\TreeTransform\Strategy\KeepAncestors;
use Ghispi\TreeTransform\Tree;

require __DIR__ . '/../vendor/autoload.php';

$printTree = require __DIR__ . '/support/print-tree.php';

$pages = [
    ['id' => 1, 'title' => 'Home', 'status' => 'published', 'children' => [
        ['id' => 2, 'title' => 'Products', 'status' => 'published', 'children' => [
            ['id' => 3, 'title' => 'Laptops', 'status' => 'draft'],
            ['id' => 4, 'title' => 'Phones', 'status' => 'published'],
        ]],
        ['id' => 5, 'title' => 'Blog', 'status' => 'published', 'children' => [
            ['id' => 6, 'title' => 'Old news', 'status' => 'archived'],
        ]],
        ['id' => 7, 'title' => 'Contact', 'status' => 'published'],
    ]],
];
$tree = Tree::fromNestedArray($pages);
$title = fn(array $page) => $page['title'];

echo "FieldEquals('status', 'published'):\n";
echo $printTree($tree->filter(new FieldEquals('status', 'published')), $title);

echo "\nFieldIn('id', [4, 6]) with KeepAncestors:\n";
echo $printTree($tree->filter(new FieldIn('id', [4, 6]), new KeepAncestors()), $title);

echo "\nAllOf(published, DepthRange(0, 1)):\n";
echo $printTree($tree->filter(new AllOf(new FieldEquals('status', 'published'), new DepthRange(0, 1))), $title);

echo "\nNot(FieldEquals('status', 'archived')):\n";
echo $printTree($tree->filter(new Not(new FieldEquals('status', 'archived'))), $title);

echo "\nAnyOf(closure, FieldEquals) with KeepAncestors:\n";
echo $printTree($tree->filter(
    new AnyOf(fn(array $page) => str_starts_with($page['title'], 'Lap'), new FieldEquals('title', 'Contact')),
    new KeepAncestors(),
), $title);
```

Output:

```text
FieldEquals('status', 'published'):
Home
├─ Products
│  └─ Phones
├─ Blog
└─ Contact

FieldIn('id', [4, 6]) with KeepAncestors:
Home
├─ Products
│  └─ Phones
└─ Blog
   └─ Old news

AllOf(published, DepthRange(0, 1)):
Home
├─ Products
├─ Blog
└─ Contact

Not(FieldEquals('status', 'archived')):
Home
├─ Products
│  ├─ Laptops
│  └─ Phones
├─ Blog
└─ Contact

AnyOf(closure, FieldEquals) with KeepAncestors:
Home
├─ Products
│  └─ Laptops
└─ Contact
```

Source: [`examples/predicates-combinators.php`](../examples/predicates-combinators.php)
<!-- /example -->

`FieldEquals` compares with `===`, except that integers and integer strings are equal: `new FieldEquals('id', 5)`
matches a row with `'id' => '5'`, as PDO often returns. `FieldIn` behaves the same way.

`DepthRange` with `Prune` cuts everything below the range — use [`maxDepth()`](stages.md#maxdepth) for that, it reads
better. `DepthRange` is useful inside `AllOf` / `AnyOf`, or with `KeepAncestors` and `Promote`.

## Ancestors

The third argument is an `Ancestors` view of the node's ancestors, root first. It is lazy: the filter keeps the
current path in a depth-indexed array it overwrites as it goes (one array write per node, O(depth) memory), and no
list is built unless you ask for one.

<!-- example: predicates-ancestors -->
```php
use Ghispi\TreeTransform\Predicate\Ancestors;
use Ghispi\TreeTransform\Strategy\Promote;
use Ghispi\TreeTransform\Tree;

require __DIR__ . '/../vendor/autoload.php';

$printTree = require __DIR__ . '/support/print-tree.php';

$pages = [
    ['title' => 'Home', 'published' => true, 'children' => [
        ['title' => 'Blog', 'published' => true, 'children' => [
            ['title' => 'Drafts', 'published' => false, 'children' => [
                ['title' => 'Upcoming launch', 'published' => true],
            ]],
            ['title' => 'Hello world', 'published' => true],
        ]],
    ]],
];
$tree = Tree::fromNestedArray($pages);

// Pages under an unpublished section stay hidden, even if they are published themselves. Prune does this anyway;
// with Promote, "Upcoming launch" would otherwise move up under "Blog".
$visible = fn(array $page, int $depth, Ancestors $ancestors) => $page['published']
    && $ancestors->every(fn(array $parent) => $parent['published']);
echo $printTree($tree->filter($visible, new Promote()), fn(array $p) => $p['title']);

// Everything the view offers, for the "Upcoming launch" page.
$tree->filter(function (array $page, int $depth, Ancestors $ancestors): bool {
    if ($page['title'] === 'Upcoming launch') {
        echo "\ncount():  ", count($ancestors), " (= depth {$depth})\n";
        echo 'parent(): ', $ancestors->parent()['title'], "\n";
        echo 'at(0):    ', $ancestors->at(0)['title'], "\n";
        echo 'some():   ', var_export($ancestors->some(fn(array $p) => !$p['published']), true), "\n";
        echo 'every():  ', var_export($ancestors->every(fn(array $p) => $p['published']), true), "\n";
        echo 'toArray() titles: ', implode(' > ', array_column($ancestors->toArray(), 'title')), "\n";
        echo 'foreach:  ';
        foreach ($ancestors as $ancestorDepth => $ancestor) {
            echo "{$ancestorDepth}:{$ancestor['title']} ";
        }
        echo "\n";
    }

    return true;
})->count();
```

Output:

```text
Home
└─ Blog
   └─ Hello world

count():  3 (= depth 3)
parent(): Drafts
at(0):    Home
some():   true
every():  false
toArray() titles: Home > Blog > Drafts
foreach:  0:Home 1:Blog 2:Drafts 
```

Source: [`examples/predicates-ancestors.php`](../examples/predicates-ancestors.php)
<!-- /example -->

| Method | Returns |
|---|---|
| `count($ancestors)` | Number of ancestors; equals the node's depth |
| `parent()` | The direct parent, or `null` for a root |
| `at(int $depth)` | The ancestor at that depth (0 = root). Throws `OutOfRangeException` outside `0 .. count() - 1` |
| `some(callable $test)` | True if an ancestor passes. Checks the nearest ancestor first, stops at the first hit |
| `every(callable $test)` | True if all ancestors pass (true for roots). Checks the nearest ancestor first |
| `toArray()` | A copy of the ancestors as a list, root first |
| `foreach ($ancestors as $depth => $ancestor)` | Iterates root first; keys are depths |

Gotchas:

- The view is reused for every node and is valid only during the predicate call. Do not store it; store
  `toArray()` if you need the ancestors later.
- Ancestors are those of the filter's **input**: the tree after earlier stages, not necessarily your raw rows. After
  `depthWindow(1)`, the old root is no longer an ancestor.
- With `Promote`, ancestors include the nodes that are being removed. That is what makes "hide everything under an
  unpublished section" work, as in the example above.

## Writing your own

Implement `Predicate` when a check needs set-up (compile a regex, normalise a search term once) or is reused:
see [Extending → Predicate](extending.md#predicate).
