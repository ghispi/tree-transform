# Strategies

A filter has two parts. The **predicate** says whether a node matches. The **strategy** decides what happens to
the node, its subtree and its ancestors:

```php
$tree->filter($predicate);                       // Prune, the default
$tree->filter($predicate, new KeepAncestors());  // any FilterStrategy
```

| Strategy | Non-matching node | Memory |
|---|---|---|
| [`Prune`](#prune) | removed with its whole subtree; descendants are never tested | O(depth) |
| [`KeepAncestors`](#keepancestors) | kept only if a descendant matches (as context) | O(depth) |
| [`Promote`](#promote) | removed; matching descendants move up | O(depth) |
| [`Annotate`](#annotate) | kept and marked | up to one root's tree |
| [`ActiveTrail`](#activetrail) | the predicate marks the *active* node; a menu is built around it | depth × branching |
| [`MinMatches`](#minmatches) | kept if its subtree has at least *n* matches | up to one undecided subtree |
| [`Placeholder`](#placeholder) | removed with its subtree, replaced by a stub per run of siblings | O(depth) |

`OnMatch` controls what happens below a match: see [OnMatch](#onmatch).

Every strategy calls the predicate at most once per node, and not at all for nodes inside a removed subtree.

## The running example

Every section uses the same tree and the predicate `fn(array $n) => $n['match']`. ✓ marks a match:

<!-- example: strategies-running-example -->
```php
use Ghispi\TreeTransform\Tree;

require __DIR__ . '/../vendor/autoload.php';

$letters = require __DIR__ . '/support/letters.php';
$printTree = require __DIR__ . '/support/print-tree.php';

// Draw the input, marking which nodes match the predicate fn (array $n) => $n['match'].
echo $printTree(
    Tree::fromNestedArray($letters),
    fn(array $n) => $n['name'] . ($n['match'] ? ' ✓' : ' ✗'),
);
```

Output:

```text
A ✓
├─ B ✗
│  ├─ C ✓
│  └─ D ✗
│     └─ E ✓
└─ F ✗
   └─ G ✗
H ✗
└─ I ✓
```

Source: [`examples/strategies-running-example.php`](../examples/strategies-running-example.php)
<!-- /example -->

The tree is defined in [`examples/support/letters.php`](../examples/support/letters.php) as nested arrays.

## Prune

**Hide what doesn't match, with everything below it.** The default strategy, and the cheapest. Use it for
visibility flags, permissions and published states, where a hidden parent hides its children.

```text
Input                Predicate              Output
A ✓                  fn(array $n)           A
├─ B ✗                 => $n['match']
│  ├─ C ✓
│  └─ D ✗
│     └─ E ✓
└─ F ✗
   └─ G ✗
H ✗
└─ I ✓
```

B, F and H fail, so their subtrees are cut. C, E and I match but are never tested:

<!-- example: strategies-prune -->
```php
use Ghispi\TreeTransform\Strategy\Prune;
use Ghispi\TreeTransform\Tree;

require __DIR__ . '/../vendor/autoload.php';

$letters = require __DIR__ . '/support/letters.php';
$printTree = require __DIR__ . '/support/print-tree.php';

$tested = [];
$result = Tree::fromNestedArray($letters)
    ->filter(function (array $n) use (&$tested): bool {
        $tested[] = $n['name'];

        return $n['match'];
    }, new Prune()); // Prune is also the default: ->filter($predicate)

echo $printTree($result, fn(array $n) => $n['name']);
echo 'Predicate called for: ', implode(', ', $tested), "\n";
```

Output:

```text
A
Predicate called for: A, B, F, H
```

Source: [`examples/strategies-prune.php`](../examples/strategies-prune.php)
<!-- /example -->

| Option | Type | Default | Effect |
|---|---|---|---|
| `onMatch` | `OnMatch` | `EvaluateDescendants` | See [OnMatch](#onmatch) |

Memory: O(depth). A non-match calls `skipSubtree()` on the input, so sources skip the rows of the subtree without
testing them (nested set by `lft`, materialized path by prefix, nested arrays by not descending). Depths are
unchanged.

## KeepAncestors

**Search in a tree.** A node is kept if it matches or if any descendant matches. Non-matching ancestors stay as
context, so every hit keeps its path to the root.

```text
Input                Predicate              Output
A ✓                  fn(array $n)           A
├─ B ✗                 => $n['match']       └─ B
│  ├─ C ✓                                      ├─ C
│  └─ D ✗                                      └─ D
│     └─ E ✓                                      └─ E
└─ F ✗                                      H
   └─ G ✗                                   └─ I
H ✗
└─ I ✓
```

<!-- example: strategies-keep-ancestors -->
```php
use Ghispi\TreeTransform\Strategy\KeepAncestors;
use Ghispi\TreeTransform\Tree;

require __DIR__ . '/../vendor/autoload.php';

$letters = require __DIR__ . '/support/letters.php';
$printTree = require __DIR__ . '/support/print-tree.php';

$result = Tree::fromNestedArray($letters)
    ->filter(fn(array $n) => $n['match'], new KeepAncestors());

echo $printTree($result, fn(array $n) => $n['name']);
```

Output:

```text
A
└─ B
   ├─ C
   └─ D
      └─ E
H
└─ I
```

Source: [`examples/strategies-keep-ancestors.php`](../examples/strategies-keep-ancestors.php)
<!-- /example -->

| Option | Type | Default | Effect |
|---|---|---|---|
| `onMatch` | `OnMatch` | `EvaluateDescendants` | `KeepDescendants` shows the whole content of a matching node ("folder contents") |

Memory: O(depth). Non-matching nodes wait on a stack until a matching descendant shows up or their subtree ends.
Every node is tested, because a match can be anywhere below. Depths are unchanged.

Used in [Recipes → Search](recipes.md#search-in-a-tree) and
[Recipes → Pruning empty categories](recipes.md#pruning-empty-categories).

## Promote

**Only the matches, moved up.** Non-matching nodes are removed; matching descendants are attached to the nearest
kept ancestor, or become roots. Also called hoisting or collapsing.

```text
Input                Predicate              Output
A ✓                  fn(array $n)           A
├─ B ✗                 => $n['match']       ├─ C
│  ├─ C ✓                                   └─ E
│  └─ D ✗                                   I
│     └─ E ✓
└─ F ✗
   └─ G ✗
H ✗
└─ I ✓
```

<!-- example: strategies-promote -->
```php
use Ghispi\TreeTransform\Strategy\Promote;
use Ghispi\TreeTransform\Tree;

require __DIR__ . '/../vendor/autoload.php';

$letters = require __DIR__ . '/support/letters.php';
$printTree = require __DIR__ . '/support/print-tree.php';

$result = Tree::fromNestedArray($letters)
    ->filter(fn(array $n) => $n['match'], new Promote());

echo $printTree($result, fn(array $n) => $n['name']);
```

Output:

```text
A
├─ C
└─ E
I
```

Source: [`examples/strategies-promote.php`](../examples/strategies-promote.php)
<!-- /example -->

| Option | Type | Default | Effect |
|---|---|---|---|
| `onMatch` | `OnMatch` | `EvaluateDescendants` | `DropDescendants` keeps only the first match per branch |

Memory: O(depth). Output depths change. [`toAdjacencyList()`](outputs.md#adjacency-list) and
[`toNestedSet()`](outputs.md#nested-set) recompute parent ids and nested set values for the new structure.

The predicate's [`Ancestors`](predicates.md#ancestors) are the input's ancestors, including the removed ones. Use
that to keep a node from being promoted out of a hidden section: see the example in
[Predicates](predicates.md#ancestors).

Used in [Recipes → Flatten matches](recipes.md#flatten-matches-with-promote).

## Annotate

**Keep everything, mark what matched.** Nothing is removed. Each node is tagged with a `MatchState`:

| `MatchState` | Value | Meaning |
|---|---|---|
| `Match` | `'match'` | The node matches |
| `AncestorOfMatch` | `'ancestor'` | It does not match, but a descendant does |
| `DescendantOfMatch` | `'descendant'` | It does not match, no descendant does, but an ancestor does |
| `None` | `'none'` | Unrelated to any match |

```text
Input                Predicate              Output
A ✓                  fn(array $n)           A  Match
├─ B ✗                 => $n['match']       ├─ B  AncestorOfMatch
│  ├─ C ✓                                   │  ├─ C  Match
│  └─ D ✗                                   │  └─ D  AncestorOfMatch
│     └─ E ✓                                │     └─ E  Match
└─ F ✗                                      └─ F  DescendantOfMatch
   └─ G ✗                                      └─ G  DescendantOfMatch
H ✗                                         H  AncestorOfMatch
└─ I ✓                                      └─ I  Match
```

By default every payload is wrapped in an `Annotated` object with public `node` and `state` properties:

<!-- example: strategies-annotate -->
```php
use Ghispi\TreeTransform\Strategy\Annotate;
use Ghispi\TreeTransform\Strategy\Annotated;
use Ghispi\TreeTransform\Tree;

require __DIR__ . '/../vendor/autoload.php';

$letters = require __DIR__ . '/support/letters.php';
$printTree = require __DIR__ . '/support/print-tree.php';

// Same as ->filter($predicate, new Annotate()). Every node is kept and wrapped in an Annotated object.
$result = Tree::fromNestedArray($letters)
    ->annotate(fn(array $n) => $n['match']);

echo $printTree($result, fn(Annotated $a) => $a->node['name'] . '  ' . $a->state->name);
```

Output:

```text
A  Match
├─ B  AncestorOfMatch
│  ├─ C  Match
│  └─ D  AncestorOfMatch
│     └─ E  Match
└─ F  DescendantOfMatch
   └─ G  DescendantOfMatch
H  AncestorOfMatch
└─ I  Match
```

Source: [`examples/strategies-annotate.php`](../examples/strategies-annotate.php)
<!-- /example -->

Pass a `combine` closure to merge the state into the payload instead:

<!-- example: strategies-annotate-combine -->
```php
use Ghispi\TreeTransform\Strategy\MatchState;
use Ghispi\TreeTransform\Tree;

require __DIR__ . '/../vendor/autoload.php';

$letters = require __DIR__ . '/support/letters.php';

// Merge the state into the array row instead of wrapping it.
$rows = Tree::fromNestedArray($letters)
    ->annotate(
        fn(array $n) => $n['match'],
        fn(array $n, MatchState $state) => ['name' => $n['name'], 'state' => $state->value],
    )
    ->toFlatList();

foreach ($rows as ['depth' => $depth, 'node' => $node]) {
    echo str_repeat('  ', $depth), $node['name'], ' => ', $node['state'], "\n";
}
```

Output:

```text
A => match
  B => ancestor
    C => match
    D => ancestor
      E => match
  F => descendant
    G => descendant
H => ancestor
  I => match
```

Source: [`examples/strategies-annotate-combine.php`](../examples/strategies-annotate-combine.php)
<!-- /example -->

| Option | Type | Default | Effect |
|---|---|---|---|
| `combine` | `?Closure(mixed $node, MatchState $state): mixed` | wraps in `Annotated` | Builds the output node |

`$tree->annotate($predicate, $combine)` is short for `$tree->filter($predicate, new Annotate($combine))`.

Memory: whether a node is an ancestor of a match is known only when its subtree has been read. Output waits for the
shallowest undecided node, so up to one root's tree is buffered. A forest of many small roots streams well; a single
huge root is buffered whole.

Used in [Recipes → Highlighting](recipes.md#highlighting-with-annotate).

## ActiveTrail

**A navigation menu expanded down to the current page.** The predicate identifies the active node (usually by URL
or id). Kept: all roots, every child of a node on the trail (the active node's ancestors), the active node, and its
children.

```text
Input                Predicate              Output (defaults)
A                    fn(array $n)           A
├─ B                   => $n['name']        ├─ B
│  ├─ C                   === 'E'           │  ├─ C
│  └─ D                                     │  └─ D
│     └─ E (active)                         │     └─ E
└─ F                                        └─ F        F's children collapsed
   └─ G                                     H           H's children collapsed
H
└─ I
```

With `siblings: false` only the trail is kept, which is a breadcrumb:

```text
A
└─ B
   └─ D
      └─ E
```

All options on the running example:

<!-- example: strategies-active-trail -->
```php
use Ghispi\TreeTransform\Tree;

require __DIR__ . '/../vendor/autoload.php';

$letters = require __DIR__ . '/support/letters.php';
$printTree = require __DIR__ . '/support/print-tree.php';

$tree = Tree::fromNestedArray($letters);
$isActive = fn(array $n) => $n['name'] === 'E';

echo "Defaults (a menu):\n";
echo $printTree($tree->activeTrail($isActive), fn(array $n) => $n['name']);

echo "\nsiblings: false (a breadcrumb):\n";
echo $printTree($tree->activeTrail($isActive, siblings: false), fn(array $n) => $n['name']);

echo "\nActive = B, activeChildrenDepth: 1 (default):\n";
echo $printTree($tree->activeTrail(fn(array $n) => $n['name'] === 'B'), fn(array $n) => $n['name']);

echo "\nActive = B, activeChildrenDepth: 0:\n";
echo $printTree(
    $tree->activeTrail(fn(array $n) => $n['name'] === 'B', activeChildrenDepth: 0),
    fn(array $n) => $n['name'],
);

echo "\nActive = B, activeChildrenDepth: PHP_INT_MAX:\n";
echo $printTree(
    $tree->activeTrail(fn(array $n) => $n['name'] === 'B', activeChildrenDepth: PHP_INT_MAX),
    fn(array $n) => $n['name'],
);

echo "\nActive = C or I (multiple: true, default):\n";
echo $printTree(
    $tree->activeTrail(fn(array $n) => in_array($n['name'], ['C', 'I'], true)),
    fn(array $n) => $n['name'],
);

echo "\nActive = C or I, multiple: false (first match wins):\n";
echo $printTree(
    $tree->activeTrail(fn(array $n) => in_array($n['name'], ['C', 'I'], true), multiple: false),
    fn(array $n) => $n['name'],
);
```

Output:

```text
Defaults (a menu):
A
├─ B
│  ├─ C
│  └─ D
│     └─ E
└─ F
H

siblings: false (a breadcrumb):
A
└─ B
   └─ D
      └─ E

Active = B, activeChildrenDepth: 1 (default):
A
├─ B
│  ├─ C
│  └─ D
└─ F
H

Active = B, activeChildrenDepth: 0:
A
├─ B
└─ F
H

Active = B, activeChildrenDepth: PHP_INT_MAX:
A
├─ B
│  ├─ C
│  └─ D
│     └─ E
└─ F
H

Active = C or I (multiple: true, default):
A
├─ B
│  ├─ C
│  └─ D
└─ F
H
└─ I

Active = C or I, multiple: false (first match wins):
A
├─ B
│  ├─ C
│  └─ D
└─ F
H
```

Source: [`examples/strategies-active-trail.php`](../examples/strategies-active-trail.php)
<!-- /example -->

| Option | Type | Default | Effect |
|---|---|---|---|
| `siblings` | `bool` | `true` | `false` keeps only the trail (a breadcrumb), not the other children of trail nodes |
| `activeChildrenDepth` | `int` | `1` | Levels shown below the active node: `0` none, `1` its children, `PHP_INT_MAX` the whole subtree |
| `multiple` | `bool` | `true` | Several active nodes give the union of their trails. `false` stops testing after the first match |

`$tree->activeTrail($isActive, siblings: …, activeChildrenDepth: …, multiple: …)` is short for
`$tree->filter($isActive, new ActiveTrail(…))`.

Memory: while it is not yet known whether a node is on the trail, its direct children are buffered. That is the sum
of child counts along the current path — typically depth × branching, worst case O(n) for one very wide node. With
Doctrine, [`GedmoTree::activeTrail()`](gedmo.md) loads only the rows the menu needs.

Used in [Recipes → Navigation menu](recipes.md#navigation-menu) and [Breadcrumb](recipes.md#breadcrumb).

## MinMatches

**Keep a node when its subtree has enough matches** — the node itself included. "Categories with at least 3
products in them", "folders with at least two large files". `new MinMatches(1)` gives the same result as
`KeepAncestors`.

Matches per subtree on the running example: A 3 (A, C, E), B 2 (C, E), D 1, H 1, F 0.

```text
Input (matches below)     Predicate              MinMatches(2)    MinMatches(3)
A ✓   (A, C, E: 3)        fn(array $n)           A                A
├─ B ✗ (C, E: 2)            => $n['match']       └─ B
│  ├─ C ✓ (1)
│  └─ D ✗ (E: 1)
│     └─ E ✓ (1)
└─ F ✗ (0)
   └─ G ✗ (0)
H ✗   (I: 1)
└─ I ✓ (1)
```

<!-- example: strategies-min-matches -->
```php
use Ghispi\TreeTransform\Strategy\MinMatches;
use Ghispi\TreeTransform\Tree;

require __DIR__ . '/../vendor/autoload.php';

$letters = require __DIR__ . '/support/letters.php';
$printTree = require __DIR__ . '/support/print-tree.php';

$tree = Tree::fromNestedArray($letters);
$matches = fn(array $n) => $n['match'];

foreach ([1, 2, 3] as $min) {
    echo "MinMatches({$min}):\n";
    echo $printTree($tree->filter($matches, new MinMatches($min)), fn(array $n) => $n['name']), "\n";
}
```

Output:

```text
MinMatches(1):
A
└─ B
   ├─ C
   └─ D
      └─ E
H
└─ I

MinMatches(2):
A
└─ B

MinMatches(3):
A
```

Source: [`examples/strategies-min-matches.php`](../examples/strategies-min-matches.php)
<!-- /example -->

| Option | Type | Default | Effect |
|---|---|---|---|
| `min` | `int` | — (required, ≥ 1) | Minimum number of matching nodes in the subtree, the node included |

Kept nodes always form a valid tree, because an ancestor's subtree has at least as many matches as its
descendant's. Depths are unchanged.

Memory: a node is emitted as soon as its count reaches `min`. A node that never reaches it is known only when its
subtree ends, so output waits: up to the size of one undecided subtree.

Used in [Recipes → Pruning empty categories](recipes.md#pruning-empty-categories).

## Placeholder

**Like `Prune`, but show that something was hidden.** Each run of consecutive removed siblings is replaced by one
stub node, built by your factory `fn(int $hidden, int $depth): mixed`. `$hidden` counts the removed siblings in the
run, not their descendants.

```text
Input                Predicate              Output
A ✓                  fn(array $n)           A
├─ B ✗                 => $n['match']       └─ (2 hidden)    B and F
│  ├─ C ✓                                   (1 hidden)       H
│  └─ D ✗
│     └─ E ✓
└─ F ✗
   └─ G ✗
H ✗
└─ I ✓
```

<!-- example: strategies-placeholder -->
```php
use Ghispi\TreeTransform\Strategy\OnMatch;
use Ghispi\TreeTransform\Strategy\Placeholder;
use Ghispi\TreeTransform\Tree;

require __DIR__ . '/../vendor/autoload.php';

$letters = require __DIR__ . '/support/letters.php';
$printTree = require __DIR__ . '/support/print-tree.php';

$tree = Tree::fromNestedArray($letters);
$matches = fn(array $n) => $n['match'];

// The factory creates the stub node for each run of removed siblings.
$stub = fn(int $hidden, int $depth) => ['name' => "({$hidden} hidden)"];

echo $printTree($tree->filter($matches, new Placeholder($stub)), fn(array $n) => $n['name']);

echo "\nWith OnMatch::KeepDescendants:\n";
echo $printTree($tree->filter($matches, new Placeholder($stub, OnMatch::KeepDescendants)), fn(array $n) => $n['name']);
```

Output:

```text
A
└─ (2 hidden)
(1 hidden)

With OnMatch::KeepDescendants:
A
├─ B
│  ├─ C
│  └─ D
│     └─ E
└─ F
   └─ G
(1 hidden)
```

Source: [`examples/strategies-placeholder.php`](../examples/strategies-placeholder.php)
<!-- /example -->

| Option | Type | Default | Effect |
|---|---|---|---|
| `factory` | `Closure(int $hidden, int $depth): mixed` | — (required) | Creates the stub node; `$depth` is the stub's depth |
| `onMatch` | `OnMatch` | `EvaluateDescendants` | See [OnMatch](#onmatch) |

Memory: O(depth). Removed subtrees are skipped like with `Prune`. The stub is a normal node for the stages and
outputs that follow, so give it the fields they read (for example the `title` your `map()` uses).

## OnMatch

`Prune`, `KeepAncestors`, `Promote` and `Placeholder` take an `OnMatch` option for what happens **below** a match:

| `OnMatch` | Effect |
|---|---|
| `EvaluateDescendants` (default) | Descendants of a match are tested like any other node |
| `KeepDescendants` | The whole subtree of a match is kept without testing it. Cheaper; "show the folder's contents" |
| `DropDescendants` | The match is kept, its descendants are not. "First match per branch" |

Every combination on the running example, written inline (`A[B, C]` means A with children B and C):

<!-- example: strategies-on-match -->
```php
use Ghispi\TreeTransform\Strategy\KeepAncestors;
use Ghispi\TreeTransform\Strategy\OnMatch;
use Ghispi\TreeTransform\Strategy\Promote;
use Ghispi\TreeTransform\Strategy\Prune;
use Ghispi\TreeTransform\Tree;

require __DIR__ . '/../vendor/autoload.php';

$letters = require __DIR__ . '/support/letters.php';

// Prints a tree in one line: A[B[C, D[E]]], H[I]
$inline = function (Tree $tree): string {
    $out = '';
    $previous = -1;
    foreach ($tree as $depth => $node) {
        if ($depth > $previous) {
            $out .= $previous >= 0 ? '[' : '';
        } else {
            $out .= str_repeat(']', $previous - $depth) . ', ';
        }
        $out .= $node['name'];
        $previous = $depth;
    }

    return $out . str_repeat(']', max(0, $previous));
};

$tree = Tree::fromNestedArray($letters);
$matches = fn(array $n) => $n['match'];

foreach (['Prune' => Prune::class, 'KeepAncestors' => KeepAncestors::class, 'Promote' => Promote::class] as $name => $class) {
    foreach (OnMatch::cases() as $onMatch) {
        printf("%-14s + %-20s %s\n", $name, $onMatch->name, $inline($tree->filter($matches, new $class($onMatch))));
    }
}
```

Output:

```text
Prune          + EvaluateDescendants  A
Prune          + KeepDescendants      A[B[C, D[E]], F[G]]
Prune          + DropDescendants      A
KeepAncestors  + EvaluateDescendants  A[B[C, D[E]]], H[I]
KeepAncestors  + KeepDescendants      A[B[C, D[E]], F[G]], H[I]
KeepAncestors  + DropDescendants      A, H[I]
Promote        + EvaluateDescendants  A[C, E], I
Promote        + KeepDescendants      A[B[C, D[E]], F[G]], I
Promote        + DropDescendants      A, I
```

Source: [`examples/strategies-on-match.php`](../examples/strategies-on-match.php)
<!-- /example -->

Two results worth spelling out:

```text
Prune + KeepDescendants         KeepAncestors + KeepDescendants
A                               A
├─ B                            ├─ B
│  ├─ C                         │  ├─ C
│  └─ D                         │  └─ D
│     └─ E                      │     └─ E
└─ F                            └─ F
   └─ G                            └─ G
                                H
                                └─ I
```

A matches, so its whole subtree is kept untested. With `Prune`, H fails and takes I with it; with `KeepAncestors`,
H stays as I's ancestor.

## Writing your own

Implement `FilterStrategy`; see [Extending → Filter strategy](extending.md#filter-strategy).
