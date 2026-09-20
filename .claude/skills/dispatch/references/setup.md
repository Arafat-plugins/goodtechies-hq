# Setup — provisioning verification capabilities

`/dispatch setup`, and bootstrap's Step 2c. Bootstrap writes the map; setup makes the repo able
to **check** work against it: render a page at a width, name the design source, reach the
database read-only. Without it, "verified at 375px" is a claim, and the db-tester is one
careless credential away from a write.

Run it alone whenever `status` reports a capability ❌ or ⚠️. It needs the dispatch markers in
`AGENTS.md` — no marker, offer `bootstrap` first (bootstrap runs setup).

## The cycle, per step

Every step below is **detect → propose → install → record**:

1. **Detect** with read-only commands. Paths and names, never file contents you do not need.
2. **Propose** — only when something is missing and installable. Say exactly what: the
   package, the version constraint, dev-only, the command, the files it changes (manifest,
   lockfile, `.gitignore`). One question, then wait. In Claude Code use `AskUserQuestion`;
   elsewhere, ask in one line and wait for the answer.
3. **Install** — on a yes only. Into the repo, never globally: no `npm -g`, no
   `pip install --user`, no system Python, no `composer global`, no `sudo`. Nothing outside the
   repo is modified.
4. **Record** the outcome in *Verification capabilities* (step f) — including "declined" and
   "none". **Never silently fall through**: a missing capability is said to the user and
   written down, because every later acceptance reads it.

Bootstrap counts as the user asking for setup: there you may propose and install on a yes.
Anywhere else, the same — propose first. Never install on your own initiative.

## Step a — runtime and dev server

```bash
for t in node php python3; do command -v "$t" >/dev/null && echo "$t: $("$t" --version 2>&1 | head -1)"; done
command -v go >/dev/null && go version                                  # go has no --version flag
find . -maxdepth 1 \( -name package-lock.json -o -name pnpm-lock.yaml -o -name yarn.lock -o -name 'bun.lock*' \
  -o -name composer.lock -o -name uv.lock -o -name poetry.lock -o -name Pipfile.lock -o -name 'requirements*.txt' -o -name go.sum \)
node -e 'const s=require("./package.json").scripts||{};for(const k of ["dev","start","serve","preview"])if(s[k])console.log(k+": "+s[k])' 2>/dev/null
grep -oE '"(dev|serve|start)": *"[^"]*"' composer.json 2>/dev/null; grep -E '^(dev|serve|run|start):' Makefile 2>/dev/null
find . -maxdepth 1 \( -name artisan -o -name manage.py -o -name wp-config.php -o -name 'next.config.*' -o -name 'nuxt.config.*' \
  -o -name 'vite.config.*' -o -name 'svelte.config.*' -o -name 'astro.config.*' \)
```

**Globs go through `find`, never an `ls` glob.** In zsh (the macOS default shell) an unmatched
glob aborts the whole command with "no matches found", hiding the files that do exist; a
quoted `-name` pattern behaves the same in every shell.

The lockfile names the package manager (`npm`, `pnpm`, `yarn`, `bun`, `composer`, `uv`,
`poetry`, `pip`). The dev-server command comes from the scripts, else the framework's
convention:

| Signal | Command | URL |
| --- | --- | --- |
| `vite.config.*`, script `vite` | `npm run dev` | `http://localhost:5173` |
| `next.config.*` / `nuxt.config.*` | `npm run dev` | `http://localhost:3000` |
| `artisan` | `php artisan serve` (+ `npm run dev` for assets) | `http://127.0.0.1:8000` |
| `manage.py` | `python manage.py runserver` | `http://127.0.0.1:8000` |
| `wp-config.php` | the site manager's — ask the user | the site's local URL |

The URL column is a default. **Record the URL the dev server itself prints** (Vite's `Local:`
line, for one) whenever you see it — the user pastes it, or the server is already running.
Vite on Node 17+ binds `localhost`, which can resolve to `::1` only, so `127.0.0.1:5173` is
refused while `localhost:5173` works. Unsure → ask the user for the command and URL, one
question. Do not start the server to find out. Record both.

**UI project?** Setup's steps b and d apply only to repos with something to render:

```bash
git ls-files | grep -cE '\.(css|scss|sass|less|vue|svelte|jsx|tsx|astro|blade\.php|twig|html)$'
find . -maxdepth 1 \( -name 'tailwind.config.*' -o -name 'postcss.config.*' -o -name theme.json \)
```

Zero and no config → not a UI project; record `Rendering: n/a (no UI)` and skip b and d. This
is **the UI-project test**: `status` runs the same two lines, so keep the extension list here
and only here.

## Step b — a browser for rendering

In order; stop at the first that holds.

**1. A browser MCP is already available.** Look at **your own tool list first** — the tools
this session can call (deferred ones included) — for playwright, puppeteer, chrome,
claude-in-chrome, or Claude_Browser. Then the configured servers: in Claude Code
`claude mcp list`; elsewhere the runtime's MCP config (`.mcp.json`, `.cursor/mcp.json`,
`.vscode/mcp.json`) or its equivalent command. `claude mcp list` shows configured servers only:
a connected Chrome (claude-in-chrome) or a connector browser (Claude_Browser) usually is not
in it, and sub-agents often cannot reach those tools at all.

- Take the tool names **exactly** as the session lists them — e.g.
  `mcp__playwright__browser_navigate, mcp__playwright__browser_resize, mcp__playwright__browser_evaluate`.
  Never guess a name.
- **Chrome and connector browsers are `(main session only)` by default** — a browser only the
  main session can drive still counts for acceptance; record it with that suffix, leave the
  frontend agent's `tools:` line alone, and the agent keeps reporting widths as read, not
  rendered (or runs the script, below). Only a server `claude mcp list` / the MCP config shows
  (a project- or user-scoped playwright, puppeteer) is offered to the sub-agent.
- For such a server, **propose appending its tool names to the `tools:` line of the installed
  `.claude/agents/dispatch-frontend.md`** (the repo's copy, never the skill's template) — the
  browser tools only, by name. Never delete the `tools:` line: the agent would inherit every
  tool in the session, write-capable database or deploy servers included. During bootstrap the
  copy does not exist yet — Step 3 applies this right after copying. A repo's own frontend
  agent is the user's file: propose the edit, do not make it.
- Record `Rendering: MCP <name>` (or `MCP <name> (main session only)`) and the tools line as
  installed.

**2. Else, Playwright in the repo.** Already there? Ask the script itself, from the repo root —
the same lookup every later measurement uses, so the probe and the run cannot disagree:

```bash
node "<SKILL_DIR>/scripts/dispatch-measure.mjs" --probe    # <SKILL_DIR>: the path bootstrap.md Step 0 printed
```

`renderer: …` and exit 0 → skip to the smoke run below. The lookup is `$DISPATCH_PYTHON` if
set, else Node `playwright` in `./node_modules` only, else Python `playwright` in `.venv` or
`venv` — never a global install or the system interpreter (a virtualenv outside the repo, as
poetry makes by default: record `DISPATCH_PYTHON=<its python>` in the Rendering line, and every
measure command is then prefixed with it). Exit 3 prints what is missing and its fix. Then propose, dev-only and pinned in the lockfile, with
browsers kept inside the repo:

```bash
# Node project — use the repo's package manager (pnpm add -D, yarn add -D, bun add -d)
npm i -D playwright
PLAYWRIGHT_BROWSERS_PATH="$PWD/.claude/dispatch/browsers" npx playwright install chromium
# Python project — inside the project's virtualenv, never the system interpreter;
# pin it in the dev group (uv add --dev / poetry add --group dev / requirements-dev.txt)
.venv/bin/python -m pip install playwright
PLAYWRIGHT_BROWSERS_PATH="$PWD/.claude/dispatch/browsers" .venv/bin/python -m playwright install chromium
```

`PLAYWRIGHT_BROWSERS_PATH` keeps the ~150 MB browser out of the user's home cache; the measure
script picks that directory up on its own. Add `.claude/dispatch/browsers/` to the repo's
`.gitignore` in the same proposal. Never run `playwright install-deps` — it needs `sudo`; if
chromium will not start for missing system libraries, say so and fall to 3. No virtualenv on
a Python project → do not create one unasked; ask, or fall to 3.

On a yes: install, then record `Rendering: local Playwright, run .claude/dispatch/dispatch-measure.mjs`.

**3. Else, none.** The user declined, or nothing can be installed. Record
`Rendering: none — every width is reported Not verified` and **say so to the user** in those
words. UI briefs still dispatch; acceptance reports every width as *Not verified*.

**Smoke run.** If the dev server is already up, run the measure script once at 375 (or one
MCP resize-and-evaluate) and record the result. Server down → record
`(not yet exercised — dev server was down)`; do not start it just for this.

## Step c — the measure script

The skill ships `scripts/dispatch-measure.mjs`. Copy it into the target repo — `.claude/dispatch/`,
not the project's `scripts/`, so it never collides with the project's own files.

**Ask before steps b and c write anything.** Neither the `tools:` edit nor the copy happens on
detection alone: put both in **one** proposal — "copy `dispatch-measure.mjs` to
`.claude/dispatch/`; append `<tool names>` to `.claude/agents/dispatch-frontend.md`'s `tools:`
line" — and wait for the yes. A copy already there is compared first; if it differs, show the
diff in that proposal, and replace it only on the yes:

```bash
cmp -s "<SKILL_DIR>/scripts/dispatch-measure.mjs" .claude/dispatch/dispatch-measure.mjs \
  || diff -u .claude/dispatch/dispatch-measure.mjs "<SKILL_DIR>/scripts/dispatch-measure.mjs" | head -60
# on the yes:
mkdir -p .claude/dispatch
cp "<SKILL_DIR>/scripts/dispatch-measure.mjs" .claude/dispatch/
node .claude/dispatch/dispatch-measure.mjs --help | head -2
```

Copy it whatever step b found: it needs only Node, and it is the one measuring command
acceptance, responsive checks and the frontend agent all call:

```bash
node .claude/dispatch/dispatch-measure.mjs <url> <width>... [--select <css selector> --prop <computed property>]
```

A `renderer:` line, then one line per width — overflow yes/no with the px, plus the computed
value when asked (custom properties too: `--prop --brand`). It starts nothing: an unreachable
URL prints `dev server not reachable at <url>` and exits 2, and so does a redirect (the line
names the final URL); no Playwright (Node or Python) exits 3 with the fix. No Node in the repo → skip the copy, record it, and the
MCP route (or *Not verified*) is the only one. A declined copy is recorded like any other
declined step.

## Step d — design guidance (UI projects)

The frontend agent reads one file before any UI brief, the way the implementer reads
`AGENTS.md`: **`DESIGN.md`** at the repo root. Without it every UI brief re-explains taste, or the
result is generic. It describes the project's design as it is — setup generates it, it does not
invent a new one.

**`DESIGN.md` exists** → leave it; record `Design source: DESIGN.md`. **It does not** → build a
proposal from what exists. Names and values only, with generated and vendored paths excluded:

```bash
find . -maxdepth 1 \( -name 'tailwind.config.*' -o -name theme.json \); find src -maxdepth 2 \( -name 'theme*' -o -path '*/styles/tokens*' \) 2>/dev/null   # theme sources
grep -rhoE --exclude-dir=node_modules --exclude-dir=vendor --exclude-dir=dist --exclude-dir=build \
  --include='*.css' --include='*.scss' -e '--[a-zA-Z0-9-]+:' . | sort | uniq -c | sort -rn | head -40
grep -rhoE --exclude-dir=node_modules --exclude-dir=vendor --exclude-dir=dist --exclude-dir=build \
  --include='*.css' --include='*.scss' -e '@media[^{]+' . | sort | uniq -c | sort -rn | head -20
grep -oE '"[@a-z0-9/-]*(design|ui|theme|tokens)[a-z0-9-]*"' package.json 2>/dev/null    # a design-system package
find design docs -maxdepth 2 -path '*design*' -type f 2>/dev/null | head -20              # reference images
```

The `--exclude-dir` flags are written out on purpose: an unquoted `$X` holding them is one
argument in zsh, which does not word-split.

Read the theme config itself (it is small); do not read stylesheets whole. On a large repo, do
this in a sub-agent that returns the proposal only.

Then ask **at most three** questions, **one question per message**, skipping any the files
already answer: brand palette? a reference product or images to follow? density — compact or
comfortable? Write the proposal, show it (`cat` for a new file) and write it only on a yes.
Under ~150 lines:

```markdown
# DESIGN.md — design source for UI work

## Tokens
<colour, type scale, spacing, radius, shadow — by the project's actual variable / theme-key names;
a spacing scale listed here makes acceptance check margin / padding / gap values against it>

## Breakpoints
<the project's own, from the @media scan — the only widths a UI change may use>

## Components
<the 5–10 patterns the project repeats — stat card, table row, pill, modal, sidebar item —
one paragraph each: anatomy, states, responsive behaviour>

## References
<paths to reference images; what to take from each, what to leave>

## Never
<the anti-patterns this project rejects — e.g. more than 3 charts per screen, productivity
visualisations, `!important`>
```

**Optional design skill.** If the runtime's skill registry offers a frontend-design skill, it
can sit beside `DESIGN.md`. Check with the registry's own command — the session's skill list
first; then, after asking (it fetches a CLI), e.g. `npx skills --help` and
`npx skills search frontend-design`. **Do not assume a name.** Found → offer to install it **into
the repo** (`.claude/skills/`, the registry's project scope — never the global one) and record
its name. Not found → `DESIGN.md` is the design source, and that is fine: say so, never
fabricate a skill name.

Record `Design source: DESIGN.md`, `DESIGN.md + <skill name>`, or `none` (the user declined —
say so; UI briefs then carry their design rules in **Format**).

## Step e — database guards

For the engine `AGENTS.md` names (no database → record `Database: none in this repo`, skip).
The db-tester's read-only posture is only as good as the credential it connects with, so this
step makes sure a read-only one exists — **provisioned, not assumed**.

**Detect** a read-only credential, by key name only — `-l` and `cut` keep values out of the
transcript (db-check.md):

```bash
find . -maxdepth 1 -type f -name '.env*' -exec grep -lE '^(DB_RO_|DATABASE_URL_RO|DB_READONLY_|PG_RO_|MYSQL_RO_|MONGO_URI_RO)' {} +
find . -maxdepth 1 -type f -name '.env*' -exec grep -ohE '^(DB_RO_[A-Z_]*|DATABASE_URL_RO|DB_READONLY_[A-Z_]*|PG_RO_[A-Z_]*|MYSQL_RO_[A-Z_]*|MONGO_URI_RO)=' {} + | cut -d= -f1 | sort -u
git check-ignore -q .env && echo ".env is ignored"
```

Also count a user that `AGENTS.md` already names as read-only.

**None found → print the SQL, do not run it.** The user runs it in their own client, as an
account allowed to create users, and confirms. Passwords are chosen by the user and never
enter this session:

| Engine | Create a read-only user | Store it under |
| --- | --- | --- |
| PostgreSQL | `CREATE ROLE dispatch_ro LOGIN;` then `\password dispatch_ro` in psql; `GRANT CONNECT ON DATABASE <db> TO dispatch_ro; GRANT USAGE ON SCHEMA public TO dispatch_ro; GRANT SELECT ON ALL TABLES IN SCHEMA public TO dispatch_ro; ALTER DEFAULT PRIVILEGES FOR ROLE <owner> IN SCHEMA public GRANT SELECT ON TABLES TO dispatch_ro; ALTER ROLE dispatch_ro SET default_transaction_read_only = on;` | `DATABASE_URL_RO`, or `DB_RO_USER` + a `PGPASSFILE` entry |
| MySQL / MariaDB | `CREATE USER 'dispatch_ro'@'localhost' IDENTIFIED BY '<chosen by you>'; GRANT SELECT, SHOW VIEW ON <db>.* TO 'dispatch_ro'@'localhost';` | `DB_RO_USER`, `DB_RO_PASSWORD` — the db-tester feeds them to `--defaults-extra-file` through process substitution (db-check.md); nothing is written to disk |
| SQLite | no user — `sqlite3 -readonly <file>` is the guard, always | nothing to store |
| MongoDB | `use admin` then `db.createUser({ user: "dispatch_ro", pwd: passwordPrompt(), roles: [{ role: "read", db: "<db>" }] })` | `MONGO_URI_RO` |

Name the schema(s) and owner role for Postgres from the migrations or config, not a guess; more
than `public` → one `GRANT USAGE` / `GRANT SELECT` line each. The env file must be one git
ignores — if `.env` is not ignored, say so before the user stores anything in it.

**Record** what came back: `Database: postgres, read-only user: dispatch_ro (DATABASE_URL_RO)`,
or `Database: mysql, read-only user: none — db-tester will refuse write-capable credentials`
when the user has not created one yet. SQLite: `read-only user: n/a (sqlite3 -readonly)`.

## Step f — record

Inside the dispatch markers, after *Known-failing baseline*, write (or rewrite) exactly this
section. Show `diff -u AGENTS.md <proposal>` first, as bootstrap does; setup run alone changes
this section and nothing else:

```markdown
## Verification capabilities

Measured <ISO date> by `/dispatch setup`:

- Dev server: `<command>` → <url>
- Rendering: <MCP name | MCP name (main session only) | local Playwright | none | n/a (no UI)>  — frontend agent tools: <line as installed>
- Design source: <DESIGN.md | design skill name | none>
- Database: <engine>, read-only user: <name | none — db-tester will refuse write-capable credentials>
- Lint / test / build: see Commands
```

Then set `"capabilities_measured": "<ISO date>"` in `.claude/.dispatch-state.json`
(bootstrap.md, Step 5). `status` reads both — **[status.md](status.md)**.

End by telling the user, in at most six lines: what was found, what was installed, what was
declined, what is still *none*, and the files to commit with the map — `AGENTS.md`,
`.claude/dispatch/dispatch-measure.mjs`, the installed agent's changed `tools:` line, the
manifest and lockfile if Playwright was added, `.gitignore`. Setup does not commit.
