---
name: ci-status
description: Check GitHub Actions results for ghispi/tree-transform without the gh CLI or the token — list runs and failing jobs via the public API, reproduce failures locally (lowest dependencies, mutation testing), and know which workflows run when. Use after pushing, before a release, or when the user mentions CI or a failing run.
---

# CI status

## Workflows

- `.github/workflows/ci.yml` — every push to `main` and every PR: PHP 8.4 and 8.5 (highest deps) and PHP 8.4
  (lowest deps), each `make ci`; superseded runs are cancelled; 20-minute job limit.
- `.github/workflows/mutation.yml` — Mondays 04:00 UTC and on demand (Actions tab → Run workflow): `make infection`,
  60-minute limit (a local 4-thread run, like a hosted runner, takes ~18 min). Standard hosted runners are free for this public repository.

## Reading results (public API, no auth)

```bash
curl -s "https://api.github.com/repos/ghispi/tree-transform/actions/runs?per_page=10&branch=main" | python3 -c "
import json,sys,urllib.request
for r in json.load(sys.stdin)['workflow_runs'][:5]:
    print(r['head_sha'][:7], r['name'], r['status'], r['conclusion'])
    for j in json.load(urllib.request.urlopen(r['jobs_url']))['jobs']:
        print('   ', j['name'], j['conclusion'], [s['name'] for s in j['steps'] if s['conclusion'] == 'failure'])"
```

- Job logs need authentication: don't use the repository token for them. Reproduce the failing job locally instead,
  or ask the user to paste the log.
- Check once after a reasonable delay (CI ~5 min, mutation ~20 min); don't poll in a loop.

## Reproducing

- Highest deps: `make ci` (or `PHP_VERSION=8.5 make build install ci`).
- Lowest deps: in a scratch clone (see the `release` skill) — never run `--prefer-lowest` in the working tree.
- Mutation: `make infection`, alone on the machine; if GitHub fails but local passes, suspect timeouts/skips from
  slower runners and compare the summary counts (killed, escaped, timed out, skipped).
