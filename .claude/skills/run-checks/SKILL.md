---
name: run-checks
description: Run this project's quality gates inside Docker (code style, PHPStan, PHPUnit, coverage, mutation testing, benchmarks) and interpret failures. Use before declaring any code change done, before commits, or when the user asks to test/check the project.
---

# Run checks (Docker)

All tooling runs in the `php` compose service. Never call host `php`/`composer`.

1. First run or after `composer.json` changes: `make build` (if the image is missing) and `make install`.
2. Fast gate: `make ci` — `cs`, `stan` (level max over `src` and `tests`), `test` (unit, differential,
   integration, docs; ~30 s).
3. Code-style failure: `make cs-fix`. To fix only some paths, the fixer needs the config:
   `docker compose run --rm php vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php --path-mode=intersection <paths>`.
4. Docs drift (`DocsInSyncTest` / `ExamplesTest` failures): `make docs`, then read the `examples/*.out` diff.
5. Deeper gates when touching `src/` logic:
   - `make coverage` — ≥ 95% lines of core.
   - `make infection` — MSI ≥ 90% (takes 8–20 min). Run it alone: under heavy load mutants time out and count as
     killed, inflating the score. Never add `--only-covering-test-cases`: the differential suite makes its PHPUnit
     filter regex exceed PCRE limits, PHPUnit runs no tests, and mutants falsely "escape". `--filter=src/File.php`
     does not work with this suite's `CoversClass` attributes either.
6. Performance-sensitive change (sources, strategies, sinks): benchmarks via the `perf-engineer` agent or `make bench`.
7. Other PHP versions: `PHP_VERSION=8.5 make build install ci`. Lowest dependencies: see the `release` skill
   (use a scratch clone).
8. GitHub CI: see the `ci-status` skill.

Single test: `docker compose run --rm php vendor/bin/phpunit --filter <Name>`.

Report results faithfully: paste the failing output, never claim green without running it.
