# Dependencies — a deliberate path

`/dispatch deps <add|remove|update> <package>`. Every brief says "do NOT add dependencies", and
an agent that needs one stops and reports — correct, and it leaves the task stuck. This is the
way through: a dependency change is **its own dispatch**, with its own brief, its own
acceptance and its own critic pass, before any code uses it.

## When it applies

- A sub-agent stopped: "a dependency is needed" (failures.md, case 4).
- The user asks to add, remove or update a package.
- An audit the user ran, or one quoted in an earlier deps report (`npm audit`, `composer audit`,
  `pip-audit`, …), names a vulnerable version. `status` runs no audit.

The one exception — a version bump of a dependency already present, one line you already hold —
is in **[when-not-to-dispatch.md](when-not-to-dispatch.md)**. Everything else comes here.

## Before dispatching

**Ask first.** Nothing gets installed without saying what and getting a yes. One message:

```
Needs a dependency: <package>@<constraint> (<dev | runtime>), for <why — one line>.
Changes <manifest> and <lockfile>; installs into the repo only. Add it?
```

Then, from the main session, names and versions only — never the package's source:

```bash
npm ls <pkg> --depth=0 2>/dev/null; composer show <pkg> 2>/dev/null | head -3; .venv/bin/pip show <pkg> 2>/dev/null | head -2
npm view <pkg> version license repository.url 2>/dev/null     # or: composer show -a <pkg> | head -8
```

Already present → it is an update or nothing to do. A package with no repository, a licence
the project cannot ship, or a name one typo away from a popular package → say so before the yes.

Clean tree and a baseline, as every dispatch (acceptance.md).

## The brief

**Always `sonnet`**, to `dispatch-implementer` (overridden down on the Agent tool call). The
Task line opens with `Dependency brief (dependencies.md):` — that exact phrase is what lifts the
implementer's "do not add a dependency" rule, for the manifest and lockfile only.

```
## Task
Dependency brief (dependencies.md): <add | remove | update> <package> <exact version constraint>, <dev | runtime> dependency.

## Inputs
Files you may edit:
  - <manifest: package.json | composer.json | pyproject.toml | requirements*.txt | go.mod | Cargo.toml>
  - <its lockfile: package-lock.json | pnpm-lock.yaml | yarn.lock | composer.lock | uv.lock | poetry.lock | go.sum | Cargo.lock>
Nothing else.

## Target
<package> at <constraint> in <manifest>, section <dependencies | devDependencies | require-dev | dev group>.
Install with: <the package manager's own command, from the table below — never by hand-editing the lockfile>

## Out of scope — do NOT
- do NOT write or change any code that uses the package — that is the next brief
- do NOT add, remove or update any other package; do NOT run a blanket update or audit fix
- do NOT install globally (-g, --user, composer global) or outside this repo
- do NOT loosen an existing constraint, or change the package manager or its version
- do NOT survey the repository; read AGENTS.md, then only the files named above
- do NOT commit, push, or change git state

## Knowledge
Read AGENTS.md at the repo root first — Commands names install, test and build.

## Done means
- [ ] manifest has exactly the one change; lockfile updated by the package manager
- [ ] install completes clean (a frozen/ci install from the new lockfile succeeds)
- [ ] existing tests still green, compared with AGENTS.md's known-failing baseline
- [ ] audit output quoted, at most 10 lines: npm audit | composer audit | pip-audit | govulncheck | cargo audit
- [ ] you report per phase: command run, result, verified / not verified

## Report
At most 40 lines. Quote commands and their last lines, not the lockfile.

[ task list broken down into phases, each phase as a vertical slice, numbered ]
```

No network in the sandbox → the agent stops and reports it. Never vendor a package by hand.

| Ecosystem | Add | Remove | Update | Clean install | Audit |
| --- | --- | --- | --- | --- | --- |
| npm | `npm i -D <pkg>@<c>` / `npm i <pkg>@<c>` | `npm rm <pkg>` | `npm i <pkg>@<c>` | `npm ci` | `npm audit --omit=dev` (or full) |
| pnpm / yarn | `pnpm add [-D]` / `yarn add [-D]` | `pnpm remove` / `yarn remove` | `pnpm add <pkg>@<c>` / `yarn add <pkg>@<c>` | `pnpm i --frozen-lockfile` / `yarn install --frozen-lockfile` (Berry: `--immutable`) | `pnpm audit` / `yarn npm audit` |
| Composer | `composer require [--dev] <pkg>:<c>` | `composer remove <pkg>` | `composer update <pkg> --with-dependencies` | `composer install` | `composer audit` |
| Python | `uv add [--dev] <pkg><c>` / `poetry add [--group dev]` | `uv remove` / `poetry remove` | `uv lock --upgrade-package <pkg>` / `poetry update <pkg>` | `uv sync --frozen` / `poetry install --sync` | `pip-audit` (in the venv) |
| Go | `go get <mod>@<v>` | `go get <mod>@none` | `go get <mod>@<v>` | `go mod verify` | `govulncheck ./...` |
| Cargo | `cargo add [--dev] <crate>@<c>` | `cargo remove <crate>` | `cargo update -p <crate> --precise <v>` | `cargo build --locked` | `cargo audit` |

Plain `pip` + `requirements.txt`: edit the one pinned line, `.venv/bin/pip install -r
requirements.txt`, never the system interpreter. No audit tool installed → `not verified: no
audit tool` — installing one is its own ask.

## Acceptance

As acceptance.md, plus:

- `git status --porcelain` lists the manifest and the lockfile — **nothing else**. Any source
  file is a rejection.
- The manifest hunk is the one line (or block) briefed. Read it whole.
- The lockfile diff can be large: read `git diff --stat` for it, then only the added lines
  naming packages and where they resolve from. New transitive packages are a line in your
  verdict, by count:
  ```bash
  git diff <BASE> <AFTER> -- <lockfile> | grep -nE '^\+ *("?(name|version|resolved)"? *[:=]|"node_modules/)' | head -40
  ```
- The audit lines are in the report. A new advisory at high or critical severity is a
  rejection unless the user accepts it by name.

## Then verify — narrowly

Always `/dispatch verify` after a deps dispatch. The scout stage is short: this diff is the
**Dependencies or lockfiles** row of the risk table in
**[verifier.md](verifier.md#stage-1--scout)**. Ask the critic **only** about:

- **provenance** — registry, publisher, repository link, a typosquat-shaped name
- **known advisories** — against the exact resolved versions
- **version pinning** — the constraint matches what was briefed, not wider
- **lockfile integrity** — `resolved` URLs point at the expected registry, integrity hashes
  present, no package appears that the manifest change does not explain

Everything else is out of scope for that critic, and the brief says so.

## Then the code

Accepted and verified → the original task resumes: re-dispatch its brief, now with the package
present, and one Inputs line — "`<package>` is installed; use it, do not add others". That is a
separate brief, at whatever model the task itself calls for.
