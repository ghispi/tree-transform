---
name: write-docs
description: Write or update the user documentation of tree-transform alongside code — README, docs/*.md pages, runnable examples in examples/ with expected output, tested by tests/Docs, ASCII tree diagrams, docblocks and CHANGELOG. Use whenever a public class, option or behaviour is added or changed, and when the user asks for docs or examples.
---

# Write docs with the code

A feature is not done until it is documented with a tested example (`docs/SPEC.md` §15).

1. **Find the page.** Map the change to its page (`docs/sources.md`, `strategies.md`, `predicates.md`,
   `stages.md`, `outputs.md`, `gedmo.md`, `recipes.md`, `extending.md`, `performance.md`). Create the page from the
   layout in SPEC §15 if it doesn't exist yet and link it from `docs/index.md`.
2. **Write the example first** as `examples/<topic>-<feature>.php`:
   - `declare(strict_types=1)`, `require __DIR__ . '/../vendor/autoload.php';`, self-contained input data,
     realistic names (categories, pages, menu items), and output printed with `print_r` / `echo` / a tree dump.
   - Store the exact expected output in `examples/<same name>.out`. Generate it by running the example in Docker
     (`docker compose run --rm php php examples/<name>.php > examples/<name>.out`) and **read it** to make sure it
     shows what the text claims.
   - `tests/Docs/ExamplesTest.php` runs every `examples/*.php` and compares with its `.out`; create it if missing
     (data provider over `glob()`, run each with `include` in an output buffer, failing on any notice).
3. **Write the page section**: what it is for (one sentence, a real use case), the example (embed the code and the
   output), options in a table (name, type, default, effect), memory/performance notes, gotchas, and links to
   related strategies/recipes. For strategies, draw input and output trees in ASCII using the spec's running example.
4. **Docblocks** on every public class, method and option: one-line summary, PHPStan types, `@throws`.
5. **README**: update the feature list / quick example only for headline features.
6. **CHANGELOG.md**: entry under `[Unreleased]`.
7. **Verify**: `make ci` (includes the docs suite). Keep the tone plain and direct; prefer short sentences and
   concrete examples over adjectives.
