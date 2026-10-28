# tree-transform

Fast, streaming filtering and transformation of PHP trees — nested set, adjacency list, materialized path and
nested arrays — with pluggable pruning strategies and Gedmo Doctrine support.

> **Status: in design / early development.** Nothing is released yet. The design lives in
> [docs/SPEC.md](docs/SPEC.md).

## What it will do

- Read a tree in any common shape: **nested set**, **adjacency list** (`parent_id`), **materialized path**, or
  **nested arrays** — including entities and array results from
  [Gedmo Doctrine tree](https://github.com/doctrine-extensions/DoctrineExtensions) repositories.
- Filter it with a strategy that fits the job:
  - `Prune` — a non-matching node drops its whole subtree,
  - `KeepAncestors` — keep matches with their path to the root (search in a tree),
  - `Promote` — drop non-matching nodes and lift matching descendants up,
  - `ActiveTrail` — a navigation menu expanded down to the active page,
  - `Annotate` — keep everything, mark what matched.
- Map nodes, limit depth, and output nested arrays, flat lists, adjacency lists or recomputed nested sets.
- Do it in a single streaming pass where the input allows: memory grows with tree **depth**, not size.

```php
use Ghispi\TreeTransform\Tree;
use Ghispi\TreeTransform\Strategy\KeepAncestors;

$result = Tree::fromNestedSet($rows)
    ->filter(fn (array $node) => str_contains($node['title'], 'php'), new KeepAncestors())
    ->toNestedArray();
```

## Requirements

PHP 8.4+. No runtime dependencies; Doctrine ORM 3 and `gedmo/doctrine-extensions` 3 are optional.

## Development

Everything runs in Docker — no PHP needed on the host.

```bash
make build install   # build the image, install dependencies
make ci              # code style, PHPStan, PHPUnit
make help            # all targets
```

## License

MIT, see [LICENSE](LICENSE).
