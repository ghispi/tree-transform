# Recipes

Common tasks, each with a runnable example. Most use the shop category tree from
[`examples/support/shop.php`](../examples/support/shop.php) (nested set rows):

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

With Doctrine and Gedmo, load the tree with [`GedmoTree`](gedmo.md) instead of passing rows; everything after the
source stays the same.

## Navigation menu

Show all top-level pages, and expand the menu down to the current page: its ancestors, their siblings, and the
current page's children. That is [`ActiveTrail`](strategies.md#activetrail).

<!-- example: recipes-navigation-menu -->
```php
use Ghispi\TreeTransform\Tree;

require __DIR__ . '/../vendor/autoload.php';

// Site pages as an adjacency list, e.g. SELECT id, parent_id, title, url FROM page ORDER BY position
$pages = [
    ['id' => 1, 'parent_id' => null, 'title' => 'Home',        'url' => '/'],
    ['id' => 2, 'parent_id' => null, 'title' => 'Products',    'url' => '/products'],
    ['id' => 3, 'parent_id' => 2,    'title' => 'Laptops',     'url' => '/products/laptops'],
    ['id' => 4, 'parent_id' => 3,    'title' => 'Ultrabooks',  'url' => '/products/laptops/ultrabooks'],
    ['id' => 5, 'parent_id' => 3,    'title' => 'Gaming',      'url' => '/products/laptops/gaming'],
    ['id' => 6, 'parent_id' => 2,    'title' => 'Phones',      'url' => '/products/phones'],
    ['id' => 7, 'parent_id' => 6,    'title' => 'Android',     'url' => '/products/phones/android'],
    ['id' => 8, 'parent_id' => null, 'title' => 'Support',     'url' => '/support'],
    ['id' => 9, 'parent_id' => 8,    'title' => 'Returns',     'url' => '/support/returns'],
];
$currentUrl = '/products/laptops';

$menu = Tree::fromAdjacencyList($pages)
    ->activeTrail(fn(array $page) => $page['url'] === $currentUrl)
    ->map(fn(array $page) => $page + ['active' => $page['url'] === $currentUrl])
    ->toNestedArray();

// Render as HTML. The menu is small, so a recursive closure is fine here.
$render = function (array $items, int $indent = 0) use (&$render): string {
    $pad = str_repeat('  ', $indent);
    $html = "{$pad}<ul>\n";
    foreach ($items as $item) {
        $class = $item['active'] ? ' class="active"' : '';
        $html .= "{$pad}  <li{$class}><a href=\"{$item['url']}\">{$item['title']}</a>";
        $html .= $item['children'] === [] ? '' : "\n" . $render($item['children'], $indent + 2) . "{$pad}  ";
        $html .= "</li>\n";
    }

    return $html . "{$pad}</ul>\n";
};

echo $render($menu);
```

Output:

```html
<ul>
  <li><a href="/">Home</a></li>
  <li><a href="/products">Products</a>
    <ul>
      <li class="active"><a href="/products/laptops">Laptops</a>
        <ul>
          <li><a href="/products/laptops/ultrabooks">Ultrabooks</a></li>
          <li><a href="/products/laptops/gaming">Gaming</a></li>
        </ul>
      </li>
      <li><a href="/products/phones">Phones</a></li>
    </ul>
  </li>
  <li><a href="/support">Support</a></li>
</ul>
```

Source: [`examples/recipes-navigation-menu.php`](../examples/recipes-navigation-menu.php)
<!-- /example -->

The predicate finds the active page; `map()` marks it for the template. With Gedmo,
[`GedmoTree::activeTrail()`](gedmo.md) loads only the rows such a menu needs.

## Breadcrumb

The same strategy without siblings and without children of the current page. `multiple: false` stops testing after
the first match.

<!-- example: recipes-breadcrumb -->
```php
use Ghispi\TreeTransform\Tree;

require __DIR__ . '/../vendor/autoload.php';

$rows = require __DIR__ . '/support/shop.php';

// The trail without siblings, and nothing below the current page.
$crumbs = Tree::fromNestedSet($rows)
    ->activeTrail(fn(array $c) => $c['slug'] === 'cases', siblings: false, activeChildrenDepth: 0, multiple: false)
    ->map(fn(array $c) => $c['title'])
    ->iterate();

echo implode(' › ', iterator_to_array($crumbs, false)), "\n";
```

Output:

```text
Shop › Electronics › Phones › Cases
```

Source: [`examples/recipes-breadcrumb.php`](../examples/recipes-breadcrumb.php)
<!-- /example -->

`iterator_to_array(…, false)` is needed because `iterate()` keys are depths. Here they are unique anyway, but the
habit avoids surprises.

## Search in a tree

Show the matches with their path to the root, so the user sees where each hit lives:
[`KeepAncestors`](strategies.md#keepancestors). Add `OnMatch::KeepDescendants` to also show everything inside a
matching category.

<!-- example: recipes-search -->
```php
use Ghispi\TreeTransform\Strategy\KeepAncestors;
use Ghispi\TreeTransform\Strategy\OnMatch;
use Ghispi\TreeTransform\Tree;

require __DIR__ . '/../vendor/autoload.php';

$rows = require __DIR__ . '/support/shop.php';
$printTree = require __DIR__ . '/support/print-tree.php';

$query = 'sh';
$matches = fn(array $c) => mb_stripos($c['title'], $query) !== false;
$label = fn(array $c) => $matches($c) ? "[{$c['title']}]" : $c['title'];

// Matches with their path to the root, so the user sees where each hit lives.
echo "Search \"{$query}\":\n";
echo $printTree(Tree::fromNestedSet($rows)->filter($matches, new KeepAncestors()), $label);

// KeepDescendants also shows everything inside a matching category, without testing it.
echo "\nSearch \"phones\", with the contents of matches:\n";
echo $printTree(
    Tree::fromNestedSet($rows)->filter(
        fn(array $c) => mb_stripos($c['title'], 'phones') !== false,
        new KeepAncestors(OnMatch::KeepDescendants),
    ),
    fn(array $c) => $c['title'],
);
```

Output:

```text
Search "sh":
[Shop]
└─ Clothing
   └─ Men
      ├─ [Shirts]
      └─ [Shoes]

Search "phones", with the contents of matches:
Shop
└─ Electronics
   └─ Phones
      └─ Cases
```

Source: [`examples/recipes-search.php`](../examples/recipes-search.php)
<!-- /example -->

## Indented select box

`iterate()` streams `depth => node`, so the options are written without building a tree. `depthWindow(1)` drops
the "Shop" root and makes its children the top level.

<!-- example: recipes-select-box -->
```php
use Ghispi\TreeTransform\Tree;

require __DIR__ . '/../vendor/autoload.php';

$rows = require __DIR__ . '/support/shop.php';
$selectedId = 9;

// iterate() streams depth => node, so the options are written without building a tree in memory.
echo "<select name=\"category\">\n";
foreach (Tree::fromNestedSet($rows)->depthWindow(1)->iterate() as $depth => $category) {
    printf(
        "  <option value=\"%d\"%s>%s%s</option>\n",
        $category['id'],
        $category['id'] === $selectedId ? ' selected' : '',
        str_repeat('&nbsp;&nbsp;', $depth),
        htmlspecialchars($category['title']),
    );
}
echo "</select>\n";
```

Output:

```html
<select name="category">
  <option value="2">Clothing</option>
  <option value="3">&nbsp;&nbsp;Men</option>
  <option value="4">&nbsp;&nbsp;&nbsp;&nbsp;Shirts</option>
  <option value="5">&nbsp;&nbsp;&nbsp;&nbsp;Shoes</option>
  <option value="6">&nbsp;&nbsp;Women</option>
  <option value="7">&nbsp;&nbsp;&nbsp;&nbsp;Dresses</option>
  <option value="8">Electronics</option>
  <option value="9" selected>&nbsp;&nbsp;Phones</option>
  <option value="10">&nbsp;&nbsp;&nbsp;&nbsp;Cases</option>
  <option value="11">&nbsp;&nbsp;Laptops</option>
  <option value="12">Garden</option>
  <option value="13">&nbsp;&nbsp;Tools</option>
</select>
```

Source: [`examples/recipes-select-box.php`](../examples/recipes-select-box.php)
<!-- /example -->

## Pruning empty categories

Hide categories that have no products, neither directly nor anywhere below. A category is kept if it has products
or a descendant does — that is [`KeepAncestors`](strategies.md#keepancestors) with "has products" as the predicate.

<!-- example: recipes-prune-empty-categories -->
```php
use Ghispi\TreeTransform\Strategy\KeepAncestors;
use Ghispi\TreeTransform\Tree;

require __DIR__ . '/../vendor/autoload.php';

$rows = require __DIR__ . '/support/shop.php';
$printTree = require __DIR__ . '/support/print-tree.php';

// Keep a category if it has products itself, or if any category below it does.
// KeepAncestors keeps the path to every category with products; empty branches disappear.
$nonEmpty = Tree::fromNestedSet($rows)
    ->filter(fn(array $c) => $c['products'] > 0, new KeepAncestors());

echo $printTree($nonEmpty, fn(array $c) => $c['title'] . ($c['products'] > 0 ? " ({$c['products']})" : ''));
```

Output:

```text
Shop
├─ Clothing
│  └─ Men (4)
│     └─ Shirts (12)
└─ Electronics
   ├─ Phones
   │  └─ Cases (30)
   └─ Laptops (7)
```

Source: [`examples/recipes-prune-empty-categories.php`](../examples/recipes-prune-empty-categories.php)
<!-- /example -->

Shoes, Women, Dresses, Garden and Tools are gone. Phones stays as the parent of Cases.

To require more, use [`MinMatches`](strategies.md#minmatches): here a category is shown only if at least two
categories in its subtree, itself included, have products.

<!-- example: recipes-min-products -->
```php
use Ghispi\TreeTransform\Strategy\MinMatches;
use Ghispi\TreeTransform\Tree;

require __DIR__ . '/../vendor/autoload.php';

$rows = require __DIR__ . '/support/shop.php';
$printTree = require __DIR__ . '/support/print-tree.php';

// Show a category only if at least two categories in its subtree (itself included) have products.
echo $printTree(
    Tree::fromNestedSet($rows)->filter(fn(array $c) => $c['products'] > 0, new MinMatches(2)),
    fn(array $c) => $c['title'],
);
```

Output:

```text
Shop
├─ Clothing
│  └─ Men
└─ Electronics
```

Source: [`examples/recipes-min-products.php`](../examples/recipes-min-products.php)
<!-- /example -->

Men qualifies (Men and Shirts); Electronics too (Cases and Laptops); Phones does not (only Cases).

## Re-rooting with depthWindow

[`depthWindow($min, $max)`](stages.md#depthwindow) makes the nodes at `$min` the new roots and cuts below `$max`.
Use it for a top navigation without the site root, or for a sidebar of one section.

<!-- example: recipes-reroot -->
```php
use Ghispi\TreeTransform\Tree;

require __DIR__ . '/../vendor/autoload.php';

$rows = require __DIR__ . '/support/shop.php';
$printTree = require __DIR__ . '/support/print-tree.php';

// A top navigation bar without the "Shop" root: levels 1 and 2 only.
echo "Top navigation:\n";
echo $printTree(Tree::fromNestedSet($rows)->depthWindow(1, 2), fn(array $c) => $c['title']);

// A sidebar for one section: select the branch (here with a filter on the root path), then drop the levels above.
// With a database, select the branch with WHERE lft >= ? AND rgt <= ? instead: the source reads any subset.
$section = 'Electronics';
$sidebar = Tree::fromNestedSet($rows)
    ->filter(fn(array $c, int $depth) => $depth !== 1 || $c['title'] === $section)
    ->depthWindow(2);

echo "\nSidebar for {$section}:\n";
echo $printTree($sidebar, fn(array $c) => $c['title']);
```

Output:

```text
Top navigation:
Clothing
├─ Men
└─ Women
Electronics
├─ Phones
└─ Laptops
Garden
└─ Tools

Sidebar for Electronics:
Phones
└─ Cases
Laptops
```

Source: [`examples/recipes-reroot.php`](../examples/recipes-reroot.php)
<!-- /example -->

The sidebar first prunes the other sections at depth 1, then drops the two levels above the section's content. With
a database, select the section's rows directly (`WHERE lft > ? AND rgt < ?` for a nested set, `path LIKE ?` for a
materialized path): the sources read any subtree on its own.

## Flatten matches with Promote

A list of categories that have products, without empty levels in between: [`Promote`](strategies.md#promote)
removes the non-matching nodes and moves their matching descendants up.

<!-- example: recipes-flatten-matches -->
```php
use Ghispi\TreeTransform\Strategy\Promote;
use Ghispi\TreeTransform\Tree;

require __DIR__ . '/../vendor/autoload.php';

$rows = require __DIR__ . '/support/shop.php';
$printTree = require __DIR__ . '/support/print-tree.php';

// Only categories with products. Empty intermediate levels are removed and their matching
// descendants move up, so a category list has no dead ends.
$flat = Tree::fromNestedSet($rows)
    ->filter(fn(array $c) => $c['products'] > 0, new Promote());

echo $printTree($flat, fn(array $c) => "{$c['title']} ({$c['products']})");
```

Output:

```text
Men (4)
└─ Shirts (12)
Cases (30)
Laptops (7)
```

Source: [`examples/recipes-flatten-matches.php`](../examples/recipes-flatten-matches.php)
<!-- /example -->

Shirts stays under Men, because Men has products too. Cases moves from Electronics › Phones to the top level.
`toAdjacencyList()` and `toNestedSet()` write the new parents and nested set values, see
[Outputs](outputs.md#adjacency-list).

## Highlighting with Annotate

Show the whole tree, but highlight the hits, open their ancestors and grey out the rest:
[`Annotate`](strategies.md#annotate) with a `combine` closure that turns the `MatchState` into a CSS class.

<!-- example: recipes-highlight -->
```php
use Ghispi\TreeTransform\Strategy\MatchState;
use Ghispi\TreeTransform\Tree;

require __DIR__ . '/../vendor/autoload.php';

$rows = require __DIR__ . '/support/shop.php';

// Show the whole tree, but highlight hits, open their ancestors and grey out the rest.
$query = 'es';
$items = Tree::fromNestedSet($rows)
    ->annotate(
        fn(array $c) => str_contains(strtolower($c['title']), $query),
        fn(array $c, MatchState $state) => [
            'title' => $c['title'],
            'class' => match ($state) {
                MatchState::Match => 'hit',
                MatchState::AncestorOfMatch => 'open',
                MatchState::DescendantOfMatch => 'inside-hit',
                MatchState::None => 'dimmed',
            },
        ],
    )
    ->iterate();

foreach ($items as $depth => $item) {
    printf("%s<li class=\"%s\">%s</li>\n", str_repeat('  ', $depth), $item['class'], $item['title']);
}
```

Output:

```html
<li class="open">Shop</li>
  <li class="open">Clothing</li>
    <li class="open">Men</li>
      <li class="dimmed">Shirts</li>
      <li class="hit">Shoes</li>
    <li class="open">Women</li>
      <li class="hit">Dresses</li>
  <li class="open">Electronics</li>
    <li class="hit">Phones</li>
      <li class="hit">Cases</li>
    <li class="dimmed">Laptops</li>
  <li class="dimmed">Garden</li>
    <li class="dimmed">Tools</li>
```

Source: [`examples/recipes-highlight.php`](../examples/recipes-highlight.php)
<!-- /example -->

`Annotate` buffers until the state of the shallowest undecided node is known — here the whole tree, because it has
one root. For very large single-root trees, consider `KeepAncestors` instead.
