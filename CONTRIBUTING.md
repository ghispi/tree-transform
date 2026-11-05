# Contributing

Thanks for helping! Bug reports, ideas and pull requests are welcome.

## Development setup

Everything runs in Docker; you only need Docker and `make`.

```bash
make build install   # build the PHP image and install dependencies
make ci              # code style, PHPStan (level max), all PHPUnit suites
make help            # every target
```

Use another PHP version with `PHP_VERSION=8.5 make build install ci`.

## Pull requests

- One topic per pull request, with tests.
- `make ci` must pass. For changes in `src/`, also run `make infection` (mutation testing, MSI ≥ 90%) and, for anything on
  a hot path (sources, strategies, sinks), `make bench`. For changes to the Gedmo bridge, run `make test-db`
  (integration tests on MySQL and PostgreSQL in Docker; `make db-down` stops them).
- Behaviour changes need documentation in the same pull request: the relevant page in `docs/`, and a runnable
  example in `examples/` with its expected output in a `.out` file (checked by the `docs` test suite).
  `make docs-site` builds the documentation site into `var/docs-site/site` and fails on broken links.
- Add a line to `CHANGELOG.md` under `[Unreleased]`.
- Public API changes follow the [compatibility promise](docs/api.md#compatibility-promise). If `make ci` fails in
  the `api` suite, the public API or its behaviour changed. If that was intended, run `make api-snapshot`, commit
  the diff of `tests/Api/public-api.txt` / `tests/Api/behaviour.txt` and update `docs/api.md`. `make bc-check` and
  `make behaviour-check` compare with the latest release; since 1.0 a break needs a new major version, and a bug
  fix that changes released behaviour is listed in `tests/Api/behaviour-changes.txt`.
- A new public feature gets cases in `tests/Api/Behaviour.php` (inputs written out there, not taken from fixtures).
- New strategies and sources follow the design in `docs/SPEC.md`: streaming where possible, no recursion, no
  per-node objects, never modify the input. Differential tests compare them with the simple reference
  implementations in `tests/Fixtures`.

## Reporting bugs

Please include the smallest input tree that shows the problem, the code you ran, and the output you expected.
