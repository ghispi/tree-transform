---
name: add-source
description: Checklist for adding or changing a tree Source (input representation) or an Accessor in tree-transform — ordering requirements, depth computation, skipSubtree fast path, validation, id normalisation, row reading without copies, fixtures and differential tests, Gedmo bridge wiring. Use when supporting a new input format or changing how an existing one is read.
---

# Add or change a tree source

1. **Spec.** Add/update the row in `docs/SPEC.md` §4: input ordering requirement, how depth is derived, cost of
   `skipSubtree()`, memory bound, and the validation it performs (all validation is always on and O(1) per row —
   there is no strict mode; checks that need O(n) memory are not done, and the docs say so).
2. **Fields.** A readonly `*Fields` value object with defaults and presets where they exist (`gedmo()`). Read
   fields through `Accessor::reader()` closures compiled once in `open()`.
3. **Implement** `src/Source/<Name>Source.php` (`open()` returns a new cursor) and `<Name>Cursor.php` (`@internal`):
   - Read rows with the `Internal\RowStream` trait (`initRows()` / `fetchRow()`): list arrays by position, without
     copying; generators through `Rows::iterator()`, which throws `LogicException` on reuse and lets the
     generator's own exceptions through.
   - Fast paths that benchmarks justified: read array keys inline when the accessor is `ArrayKeyAccessor` or
     `AutoAccessor`; check `is_int()` before `Rows::int()`.
   - Ids through `Rows::key()` (`5` === `'5'`); integer fields through `Rows::int()` (integer strings only).
   - Pre-order output, depth-0 roots, forests. `skipSubtree()` must avoid work (no descending, skip rows by a cheap
     comparison). Iterative, never mutate input, O(depth) unless the format forces O(n) — document it.
   - Malformed input throws an `InvalidTreeException` subclass naming the offending id/path and how to fix it.
4. **Fixtures.** Teach `tests/Fixtures/GeneratedTree` to render the representation, so the differential suite
   compares it with every other source.
5. **Tests.** Unit (ordering, forests, malformed input, skip behaviour, generator reuse, string ids) plus the
   differential matrix (`tests/Fixtures/Matrix`).
6. **Gedmo.** If it maps to a Gedmo strategy, follow the `gedmo-bridge` skill.
7. **Finish.** `run-checks`; a benchmark subject in `benchmarks/SourceBench.php`; a `tree-semantics-reviewer`
   pass; docs and a tested example via `write-docs` (incl. `CHANGELOG.md`).
