# Status — what is set up, what is stale, what to run next

`/dispatch status`, and the default when `/dispatch` has no argument. Read-only. Runs the
checks below, prints one table, ends with one recommendation. It reads
`.claude/.dispatch-state.json` — the only mode that reads it (bootstrap and setup write it).

## Checks

Run all of them; do not stop at the first failure.

```bash
# 1. map
test -f AGENTS.md && grep -c '<!-- dispatch:map v1 -->' AGENTS.md
# 2. agents (role coverage, by description — see routing.md)
find .claude/agents -maxdepth 1 -name '*.md' -exec grep -H '^description:' {} + 2>/dev/null | cut -c1-140
find .claude/agents -maxdepth 1 -name '*.md' -newer .claude/.dispatch-state.json 2>/dev/null   # edited since bootstrap/setup
# 3. state
cat .claude/.dispatch-state.json 2>/dev/null
# 4. drift since bootstrap (needs "commit" from the state file)
git diff --stat <state.commit> HEAD | tail -1
git diff --name-only --diff-filter=ADR <state.commit> HEAD | cut -d/ -f1-2 | sort -u | head -20
# 5. CLAUDE.md link
test -f CLAUDE.md && grep -c 'AGENTS.md' CLAUDE.md
# 6. working tree
git status --porcelain | wc -l
# 7. skill dir (see bootstrap.md for the search)
# 8. capabilities — the recorded section, then cheap live probes of what it claims
sed -n '/^## Verification capabilities/,/^## /p' AGENTS.md | head -12
test -f .claude/dispatch/dispatch-measure.mjs && node .claude/dispatch/dispatch-measure.mjs --probe
#   the script's own lookup (DISPATCH_PYTHON, ./node_modules, .venv/venv) and a chromium start:
#   "renderer: …" and exit 0, or exit 3 with the fix for this repo's ecosystem
test -f DESIGN.md && echo design-md
# 9. UI project? — the same UI-project test as setup.md (end of step a, which gates step b);
#    run its two lines from there, do not keep a second extension list here
```

| Check | Green | Not green — report as |
| --- | --- | --- |
| Map | `AGENTS.md` present **with** marker | absent → "not bootstrapped"; present without marker → "foreign AGENTS.md — bootstrap will append a dispatch section, not overwrite" |
| Agents | each of the four roles (implement, frontend, db, critic) covered by some agent, and each file among the agents the runtime has loaded | list the uncovered roles. A file on disk that the runtime's agent list does not offer (Claude Code: the Agent tool's list of agent types) → "not loaded — restart or `/agents`"; no such list to compare, but the file is newer than `.claude/.dispatch-state.json` → "edited since bootstrap/setup — a restart may be needed to load it" |
| State file | present, `version` equals this skill's `metadata.version` | absent → bootstrap did not finish, or the file is gitignored on this clone; older version → "re-run bootstrap to upgrade the map" |
| Layout drift | 0 added/deleted/renamed paths under directories the Layout table names | list them; any directory that appears or disappears means the map is wrong for it |
| Baseline age | `baseline_measured` within 30 days, or `not measured` with a reason | "known-failing baseline is N days old — re-measure" (bootstrap Step 2b) |
| CLAUDE.md | absent, or contains a pointer to `AGENTS.md` | "CLAUDE.md does not point sub-agents at AGENTS.md" |
| Working tree | clean | "N uncommitted paths — commit, stash, or snapshot before the first dispatch (acceptance.md)" |
| Capabilities | *Verification capabilities* present and `capabilities_measured` set | absent → ❌ "not provisioned — `/dispatch setup`"; otherwise one line per capability, below |

After the checks above, report each line of *Verification capabilities* as ✅ / ⚠️ / ❌ with
the **one** command that fixes it:

| Line | ✅ | ⚠️ | ❌ |
| --- | --- | --- | --- |
| Dev server | command and URL recorded | not recorded → `/dispatch setup` | — |
| Rendering | MCP named (sub-agent or `(main session only)`); or local Playwright and `--probe` exits 0; or `n/a (no UI)` | `none` on a UI project → `/dispatch setup` | local Playwright recorded but `--probe` exits 3 → the fix its line names — for Node `PLAYWRIGHT_BROWSERS_PATH="$PWD/.claude/dispatch/browsers" npx playwright install chromium`, for Python `PLAYWRIGHT_BROWSERS_PATH="$PWD/.claude/dispatch/browsers" "<py>" -m playwright install chromium`, for a missing package `/dispatch setup`; the script itself gone → `/dispatch setup` |
| Design source | `DESIGN.md` exists, or a design skill is named and installed | `none` on a UI project → `/dispatch setup` (step d) | `DESIGN.md` recorded but missing → `/dispatch setup` |
| Database | read-only user named; SQLite (`-readonly`); or no database | — | `none` → run the read-only-user SQL for the engine (`/dispatch setup` prints it, step e); the db-tester refuses until then |

A repo with rendering = none and a frontend framework present is ⚠️, with this sentence:
"UI briefs will be accepted as *Not verified* for every width until this is set up."

## Output

```
Map:        AGENTS.md, dispatch marker present, bootstrapped 2026-09-01 at 3f2a9c1
Agents:     implementer ✓  frontend ✓  db-tester ✓  critic ✓   (4 in .claude/agents/)
State:      version 1.5.1 (current)
Drift:      12 commits since bootstrap; 0 layout changes
Baseline:   measured 2026-09-01 (14 days) — 11 failing
CLAUDE.md:  linked
Tree:       clean
Capabilities (measured 2026-09-01):
  ✅ Dev server     npm run dev → http://localhost:5173
  ⚠️ Rendering      none — UI briefs will be accepted as *Not verified* for every width until this is set up. Fix: /dispatch setup
  ✅ Design source  DESIGN.md
  ❌ Database       postgres, no read-only user. Fix: run the SQL from /dispatch setup (step e)
Next:       /dispatch setup
```

## Recommendation rules

In priority order — the first that applies is the `Next:` line:

1. No map, or foreign map → `/dispatch bootstrap`
2. State version older than the skill → `/dispatch bootstrap` (re-run upgrades the region between the markers)
3. Layout drift → `/dispatch bootstrap` (re-run; show the AGENTS.md diff first)
4. Baseline older than 30 days or `not measured` → re-measure (bootstrap Step 2b alone)
5. Uncovered agent role → install that template (bootstrap Step 3 alone)
6. Capabilities absent, or any ❌ / ⚠️ → `/dispatch setup` (for a database ❌, the SQL it prints)
7. Dirty tree → commit or snapshot, then dispatch
8. Otherwise → `/dispatch <task>`

Status never writes anything and never installs anything — the probes above only read. It says
what to run; the user runs it.
