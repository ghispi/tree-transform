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
- `make ci` must pass. For changes in `src/`, also run `make infection` (mutation testing) and, for anything on
  a hot path (sources, strategies, sinks), `make bench`.
- Behaviour changes need documentation in the same pull request: the relevant page in `docs/`, and a runnable
  example in `examples/` with its expected output in a `.out` file (checked by the `docs` test suite).
- Add a line to `CHANGELOG.md` under `[Unreleased]`.
- New strategies and sources follow the design in `docs/SPEC.md`: streaming where possible, no recursion, no
  per-node objects, never modify the input. Differential tests compare them with the simple reference
  implementations in `tests/Fixtures`.

## Reporting bugs

Please include the smallest input tree that shows the problem, the code you ran, and the output you expected.
