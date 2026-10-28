---
name: add-strategy
description: Checklist for adding or changing a filter strategy (FilterStrategy) or a cursor Stage in tree-transform — spec entry, streaming implementation with compiled predicates and skip forwarding, reference oracle, differential and unit tests, benchmark, docs. Use whenever a new strategy/stage is requested or an existing one changes behaviour.
---

# Add or change a filter strategy or stage

1. **Spec first.** Add/update `docs/SPEC.md` §5 (strategy) or §6 (stage): rule, result on the A..I running example,
   memory bound, and whether it takes `OnMatch` (Prune, KeepAncestors, Promote and Placeholder do; strategies that
   define descendants' fate themselves don't). Settle ambiguous semantics before coding.
2. **Implement** `src/Strategy/<Name>.php` (`FilterStrategy`, readonly config) + `<Name>Cursor.php` (`@internal`),
   or `src/Stage/<Name>.php` + cursor:
   - Predicates: `CompiledPredicate::of($predicate)` in the constructor; call `($this->test)($node, $depth, $ancestors)`
     and cast with `(bool)`; call `$this->ancestors->enter($node, $depth)` only when `needsAncestors` is true.
     At most one predicate call per node, none inside a subtree whose fate is known.
   - Call `skipSubtree()` on the input as soon as a whole subtree's output is decided.
   - Downstream `skipSubtree()`: drop buffered output below that depth (`skipBelow`), and forward to the input
     only when the skipped node is the input's current node and skipping cannot change any other output.
   - State as int stacks / parallel arrays (no per-node objects); unset queue entries as they are emitted.
   - `final`, no recursion. Docblock with an ASCII before/after on the running example and the memory bound.
3. **Oracle.** Add the naive recursive version to `tests/Fixtures/Oracle/Oracle.php` first.
4. **Tests.** Unit: running example for every supported `OnMatch` mode and the edge cases; skip forwarding with
   `SpyCursor`. Differential: register it in the strategy/stage oracle tests and the chained-pipeline test.
5. **Facade.** A `Tree` method only for common cases; otherwise `filter($p, new Name())` / `pipe(new Name())`.
6. **Finish.** Benchmark subject in `benchmarks/StrategyBench.php` if on a hot path; `run-checks`; a
   `tree-semantics-reviewer` pass; docs and a tested example via `write-docs` (incl. `CHANGELOG.md`).
