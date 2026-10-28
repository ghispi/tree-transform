---
name: release
description: Cut a release of ghispi/tree-transform — version choice (SemVer, 0.x rules), changelog, checks, annotated tag, push, GitHub release notes, Packagist verification and a clean-install smoke test. Use when the user or product owner decides to publish a version.
---

# Release

1. **Pick the version.** SemVer; while in 0.x, breaking changes bump the minor (0.1 → 0.2), everything else the
   patch. Read `git log <last tag>..HEAD` and the `[Unreleased]` changelog section.
2. **Checks** (Docker only; run them one after another, not in parallel):
   - `make ci` and `make docs` (must leave no diff).
   - GitHub CI green on the commit you release (PHP 8.4, 8.5, lowest deps) — see the `ci-status` skill. To run the
     lowest-dependency job locally, use a scratch clone (never the working tree, its `vendor/` is shared):
     `git clone . <scratch> && docker run --rm -u $(id -u):$(id -g) -e COMPOSER_HOME=/tmp/c -v <scratch>:/app -w /app tree-transform-php:8.4 sh -c 'composer update --prefer-lowest --prefer-stable -q && vendor/bin/phpstan analyse && vendor/bin/phpunit'`.
   - `make infection` (MSI ≥ 90%, alone on the machine).
3. **Changelog.** Rename `[Unreleased]` to `[x.y.z] - YYYY-MM-DD`, add an empty `[Unreleased]` above it, and update
   the links at the bottom (`[Unreleased]: …/compare/vx.y.z...HEAD`, `[x.y.z]: …/compare/vA...vx.y.z`, or
   `…/releases/tag/vx.y.z` for the first release).
4. **Commit and tag.** Commit `Release x.y.z`, then `git tag -a vx.y.z -m "x.y.z"`; push the commit and the tag
   (`git push && git push origin vx.y.z`) only when the release was decided by the user/product owner.
5. **GitHub release.** Write the changelog section plus install and docs links to `var/release-notes-x.y.z.md` and
   give the user `https://github.com/ghispi/tree-transform/releases/new?tag=vx.y.z`. Do not call the API with the
   repository token (it stays limited to git). Label: **none** (not pre-release) for plain `x.y.z` tags; 0.x already
   signals instability, and pre-releases are not shown as Latest. Pre-release only for `-rc`/`-beta` tags.
6. **Packagist.** The package and its GitHub webhook exist; a pushed tag appears within a minute. Verify:
   `curl -s https://repo.packagist.org/p2/ghispi/tree-transform.json` lists the version.
7. **Smoke test** in a fresh project (scratch dir, Docker): `composer init` + `composer require ghispi/tree-transform`,
   run a short script using the new feature, confirm the installed version and output.
