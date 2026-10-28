---
name: add-source
description: Checklist for adding or changing a tree Source (input representation) or an Accessor in tree-transform — ordering requirements, depth computation, skipSubtree fast path, validation, fixtures and differential tests, Gedmo bridge wiring. Use when supporting a new input format or changing how an existing one is read.
---

# Add a tree source

1. **Spec.** Add the row to `docs/SPEC.md` §4: input ordering requirement, how depth is derived, cost of
   `skipSubtree()`, memory bound, validation checks (cheap vs `strict`).
2. **Fields.** A readonly `*Fields` value object for field names, with presets where they exist (e.g. `gedmo()`).
   Read fields through compiled `Accessor` readers; add an inline array fast path only if a benchmark justifies it.
3. **Implement** `src/Source/<Name>Source.php` (implements `Source`, `open()` returns a `Cursor`).
   - Pre-order output, depth 0 roots, forests supported.
   - `skipSubtree()` must avoid work (not descend / skip rows without evaluating them).
   - Never mutate the input. Iterative, O(depth) unless the format forces O(n) — document it.
   - Malformed input raises an exception implementing `TreeTransformException`, with the offending id.
4. **Fixtures.** Teach the random tree generator in `tests/Fixtures` to render this representation.
5. **Tests.** Unit (ordering, forests, malformed input, skip behaviour) and add to the differential matrix so it
   is compared with every other source.
6. **Gedmo.** If it maps to a Gedmo strategy, wire auto-configuration in `src/Bridge/Gedmo` and add an integration
   test on SQLite.
7. Verify with `run-checks`; request a `tree-semantics-reviewer` pass; docs and a tested example via the `write-docs` skill (incl. `CHANGELOG.md` entry).
