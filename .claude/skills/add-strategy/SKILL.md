---
name: add-strategy
description: Checklist for adding or changing a filter strategy (FilterStrategy) or a cursor Stage in tree-transform — spec entry, streaming implementation, reference oracle, differential and unit tests, benchmark, docs. Use whenever a new strategy/stage is requested or an existing one changes behaviour.
---

# Add a filter strategy or stage

1. **Spec first.** Add or update the row in `docs/SPEC.md` §5 (strategy) or §6 (stage): rule, result on the running
   example, memory bound, interaction with every `OnMatch` mode. Agree semantics before coding if ambiguous.
2. **Implement** in `src/Strategy/<Name>.php` (implements `FilterStrategy`) or `src/Stage/<Name>.php`
   (implements `Stage`). It returns a `Cursor` decorator; keep state as int stacks / payload references.
   - Call the predicate at most once per node; call `skipSubtree()` on the input whenever the subtree's output is
     already decided, so sources can skip work.
   - Accept `OnMatch` in the constructor if it makes sense; document if it does not.
   - `final`, no recursion, no per-node allocation.
3. **Oracle.** Add the naive recursive equivalent to `tests/Fixtures/Oracle` — obvious code, no optimisation.
4. **Tests.**
   - Unit: running example from the spec for every `OnMatch` mode, plus edge cases (empty, forest, deep chain,
     wide node, multi-level depth drop).
   - Differential: register the strategy in the differential matrix so it runs against all sources and random seeds.
5. **Facade.** Expose it in `Tree` only if it is common; otherwise usable via `filter($p, new Name())`.
6. **Benchmark** subject in `benchmarks/` if it is on a hot path.
7. **Verify** with the `run-checks` skill, then ask the `tree-semantics-reviewer` agent to review.
8. docs and a tested example via the `write-docs` skill (incl. `CHANGELOG.md` entry) under Unreleased.
