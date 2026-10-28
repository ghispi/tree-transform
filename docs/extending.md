# Extending

Every part of a pipeline is a small interface. Implement one, and it works with everything else.

| You want to… | Implement | Plug in with |
|---|---|---|
| Read a new input format | [`Source`](#source) (+ a `Cursor`) | `Tree::from($source)` |
| Decide differently what happens to non-matching nodes | [`FilterStrategy`](#filter-strategy) | `->filter($predicate, $strategy)` |
| Transform the stream in another way | [`Stage`](#stage) | `->pipe($stage)` |
| Build a new output | [`Sink`](#sink) | `->into($sink)` |
| Reuse a match condition | [`Predicate`](#predicate) | `->filter($predicate)` |
| Read fields from unusual payloads | [`Accessor`](#accessor) | the `accessor` argument of sources and field predicates |

## The cursor contract

Sources, strategies and stages all produce a `Cursor`:

```php
interface Cursor
{
    public function next(): bool;      // advance in pre-order; false when exhausted
    public function node(): mixed;     // payload of the current node, unchanged
    public function depth(): int;      // 0 for roots
    public function skipSubtree(): void; // do not visit the current node's descendants
}
```

Rules every cursor must follow:

- **Pre-order.** A node is followed by its descendants, then by its next sibling.
- **Depths.** The first node has depth 0. Each next node has a depth of at most the previous depth + 1.
- `node()` and `depth()` are only meaningful after `next()` returned `true`.
- `skipSubtree()` is called before `next()`: the next call moves past the current node's subtree. Calling it twice
  has no further effect. Sources use it to avoid work (not descending, skipping rows); decorators forward it to their
  input when they can.
- **Never mutate the input.** Copy array payloads before changing them; leave objects alone.
- Keep hot loops iterative, and avoid creating an object per node.

## Source

A `Source` has one method, `open(): Cursor`. Make it re-callable when the input is re-iterable. This one reads an
indented outline:

<!-- example: extending-source -->
```php
use Ghispi\TreeTransform\Cursor\Cursor;
use Ghispi\TreeTransform\Source\Source;
use Ghispi\TreeTransform\Tree;

require __DIR__ . '/../vendor/autoload.php';

$printTree = require __DIR__ . '/support/print-tree.php';

/**
 * Reads an indented outline (two spaces per level), one node per line. Streams: O(1) memory.
 */
final readonly class OutlineSource implements Source
{
    public function __construct(private string $text) {}

    public function open(): Cursor
    {
        return new OutlineCursor(preg_split('/\R/', trim($this->text, "\n")) ?: []);
    }
}

final class OutlineCursor implements Cursor
{
    private int $position = -1;

    private int $depth = 0;

    private string $node = '';

    private int $skipBelow = -1;

    /** @param list<string> $lines */
    public function __construct(private readonly array $lines) {}

    public function next(): bool
    {
        while (isset($this->lines[++$this->position])) {
            $line = $this->lines[$this->position];
            $depth = intdiv(strlen($line) - strlen(ltrim($line, ' ')), 2);
            // skipSubtree(): pass over deeper lines without returning them.
            if ($this->skipBelow >= 0 && $depth > $this->skipBelow) {
                continue;
            }
            $this->skipBelow = -1;
            $this->depth = $depth;
            $this->node = trim($line);

            return true;
        }

        return false;
    }

    public function node(): mixed
    {
        return $this->node;
    }

    public function depth(): int
    {
        return $this->depth;
    }

    public function skipSubtree(): void
    {
        $this->skipBelow = $this->depth;
    }
}

$outline = <<<'TXT'
    Shop
      Clothing
        Men
        Women
      Archive
        Old collection
      Electronics
    TXT;

echo $printTree(
    Tree::from(new OutlineSource($outline))->filter(fn(string $title) => $title !== 'Archive'),
    fn(string $title) => $title,
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

Source: [`examples/extending-source.php`](../examples/extending-source.php)
<!-- /example -->

`skipSubtree()` remembers the current depth and `next()` passes over deeper lines. Because the filter removed
"Archive", "Old collection" was never returned or tested.

Checklist for a real source: validate the input as you read it and throw `InvalidTreeException` with a clear message
(the depth contract, ordering requirements); read fields through an [`Accessor`](#accessor) and compile the readers
in `open()`, not per row; say in the docblock what order the input must be in and what memory it uses.

## Filter strategy

A `FilterStrategy` returns a cursor over the filtered tree:

```php
interface FilterStrategy
{
    public function apply(Cursor $input, Predicate $predicate): Cursor;
}
```

This one works like `Prune` but keeps at most `$max` nodes per root tree, "a preview of every section":

<!-- example: extending-strategy -->
```php
use Ghispi\TreeTransform\Cursor\Cursor;
use Ghispi\TreeTransform\Predicate\Ancestors;
use Ghispi\TreeTransform\Predicate\Predicate;
use Ghispi\TreeTransform\Strategy\FilterStrategy;
use Ghispi\TreeTransform\Tree;

require __DIR__ . '/../vendor/autoload.php';

$letters = require __DIR__ . '/support/letters.php';
$printTree = require __DIR__ . '/support/print-tree.php';

/**
 * Like Prune (a non-matching node is removed with its subtree), but keeps at most $max nodes per root tree:
 * "a preview of every section". Streaming, O(depth) memory (for the Ancestors view).
 */
final readonly class FirstPerRoot implements FilterStrategy
{
    public function __construct(private int $max) {}

    public function apply(Cursor $input, Predicate $predicate): Cursor
    {
        return new FirstPerRootCursor($input, $predicate, $this->max);
    }
}

final class FirstPerRootCursor implements Cursor
{
    private readonly Ancestors $ancestors;

    private int $kept = 0;

    public function __construct(
        private readonly Cursor $input,
        private readonly Predicate $predicate,
        private readonly int $max,
    ) {
        $this->ancestors = new Ancestors();
    }

    public function next(): bool
    {
        while ($this->input->next()) {
            $depth = $this->input->depth();
            if ($depth === 0) {
                $this->kept = 0;
            }
            if ($this->kept >= $this->max) {
                $this->input->skipSubtree(); // nothing more from this subtree: let the source skip it
                continue;
            }
            $node = $this->input->node();
            $this->ancestors->enter($node, $depth); // keep the Ancestors view up to date for the predicate
            if (!$this->predicate->matches($node, $depth, $this->ancestors)) {
                $this->input->skipSubtree();
                continue;
            }
            ++$this->kept;

            return true;
        }

        return false;
    }

    public function node(): mixed
    {
        return $this->input->node();
    }

    public function depth(): int
    {
        return $this->input->depth();
    }

    public function skipSubtree(): void
    {
        $this->input->skipSubtree();
    }
}

echo $printTree(
    Tree::fromNestedArray($letters)->filter(fn(array $n) => $n['name'] !== 'C', new FirstPerRoot(4)),
    fn(array $n) => $n['name'],
);
```

Output:

```text
A
└─ B
   └─ D
      └─ E
H
└─ I
```

Source: [`examples/extending-strategy.php`](../examples/extending-strategy.php)
<!-- /example -->

What a strategy must do:

- Call the predicate **at most once per node**, and not at all for nodes inside a subtree it removes. Call
  `skipSubtree()` on the input as soon as the outcome of a whole subtree is known, so the source can skip it.
- Pass a correct `Ancestors` view. Create one `Ancestors` per cursor and call `$ancestors->enter($node, $depth)`
  right before each `matches()` call.
- Keep the output a valid pre-order stream with correct depths. If you remove nodes and keep their descendants,
  recompute the depths, as `Promote` does.
- Write down the memory bound in the docblock. Buffer only what you must.

## Stage

A `Stage` wraps a cursor and returns a cursor:

```php
interface Stage
{
    public function apply(Cursor $input): Cursor;
}
```

This one adds the URL of every category, built from the slugs of its ancestors. It keeps one slug per open depth,
O(depth) memory:

<!-- example: extending-stage -->
```php
use Ghispi\TreeTransform\Cursor\Cursor;
use Ghispi\TreeTransform\Stage\Stage;
use Ghispi\TreeTransform\Tree;

require __DIR__ . '/../vendor/autoload.php';

$rows = require __DIR__ . '/support/shop.php';

/**
 * Adds the URL path of every node, built from the slugs of its ancestors: "/clothing/men/shirts".
 * Keeps one slug per open depth: O(depth) memory.
 */
final readonly class WithUrl implements Stage
{
    public function apply(Cursor $input): Cursor
    {
        return new WithUrlCursor($input);
    }
}

final class WithUrlCursor implements Cursor
{
    /** @var array<int, string> slugs of the current path, indexed by depth */
    private array $slugs = [];

    private mixed $node = null;

    public function __construct(private readonly Cursor $input) {}

    public function next(): bool
    {
        if (!$this->input->next()) {
            return false;
        }
        $depth = $this->input->depth();
        $node = $this->input->node();
        $this->slugs[$depth] = $node['slug'];
        $node['url'] = '/' . implode('/', array_slice($this->slugs, 0, $depth + 1));
        $this->node = $node; // a copy: the input row is not modified

        return true;
    }

    public function node(): mixed
    {
        return $this->node;
    }

    public function depth(): int
    {
        return $this->input->depth();
    }

    public function skipSubtree(): void
    {
        $this->input->skipSubtree();
    }
}

foreach (Tree::fromNestedSet($rows)->depthWindow(1)->pipe(new WithUrl())->maxDepth(1) as $depth => $category) {
    echo str_repeat('  ', $depth), $category['url'], "\n";
}
```

Output:

```text
/clothing
  /clothing/men
  /clothing/women
/electronics
  /electronics/phones
  /electronics/laptops
/garden
  /garden/tools
```

Source: [`examples/extending-stage.php`](../examples/extending-stage.php)
<!-- /example -->

The stage copies the array row before adding `url`, and forwards `skipSubtree()` to its input so a later
`maxDepth()` still lets the source skip rows.

## Sink

A `Sink` consumes a cursor and returns a result. Use the `@implements Sink<…>` annotation so static analysis knows
what `into()` returns.

```php
/** @template TResult */
interface Sink
{
    /** @return TResult */
    public function consume(Cursor $cursor): mixed;
}
```

Many outputs need only the depth of each node, not a built tree. This one writes nested `<ul>` lists in one pass:

<!-- example: extending-sink -->
```php
use Ghispi\TreeTransform\Cursor\Cursor;
use Ghispi\TreeTransform\Sink\Sink;
use Ghispi\TreeTransform\Tree;

require __DIR__ . '/../vendor/autoload.php';

$rows = require __DIR__ . '/support/shop.php';

/**
 * Renders nested <ul> lists in one pass, without building a tree: it only needs the depth of each node.
 *
 * @implements Sink<string>
 */
final readonly class HtmlListSink implements Sink
{
    /** @param Closure(mixed): string $label */
    public function __construct(private Closure $label) {}

    public function consume(Cursor $cursor): string
    {
        $html = '';
        $open = -1; // depth of the deepest open <li>
        while ($cursor->next()) {
            $depth = $cursor->depth();
            if ($depth > $open) {
                $html .= ($open >= 0 ? "\n" : '') . self::indent(2 * $depth) . "<ul>\n";
            } else {
                $html .= "</li>\n" . $this->close($open, $depth);
            }
            $html .= self::indent(2 * $depth + 1) . '<li>' . htmlspecialchars(($this->label)($cursor->node()));
            $open = $depth;
        }

        return $open < 0 ? '' : $html . "</li>\n" . $this->close($open, 0) . "</ul>\n";
    }

    /** Closes the lists from depth $from up to depth $to. */
    private function close(int $from, int $to): string
    {
        $html = '';
        for ($depth = $from; $depth > $to; --$depth) {
            $html .= self::indent(2 * $depth) . "</ul>\n" . self::indent(2 * $depth - 1) . "</li>\n";
        }

        return $html;
    }

    private static function indent(int $level): string
    {
        return str_repeat('  ', $level);
    }
}

echo Tree::fromNestedSet($rows)
    ->maxDepth(2)
    ->depthWindow(1)
    ->filter(fn(array $c) => $c['slug'] !== 'garden')
    ->into(new HtmlListSink(fn(array $c) => $c['title']));
```

Output:

```html
<ul>
  <li>Clothing
    <ul>
      <li>Men</li>
      <li>Women</li>
    </ul>
  </li>
  <li>Electronics
    <ul>
      <li>Phones</li>
      <li>Laptops</li>
    </ul>
  </li>
</ul>
```

Source: [`examples/extending-sink.php`](../examples/extending-sink.php)
<!-- /example -->

The sink calls `next()` until it returns `false`. It may stop early (like `limit()`), which stops reading the
input too.

## Predicate

Implement `Predicate` when a condition needs set-up you want to do once, or is reused across the codebase. This one
normalises the search term once in the constructor, then compares it case- and accent-insensitively:

<!-- example: extending-predicate -->
```php
use Ghispi\TreeTransform\Predicate\Ancestors;
use Ghispi\TreeTransform\Predicate\Predicate;
use Ghispi\TreeTransform\Strategy\KeepAncestors;
use Ghispi\TreeTransform\Tree;

require __DIR__ . '/../vendor/autoload.php';

$rows = require __DIR__ . '/support/shop.php';
$printTree = require __DIR__ . '/support/print-tree.php';

/**
 * Case- and accent-insensitive search in a field. The search term is normalised once, not per node.
 */
final readonly class TitleContains implements Predicate
{
    private string $needle;

    public function __construct(string $needle, private string $field = 'title')
    {
        $this->needle = self::normalise($needle);
    }

    public function matches(mixed $node, int $depth, Ancestors $ancestors): bool
    {
        return str_contains(self::normalise($node[$this->field]), $this->needle);
    }

    private static function normalise(string $text): string
    {
        return strtr(mb_strtolower($text), ['é' => 'e', 'è' => 'e', 'ü' => 'u', 'ö' => 'o']);
    }
}

echo $printTree(
    Tree::fromNestedSet($rows)->filter(new TitleContains('LAPTÖPS'), new KeepAncestors()),
    fn(array $c) => $c['title'],
);
```

Output:

```text
Shop
└─ Electronics
   └─ Laptops
```

Source: [`examples/extending-predicate.php`](../examples/extending-predicate.php)
<!-- /example -->

Ignore `$depth` and `$ancestors` when you don't need them. Wrap and combine your predicate with `AllOf`, `AnyOf`
and `Not` like the built-in ones.

## Accessor

An `Accessor` turns a field name into a reader closure:

```php
interface Accessor
{
    /** @return \Closure(mixed $node): mixed */
    public function reader(string $field): \Closure;
}
```

`reader()` is called once per field when a source opens; the closure runs for every node. Do the expensive work
(reflection, method lookup) in `reader()`, and return `null` when a field is missing.

<!-- example: extending-accessor -->
```php
use Ghispi\TreeTransform\Accessor\Accessor;
use Ghispi\TreeTransform\Source\NestedSetFields;
use Ghispi\TreeTransform\Tree;

require __DIR__ . '/../vendor/autoload.php';

$printTree = require __DIR__ . '/support/print-tree.php';

/** A model that keeps its columns in an attributes array, as many active-record libraries do. */
final readonly class Model
{
    /** @param array<string, mixed> $attributes */
    public function __construct(private array $attributes) {}

    public function getAttribute(string $name): mixed
    {
        return $this->attributes[$name] ?? null;
    }
}

/**
 * Reads fields with $model->getAttribute($field). reader() runs once per field; the returned closure runs per node.
 */
final readonly class AttributeAccessor implements Accessor
{
    public function reader(string $field): Closure
    {
        return static fn(mixed $node): mixed => $node instanceof Model ? $node->getAttribute($field) : null;
    }
}

$categories = [
    new Model(['title' => 'Books',   '_lft' => 1, '_rgt' => 6]),
    new Model(['title' => 'Fiction', '_lft' => 2, '_rgt' => 3]),
    new Model(['title' => 'Travel',  '_lft' => 4, '_rgt' => 5]),
];

$tree = Tree::fromNestedSet($categories, new NestedSetFields(left: '_lft', right: '_rgt'), new AttributeAccessor());
echo $printTree($tree, fn(Model $m) => $m->getAttribute('title'));
```

Output:

```text
Books
├─ Fiction
└─ Travel
```

Source: [`examples/extending-accessor.php`](../examples/extending-accessor.php)
<!-- /example -->

Pass it to any source (`Tree::fromNestedSet($rows, $fields, $accessor)`), to `FieldEquals` / `FieldIn`, or to
`AdjacencyListSink`.
