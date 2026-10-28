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
     realistic names (categories, pages, menu items); print trees with the helpers in `examples/support/`.
   - Embed it in the page with `<!-- example: <name> -->` and `<!-- /example -->` (append ` no-output` to show only
     the code). The block content is generated — never edit it by hand.
   - Run `make docs`: it regenerates every `examples/*.out` and every embedded block. **Read the `.out` diff** to
     make sure the output shows what the text claims.
   - `tests/Docs` runs every example against its `.out` and checks the pages are in sync (`DocsInSyncTest`).
3. **Write the page section**: what it is for (one sentence, a real use case), the example (embed the code and the
   output), options in a table (name, type, default, effect), memory/performance notes, gotchas, and links to
   related strategies/recipes. For strategies, draw input and output trees in ASCII using the spec's running example.
4. **Docblocks** on every public class, method and option: one-line summary, PHPStan types, `@throws`.
5. **README**: update the feature list / quick example only for headline features.
6. **CHANGELOG.md**: entry under `[Unreleased]`.
7. **Verify**: `make ci` (includes the docs suite). For larger docs work, delegate to the `docs-writer` agent. Keep the tone plain and direct; prefer short sentences and
   concrete examples over adjectives.
