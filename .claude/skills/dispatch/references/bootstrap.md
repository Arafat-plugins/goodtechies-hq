# Bootstrap — building the map

Run once per repo, before any dispatch. Produces `AGENTS.md`: a map written **for sub-agents**,
not for humans. It is the thing that lets an agent start working instead of surveying, and it is
what keeps the main session's own context small.

## What bootstrap does

1. Survey the repo — **this one time**, thoroughly.
2. Write (or append to) `AGENTS.md` at the repo root, inside dispatch markers.
   2b. **Measure** the known-failing baseline by running the test and lint commands.
   2c. **Capabilities** — provision and record how work gets verified here ([setup.md](setup.md)).
3. Install the agent templates into `.claude/agents/`.
4. Point `CLAUDE.md` at `AGENTS.md` if `CLAUDE.md` exists.
5. Record state in `.claude/.dispatch-state.json`.
6. Tell the user what to commit, before the first dispatch.

Bootstrap is the one time heavy reading is correct. Do it in a sub-agent if the repo is large —
the survey's context cost should not land on the main session.

## Step 0 — find the skill directory

Templates are copied from the skill's own `agents/` directory. **If the runtime told you where
this `SKILL.md` was loaded from, use that** — it is the copy you are running. Otherwise list
every candidate with its version:

```bash
{ for d in .claude/skills/dispatch ~/.claude/skills/dispatch .agents/skills/dispatch ~/.agents/skills/dispatch; do
    [ -f "$d/agents/dispatch-implementer.md" ] && echo "$d/agents/dispatch-implementer.md"
  done
  find . ~/.claude ~/.agents -path '*/dispatch/agents/dispatch-implementer.md' 2>/dev/null
} | while IFS= read -r f; do
  d=$(dirname "$(dirname "$f")")
  printf '%s\t%s\n' "$(sed -nE 's/^  version: *//p' "$d/SKILL.md" 2>/dev/null | head -1)" "$d"
done | sort -u
```

Take the first line whose version equals this `SKILL.md`'s `metadata.version` (`1.5.1`) — a
plugin cache can hold older copies, and a stale template installs stale rules. The directory
is only ever taken from a line that printed; nothing printed, or no line with this version →
say so and stop. Do not reconstruct templates from memory, and do not fall back to `.`.

Write the chosen path into your plan as `SKILL_DIR: <path>` and paste it wherever a later
command says `<SKILL_DIR>` — like BASE (acceptance.md), a shell variable does not reliably
survive to the next tool call.

## Step 1 — survey

```bash
ls -la
cat README.md 2>/dev/null | head -60
cat package.json composer.json pyproject.toml go.mod Cargo.toml 2>/dev/null
git log --oneline -15
find . -maxdepth 2 -type d -not -path '*/.*' -not -path '*/node_modules*' -not -path '*/vendor*'
```

Read existing `CLAUDE.md` / `AGENTS.md` / `CONTRIBUTING.md` first if present — they encode
decisions you should not re-derive or contradict.

**Empty or near-empty repo.** If there is no source yet and no `PROJECT_BRIEF.md`, stop and run
the intake first — **[new-project.md](new-project.md)**. Bootstrapping nothing produces an
`AGENTS.md` with nothing to say; the intake produces `PROJECT_BRIEF.md`, and that is what "What
this project is" below is written from.

**Monorepo:** the root `AGENTS.md` is an index — one row per package with its path and the
package's own `AGENTS.md` if it has one — plus the root-level commands and conventions. Write
per-package `AGENTS.md` files only for packages the user names; a brief then says "read the
root `AGENTS.md`, then `packages/<name>/AGENTS.md`".

## Step 2 — write AGENTS.md

**The marker.** Everything bootstrap writes sits between two markers. They are how `status`,
the cycle, and re-runs tell a dispatch map from an `AGENTS.md` some other tool wrote:

```markdown
<!-- dispatch:map v1 -->
...
<!-- /dispatch:map -->
```

Three cases:

- **No `AGENTS.md`** — write the structure below, markers first and last.
- **`AGENTS.md` without the marker** (Codex, Cursor, a human wrote it) — **append**, never
  overwrite. Leave every existing line intact; add the marked region at the end, and inside
  it reference existing sections rather than restating them ("Commands: see *Scripts* above").
- **Marker present** — re-run. Rewrite only the region between the markers.

In the last two cases, write the proposal to a temp file and show the user
`diff -u AGENTS.md <proposal>` before writing. **Never write without showing the diff.**

Structure. Keep it under ~200 lines; a map that costs as much as the territory is not a map.

```markdown
<!-- dispatch:map v1 -->
# AGENTS.md — repo map for sub-agents

Read this before touching anything. It replaces surveying the codebase.

## What this project is
<2-3 sentences: what it does, runtime/version requirements, who consumes it — drawn from
`PROJECT_BRIEF.md` when this project was bootstrapped fresh after the new-project intake>

## Layout — what lives where
| Path | Holds | Edit when |
| --- | --- | --- |
<one row per top-level directory that matters; say plainly which are generated or vendored>

## Where things are
- Entry point / bootstrap: <path>
- Routes / endpoints:      <path — and which file is the source of truth>
- Frontend assets:         <path, plus a per-surface table if there are many>
- Templates / views:       <path>
- Hooks / events / plugins:<path>
- Tests:                   <path, split by kind>
- Config:                  <path>
- Database:                <engine, and the config file holding the connection — never the values>

### Surfaces
| Surface (route / page) | Entry (controller / handler) | View / component | Styles |
| --- | --- | --- | --- |
<one row per surface, generated — see "The Surfaces table" below; framework projects only>

## Conventions that will get a change rejected
<indent, naming, escaping, error handling, i18n, imports — the specific ones, not generic advice>

## Do NOT
- do NOT edit <generated/build output> — it is generated by <command>
- do NOT edit <vendored deps>
- <any architectural boundary that must hold, and what breaks if it does not>

## Commands
| Purpose | Command |
| --- | --- |
| Lint | |
| Test | |
| Build | |

## Known-failing baseline
Measured <ISO date> at <short sha> with `<command>`:
<the failing tests, or "none failing", or "not measured — <reason>". Never "none" unmeasured.>

## Verification capabilities
<written by Step 2c — dev server, rendering, design source, database read-only user; setup.md, step f>

## What not to bother reading
<large generated dirs, vendored code, fixtures, build output — with a one-line reason each>
<!-- /dispatch:map -->
```

*Known-failing baseline* and *What not to bother reading* are the sections people skip and the
ones that pay. "What not to bother reading" is a direct instruction not to spend context, and
the known-failing baseline stops every future agent from re-investigating the same
pre-existing failures.

### The Surfaces table

On a framework project, "Where things are" carries a **Surfaces** table, so a brief can name
the owning files without a grep — the cycle's LOCATE step skips the grep when a row names
them. **Generate it from the framework's own output; never write it from memory.** Paths and
names only — no file bodies:

- **Laravel** — routes to `controller@method`; the Inertia page from the controller's
  `Inertia::render('…')`; Blade views by name (`view('…')` → `resources/views/….blade.php`):
  ```bash
  php artisan route:list --json --except-vendor | php -r 'foreach (json_decode(stream_get_contents(STDIN), true) as $r) echo "{$r["method"]} /{$r["uri"]} → {$r["action"]}\n";' | head -80
  grep -rnoE "Inertia::render\(['\"][^'\"]+" app/Http/Controllers | head -80
  grep -rnoE "view\(['\"][^'\"]+" app/Http/Controllers | head -80
  ```
- **Next.js / Nuxt / SvelteKit** — the routes folder tree, one row per route file; styles are
  the co-located `*.module.css` / `<style>` block, or the global sheet:
  ```bash
  find app src/app -type f \( -name 'page.*' -o -name 'route.*' \) 2>/dev/null | sort                     # Next, app router
  find pages src/pages -type f \( -name '*.vue' -o -name '*.[jt]s' -o -name '*.[jt]sx' \) 2>/dev/null \
    | grep -vE '/_(app|document)\.' | sort                                                                     # Next pages router, Nuxt
  find src/routes -type f \( -name '+page.svelte' -o -name '+server.*' \) 2>/dev/null | sort               # SvelteKit
  ```
- **WordPress** — the theme's template-hierarchy files, plus registered REST routes:
  ```bash
  find <theme> -maxdepth 1 -name '*.php' | grep -E '/(index|front-page|home|single.*|page.*|archive.*|category.*|taxonomy.*|search|404)\.php$'
  find <theme>/templates -maxdepth 1 -name '*.html' 2>/dev/null
  grep -rnoE "register_rest_route\(\s*['\"][^'\"]+['\"]\s*,\s*['\"][^'\"]+" --include='*.php' --exclude-dir=vendor --exclude-dir=node_modules . | head -60
  ```
- **Plain PHP / static** — one row per entry file in the web root
  (`find public -maxdepth 1 \( -name '*.php' -o -name '*.html' \)`, or the root itself).

**Cap it at ~60 rows.** Beyond that, group by prefix — one row `/admin/* (42 routes)` whose
cells say `see app/Http/Controllers/Admin/` — so the map stays a map. The Styles column names
the stylesheet that owns the surface (or "utility classes, in the view" on Tailwind); unknown →
leave it blank rather than guess. No framework and no routes → omit the table.

## Step 2b — measure the baseline

**Run the commands. Do not write "none" because you did not see failures; write it because
you ran the suite and counted zero.** A baseline that was never measured is worse than none —
the next agent trusts it.

```bash
command -v timeout || command -v gtimeout                    # which time limiter exists
timeout 120 <lint command>  > /tmp/dispatch-lint.txt 2>&1; echo "lint exit $?"
timeout 600 <test command>  > /tmp/dispatch-test.txt 2>&1; echo "test exit $?"
grep -ciE '(FAIL|ERROR|✗)' /tmp/dispatch-test.txt          # count, not the output
```

Stock macOS has no `timeout`; with coreutils from Homebrew it is `gtimeout` — use whichever
the first line printed. Neither → drop the prefix and give the tool call its own limit instead
(Claude Code: the Bash tool's `timeout` parameter, at most 10 minutes; elsewhere, the runtime's
equivalent), and record a limit hit as `not measured — timed out`.

Read only the failure names out of the log (`grep -E 'FAIL' | head -40`), not the log.
Record, in that section:

- the date and commit it was measured at
- the exact command
- the list of failing tests, or `none failing`
- or `not measured — <reason>`: timed out at 10 minutes, needs a database that is down, no
  runner installed, needs credentials. The reason is what lets a future run decide whether to
  retry.

Large repo: do this inside the survey sub-agent, and have it return the section text only.

## Step 2c — capabilities

Run **[setup.md](setup.md)**, steps a–f, now. The map says where things are; this step makes
sure the next acceptance can *check* them — a dev server to load, a browser to render at a
width, a design source, a read-only database user — and writes *Verification capabilities*
into the region above.

Bootstrap is a request for setup: propose each missing install, and run it on a yes. Anything
declined or impossible is recorded as `none` and said to the user, never skipped quietly. The
one part that waits: step b's `tools:` edit applies to the frontend agent Step 3 installs, right
after the copy.

## Step 3 — install agents

Read what the repo already has before copying anything:

```bash
find .claude/agents -maxdepth 1 -name '*.md' -exec head -4 {} + 2>/dev/null
```

(`find`, not an `ls` glob: in zsh an unmatched glob aborts the whole command with "no matches
found".) Then install **only the templates whose role is not already covered.** Match by role, not by
filename — a repo with its own `acme-frontend` agent does not need `dispatch-frontend`, even
though the names differ. Purpose-built agents carry conventions the generic templates cannot,
and a second agent covering the same ground just makes routing ambiguous.

```bash
mkdir -p .claude/agents
cp "<SKILL_DIR>/agents/<template>.md" .claude/agents/     # per template you decided to install
```

**Effort.** Every template carries `effort: medium`. Keep that line in the installed copy; for a
repo's own agents that lack an `effort:` line, suggest adding `effort: medium` (routing.md,
*Effort*) — do not edit them without the user's yes.

**Never overwrite an existing agent file.** If a template's role is covered but the existing
agent is weak, say so to the user and let them decide — do not silently replace their work.

**Browser tools for the frontend agent.** The template's `tools:` line has no browser. If Step
2c found a browser MCP the sub-agent can reach and the user said yes, append its browser tool
names — exactly as the session lists them — to the `tools:` line of the *installed copy*
(setup.md, step b). Never delete the line: the agent would inherit every tool, write-capable
MCP servers included. *Verification capabilities* records which, so acceptance knows whether
"verified at 375px" was rendered or read.

**Hot-loading.** Claude Code reads `.claude/agents/` at session start. Files installed now may
not be selectable until the session restarts or `/agents` reloads them. Tell the user. Until
then, routing.md describes the fallback (a general-purpose sub-agent with the template body in
the brief).

Record in `AGENTS.md` which agent covers which role, so routing does not have to re-derive it.

## Step 4 — link CLAUDE.md

If `CLAUDE.md` exists, add a pointer near the top. **Patch, never overwrite** — it is curated:

```markdown
> Sub-agents: read [AGENTS.md](AGENTS.md) first. It is the repo map.
```

If `CLAUDE.md` does not exist, do not create one. `AGENTS.md` is enough.

## Step 5 — record state

```json
{
  "bootstrapped": "<ISO date>",
  "commit": "<short sha of HEAD at bootstrap>",
  "agents_map": "AGENTS.md",
  "baseline_measured": "<ISO date, or null>",
  "capabilities_measured": "<ISO date, or null>",
  "version": "1.5.1"
}
```

at `.claude/.dispatch-state.json`. `status` reads `commit` to measure drift,
`baseline_measured` to age the baseline, and `capabilities_measured` to know setup ran.

## Step 6 — commit, before the first dispatch

Bootstrap leaves the tree dirty. Acceptance diffs against a baseline; if bootstrap's files are
still uncommitted they land in every dispatch's diff. So, before the first dispatch, one of:

```bash
git add AGENTS.md CLAUDE.md .claude/agents/dispatch-*.md .claude/.dispatch-state.json \
        .claude/dispatch/dispatch-measure.mjs
git add <DESIGN.md, .gitignore, manifest + lockfile — whichever Step 2c changed>
git commit -m "chore: dispatch bootstrap"
```

or the snapshot baseline from acceptance.md. Bootstrap does not commit on its own — say the
command and let the user run it.

**What to commit:** `AGENTS.md`, `.claude/agents/dispatch-*.md`, the `CLAUDE.md` patch — yes,
recommended; they are the map every teammate's session needs. So are
`.claude/dispatch/dispatch-measure.mjs`, `DESIGN.md`, and any dev-dependency Step 2c added
(manifest + lockfile) — every teammate's acceptance runs on them. Never
`.claude/dispatch/browsers/`: it is a downloaded binary, gitignored by setup.
`.claude/.dispatch-state.json` — commit it too: it is small, deterministic, and `status` on a
fresh clone depends on it. If
the team prefers it local, add it to `.gitignore`; `status` then reports the state file as
absent and falls back to the marker.

## Re-running

Re-running is safe and is the right move when the layout has drifted or `status` says the
state version is old. It rewrites only the region between the markers, never touches existing
agents, and never overwrites `CLAUDE.md`. Always show the diff of `AGENTS.md` before writing.
