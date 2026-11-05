---
name: pull-request
description: Land a change on main of ghispi/tree-transform — main is protected, so every change goes branch → push → pull request → required checks green → squash merge. Opening and merging PRs uses .claude/scripts/github-api (token from the git credential helper, never printed). Use whenever committed work is to be pushed.
---

# Pull request

`main` has a ruleset: no direct pushes, no force pushes, linear history, a pull request with 0 approvals, squash
merge only, and these checks required (names as GitHub shows them):
`PHP 8.4 (highest deps)`, `PHP 8.4 (lowest deps)`, `PHP 8.5 (highest deps)`, `Integration (MySQL, PostgreSQL)`,
`Backward compatibility`. `Build site` is not required (the docs workflow is path-filtered). Release tags `v*`
can be created but not moved or deleted. Admins can bypass only by merging a PR, never by pushing.

Push only when the user/product owner decided the change should land (see CLAUDE.md).

1. **Branch.** `git switch -c <topic>` from an up-to-date `main` (`git pull --ff-only`). Short kebab-case name.
2. **Commit and check.** Commits as usual; `make ci` passes locally.
3. **Push.** `git push -u origin <topic>`.
4. **Open the PR.** Title = what the squash commit should say; body = why, plus the attribution line.
   ```bash
   .claude/scripts/github-api POST pulls "$(python3 -c 'import json,sys; print(json.dumps({"title":sys.argv[1],"head":sys.argv[2],"base":"main","body":sys.argv[3]}))' "<title>" "<topic>" "<body>")" \
     | python3 -c 'import json,sys; p=json.load(sys.stdin); print(p.get("number"), p.get("html_url"), p.get("message",""))'
   ```
5. **Wait for checks** — once after ~5 minutes, not in a loop (public API, no token):
   ```bash
   curl -s "https://api.github.com/repos/ghispi/tree-transform/commits/<topic>/check-runs" \
     | python3 -c 'import json,sys; [print(c["name"], c["status"], c["conclusion"]) for c in json.load(sys.stdin)["check_runs"]]'
   ```
   On a failure, use the `ci-status` skill to reproduce it locally; fix, push to the same branch.
6. **Merge** (squash; the PR title becomes the commit subject):
   ```bash
   .claude/scripts/github-api PUT pulls/<n>/merge '{"merge_method":"squash"}'
   ```
   GitHub deletes the branch. Locally: `git switch main && git pull --ff-only && git branch -D <topic>`
   (`-D`: squashing means git does not see the branch as merged).

Dependabot PRs: merge the same way once their checks are green.
