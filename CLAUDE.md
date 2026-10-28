# tree-transform

PHP library: streaming filter/transform of trees (nested set, adjacency list, materialized path, nested arrays),
pluggable filter strategies, optional Gedmo Doctrine bridge. **The spec is `docs/SPEC.md` — read it before
designing or changing behaviour; update it when behaviour changes.**

## Ground rules

- **Docker only.** No PHP on the host. Run everything through `make` (see `make help`) or
  `docker compose run --rm php <cmd>`. Pick PHP with `PHP_VERSION=8.4|8.5 make …`.
- Before calling work done: `make ci` (cs + phpstan max + phpunit) must pass.
- Core (`src/` outside `Bridge/`) has **zero runtime dependencies**.
- PER-CS 2.0, `declare(strict_types=1)`, `final` classes by default, readonly value objects.
- Hot paths: iterative (no recursion), no per-node object allocation, closures compiled once, never mutate input.
- Every strategy/source change needs differential tests against the reference oracle (`tests/Differential`).
- **Docs ship with the code**: every public feature gets docs and a tested example (`examples/*.php` + `.out`,
  run by `tests/Docs`) in the same change — use the `write-docs` skill. See SPEC §15.
- Public GitHub repo: no secrets, no internal references in code, commits or docs.

## Layout

`src/{Cursor,Source,Accessor,Predicate,Strategy,Stage,Sink,Bridge/Gedmo,Exception}`,
`tests/{Unit,Differential,Integration,Docs,Fixtures}`, `benchmarks/`, `docs/`, `examples/`.

## Project agents and skills

- Agents (`.claude/agents`): `tree-semantics-reviewer`, `test-engineer`, `perf-engineer`.
- Skills (`.claude/skills`): `run-checks`, `add-strategy`, `add-source`, `write-docs`.

## GitHub

Remote `https://github.com/ghispi/tree-transform` via a repo-local credential helper reading the fine-grained token in
`~/.config/tree-transform-github-token` (never read or print it). Commit identity is repo-local
(`ghispi <ghispi@users.noreply.github.com>`). Push only when the user asks.
