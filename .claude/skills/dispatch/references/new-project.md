# New project — clarifying before you build

**Heavy** new work — a project/app/site/service built from scratch, an empty or near-empty
repo, or a new large subsystem (multiple modules, many files, its own data model) — needs
information gathered before a brief can be written. Skipping this produces a brief built on
guesses, and a first build the user rejects wholesale.

**Light** new things — one script, one small file, a snippet — skip intake, or need at most 1-2
questions. Do not run the order below for "write me a script that renames these files."

## When it applies

Concrete signals, not vibes:

- "Build me a `<thing>`" — an app, site, service, tool — with no existing code to extend
- An empty repo, or one with only scaffolding (README, license, no source)
- A new subsystem the size of its own module set: several files, its own data model, not a
  single addition to something that already exists

None of these hold → this is a normal dispatch. Plan from `AGENTS.md` as usual.

## Intake — one question at a time

Before you write anything, gather enough to plan. In Claude Code, use `AskUserQuestion` with
**a single question per call**, 2-4 concrete options — the user can always type their own
answer, and "you decide" is a legitimate option where sensible. Wait for the answer, then ask
the next. **Never batch** — same rule as [responsive.md](responsive.md).

Skip anything the user already said unprompted. **Stop as soon as you have enough to plan** —
this is intake, not an interrogation; most projects need fewer than all ten questions.

Suggested order:

1. **Purpose and users** — "What is this for, and who uses it?"
2. **Platform** — "Web app / mobile app / desktop app / API / CLI?"
3. **Tech stack** — "Any stack preference, or should I choose one that fits?"
4. **MVP scope** — "What must v1 do? And what should explicitly wait — not in v1?"
5. **Data and auth** — "Does it store data? Do users log in?"
6. **Look and feel** — "Any design direction — a reference site, a vibe, brand colours?" UI
   project → continue straight into [responsive.md](responsive.md)'s questions.
7. **Integrations** — "Any third-party services — payments, email, external APIs?"
8. **Hosting** — "Where should this run once it's built?"
9. **Constraints** — "Deadline, budget, languages to support (e.g. Bangla + English),
   performance or accessibility requirements?"
10. **Definition of done** — "How will you judge v1 is done — a demo, a checklist, a launch?"

Each option list above is a starting point, not a script — phrase the options to fit what the
user already said.

## Confirm before building

Summarise the answers as a short **project brief** and get an explicit yes before scaffolding
anything. Save it as `PROJECT_BRIEF.md` at the repo root — sections matching the questions
above, plus a final **Out of scope for v1** section. This file is the source for planning and
for every dispatch brief that follows; do not re-derive it from memory later.

## Then: scaffold, bootstrap, build

0. **A root commit first.** An empty repo has no `HEAD`: `git rev-parse HEAD` fails, so there
   is no BASE and acceptance cannot diff the scaffold. Propose the user's first commit, holding
   only the brief, and run it on a yes — this skill never commits on its own:
   ```bash
   git add PROJECT_BRIEF.md && git commit -m "chore: project brief"
   git rev-parse HEAD                                  # BASE for the scaffold dispatch
   ```
   On a no, ask the user to make any first commit; do not dispatch the scaffold without one.
1. **Scaffold** the project — a heavy/core task, so per [routing.md](routing.md#model-selection)
   this runs at `opus`. This dispatch is **exempt from SKILL.md's "no map, no dispatch" rule**:
   there is nothing to map yet. Its brief's Knowledge line reads "Read `PROJECT_BRIEF.md` at
   the repo root first; there is no `AGENTS.md` yet", and its Inputs name the directories and
   files the scaffold may create. Acceptance runs as usual, against that BASE.
2. Run **`/dispatch bootstrap`** so `AGENTS.md` exists before any feature dispatch —
   [bootstrap.md](bootstrap.md) reads `PROJECT_BRIEF.md` for "What this project is" when there
   is no code yet to survey.
3. Split the build into dispatches as normal, respecting the
   [2-concurrent-sub-agent cap](routing.md#concurrency-cap). A UI feature still asks the
   responsive questions from responsive.md if `PROJECT_BRIEF.md`'s "Look and feel" answer did
   not already cover them.
