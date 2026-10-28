---
name: run-checks
description: Run this project's quality gates inside Docker (code style, PHPStan, PHPUnit, coverage, mutation testing, benchmarks) and interpret failures. Use before declaring any code change done, before commits, or when the user asks to test/check the project.
---

# Run checks (Docker)

All tooling runs in the `php` compose service. Never call host `php`/`composer`.

1. First run or after `composer.json` changes: `make build` (if the image is missing) and `make install`.
2. Fast gate: `make ci` — runs `cs`, `stan`, `test` in that order.
3. On a code-style failure: `make cs-fix`, then re-run `make ci`.
4. Deeper gates when touching `src/` logic:
   - `make coverage` — target ≥ 95% lines.
   - `make infection` — MSI ≥ 95% on core. Do not add `--only-covering-test-cases`: with the differential suite its
     PHPUnit filter regex exceeds PCRE limits, PHPUnit runs no tests, and mutants falsely "escape".
5. Performance-sensitive change (sources, strategies, sinks): `make bench` and compare with the previous run
   (or delegate to the `perf-engineer` agent).
6. Other PHP versions: `PHP_VERSION=8.5 make build install ci`. The lowest supported version must pass.

Single test: `docker compose run --rm php vendor/bin/phpunit --filter <Name>`.

Report results faithfully: paste the failing output, never claim green without running it.
