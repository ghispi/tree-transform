---
name: release
description: Cut a release of ghispi/tree-transform — version choice (SemVer, 0.x rules), changelog, checks, annotated tag, push, GitHub release and Packagist. Use when the user or product owner decides to publish a version.
---

# Release

1. **Pick the version.** SemVer; while in 0.x, breaking changes bump the minor (0.1 → 0.2), everything else the
   patch. Check `git log <last tag>..HEAD` and the `[Unreleased]` changelog section.
2. **Changelog.** Rename `[Unreleased]` to `[x.y.z] - YYYY-MM-DD`, add an empty `[Unreleased]` above it, and the
   compare links at the bottom (`[x.y.z]: https://github.com/ghispi/tree-transform/compare/vA...vx.y.z`).
3. **Checks** (Docker only): `make ci`, `PHP_VERSION=8.5 make build install ci`, `make infection`, and the
   lowest-dependency run:
   `docker compose run --rm php composer update --prefer-lowest --prefer-stable` then `make ci`, then
   `make update` to go back.
4. **Docs**: README install line and examples current; `make docs` leaves no diff.
5. **Commit** `Release x.y.z`, then an annotated tag: `git tag -a vx.y.z -m "x.y.z"`. Push the commit and the tag
   (`git push && git push origin vx.y.z`) — pushing is an outward-facing action: do it only when the release was
   decided by the user/product owner.
6. **GitHub release**: create it from the tag with the changelog section as notes (web UI or API with the
   repository's token; never print the token).
7. **Packagist**: the first time, the user submits https://github.com/ghispi/tree-transform on packagist.org and
   enables the GitHub hook; afterwards tags are picked up automatically. Verify the version appears.
