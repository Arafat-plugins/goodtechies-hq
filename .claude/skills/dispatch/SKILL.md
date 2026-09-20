---
name: dispatch
description: Delegate implementation work to sub-agents while the main session keeps a clean context. The main session plans, hands each sub-agent an explicit written spec plus the exact files to touch, then judges the returned diff itself. Run `/dispatch bootstrap` once per repo to generate the AGENTS.md map that sub-agents read instead of surveying the codebase. Use when a task needs real file edits, when the main session is filling up with file contents it does not need, when sub-agents keep re-reading the whole project before doing anything, or when the user asks to dispatch, delegate, orchestrate, or route work to sub-agents.
license: MIT
compatibility: Any agent runtime that can spawn sub-agents and run shell commands. Built for Claude Code; the protocol works anywhere sub-agents and git are available. Git is required for the acceptance and verify steps.
metadata:
  version: 1.5.1
  author: Arafat-plugins
---

# Dispatch

You are the **owner** of this task, not a relay. You hold the plan and the working
knowledge of the repo. You do not burn your context doing the work.

## The one rule

**Hold the map, not the territory.**

- Read `AGENTS.md` (and `CLAUDE.md` if present). Compact, stable, a few KB. That is your
  knowledge of this repo.
- **Do not open the files you are about to dispatch.** Locating a file is cheap — one `grep`,
  one line of output. Reading 900 lines of a stylesheet is not. The sub-agent reads it.
- On acceptance, read the **diff**, never the whole file. The diff is what you are judging.

Every rule below serves this one. If you catch yourself reading a file's contents to decide
what to put in a brief, stop — that is the sub-agent's job.

## Your three roles, in order

1. **Planner** — decide what the change actually is. Ask the user if the ask is ambiguous.
2. **Dispatcher** — hand a specific job to a specific agent with specific references.
   Never "go figure out the codebase."
3. **Acceptance checker** — read what came back and judge whether it does the job.
   **This is yours. Do not delegate it.**

## Modes

Pick by what follows the command. With no argument, run `status`.

| Invocation | Mode |
| --- | --- |
| `/dispatch bootstrap` | Generate `AGENTS.md` for this repo + install agent templates. **Run this first.** |
| `/dispatch setup` | Provision and record verification capabilities — dev server, browser, design source, read-only DB user. Bootstrap runs it — **[references/setup.md](references/setup.md)** |
| `/dispatch new <idea>` | Heavy new project: one-question-at-a-time intake, then scaffold + bootstrap — **[references/new-project.md](references/new-project.md)** |
| `/dispatch <task>` | The full cycle: plan → locate → brief → work → accept |
| `/dispatch deps <add\|remove\|update> <package>` | A dependency change as its own dispatch: manifest + lockfile only, then a narrow verify — **[references/dependencies.md](references/dependencies.md)** |
| `/dispatch verify` | Security critic chain over the current diff |
| `/dispatch db <check>` | Database inspection via the db-tester agent — **[references/db-check.md](references/db-check.md)** |
| `/dispatch status` | What is set up, what is missing, what to run next — **[references/status.md](references/status.md)** |

## Starting something new?

A **heavy** new project — built from scratch, an empty/near-empty repo, or a new large
subsystem (multiple modules, many files, its own data model) — needs information gathered
*before* a brief can be written, one question at a time. A **light** new thing (one script, one
small file) does not need this.

Recognise it under a plain `/dispatch <task>` too, not only `/dispatch new` — if the task
matches the signals, run the intake first, then continue below. Read
**[references/new-project.md](references/new-project.md)**: the trigger signals, the
one-question-at-a-time order, and the `PROJECT_BRIEF.md` confirm step that feeds bootstrap.

## First: is this repo bootstrapped?

```bash
grep -l '<!-- dispatch:map v1 -->' AGENTS.md 2>/dev/null; ls .claude/agents/ 2>/dev/null
```

Three outcomes:

- **No `AGENTS.md`** — say so and offer `bootstrap` before anything else. A dispatch without a
  map is exactly the situation this skill exists to prevent. Do not proceed to a task dispatch
  with no map unless the user tells you to. One exemption: the **scaffold** dispatch of a new
  project runs before any map exists, from `PROJECT_BRIEF.md` (new-project.md).
- **`AGENTS.md` exists but has no marker** — it was written for another tool (Codex, Cursor, a
  human). Treat it as **not bootstrapped**: it may lack the sections sub-agents need. Offer
  `bootstrap`, which appends a dispatch section and never overwrites what is there.
- **Marker present** — bootstrapped. Run the cycle.

Read **[references/bootstrap.md](references/bootstrap.md)** when running bootstrap.

## Before you dispatch anything

**Is this worth a dispatch?** A ≤5-line edit to lines you already hold in context is not.
Read **[references/when-not-to-dispatch.md](references/when-not-to-dispatch.md)** — it is
short, and the rule is narrow.

**Record the baseline.** Acceptance judges *the sub-agent's* diff, so you need a point to
diff from that excludes whatever was already uncommitted. Each command below **prints** a sha:
write it into your plan as `BASE: <sha>` and paste that literal into later commands. A shell
variable does not survive between tool calls in every runtime.

```bash
git status --porcelain           # empty = clean. Preferred: commit or stash first.
git rev-parse HEAD               # clean tree: the printed sha is BASE
```

Dirty tree the user does not want to commit yet — **the snapshot command**: a throwaway index,
so the real index is never touched; the printed tree sha is BASE:

```bash
( export GIT_INDEX_FILE="$(git rev-parse --path-format=absolute --git-path dispatch-snap-index)"; git read-tree HEAD && git add -A >/dev/null && git write-tree; rm -f "$GIT_INDEX_FILE" )
```

No commit yet (empty repo) → new-project.md makes a root commit first. Details, older git, and
worktree isolation in **[references/acceptance.md](references/acceptance.md)**.

## At most 2 sub-agents at once

**Never more than 2 sub-agents running concurrently** — workers, scouts, the critic, and the
db-tester all count toward the same cap. A third job waits in a queue until one of the two
finishes.

A plan that needs more than 2 running at once is a decision, not a default: **ask the user
first** — name the extra job, why it cannot wait, and the cost — and proceed past 2 only on an
explicit yes, for that plan only. Detail and an example ask:
**[references/routing.md](references/routing.md#concurrency-cap)**.

## The dispatch cycle

### 1. PLAN
Decide what the change is, from `AGENTS.md` + `CLAUDE.md` only. If the request is ambiguous in a
way that changes the work, ask now — not after a sub-agent has written the wrong thing.

### 2. LOCATE
Resolve exact paths. Paths only, no contents:

```bash
grep -rl "<symbol or selector>" --include="*.<ext>" . | head
```

If the Surfaces table in `AGENTS.md` names the owning files, skip the grep entirely.
If you cannot narrow to a small set of files, dispatch a **read-only scout** to find them and
return paths — not a worker who both searches and edits.

### 3. BRIEF
Assemble the spec. Read **[references/prompt-spec.md](references/prompt-spec.md)** for the
template and the worked example. Every brief carries, at minimum:

- **Task** — one sentence: the observable change
- **Inputs** — exact file paths, the current wrong behaviour; for UI work, the **Page URL(s)**
- **Audience** — what consumes this code and what contract it must keep
- **Format** — the conventions of the file being edited
- **Out of scope** — explicit "do NOT touch X, do NOT refactor Y, do NOT survey the repo"
- **Knowledge** — "read `AGENTS.md` first, then only the files named above"
- **Done means** — the checkable list step 5 judges against; no list, no dispatch
- **Report** — per phase, **≤ 40 lines**. You read the diff yourself; the report is a map to it.
- **Footer** — the phase line, verbatim, always (see below)

Pick the agent with **[references/routing.md](references/routing.md)**. If the agent name is
not recognised by the runtime (templates installed this session are not hot-loaded), routing
says what to do instead.

**Set the model, every dispatch.** The Agent tool's `model` parameter overrides the agent
file's frontmatter — use it. `sonnet` for light work (copy, docs, config values, renames,
mechanical edits, read-only scouting, DB checks, the security critic); `opus` for design work
or core-level implementation (architecture, new subsystems, business logic, cross-file
changes). Unsure on a design/core task → choose `opus` and say why. State the choice and a
one-line reason in your plan. Table: **[references/routing.md](references/routing.md#model-selection)**.

**Effort is `medium` for every sub-agent.** It is set once, as `effort: medium` in each agent
file's frontmatter — the Agent tool has no per-call effort. Never raise it on your own; only the
user changes it. Details: **[references/routing.md](references/routing.md#effort)**.

**Designing or changing UI?** Responsive behaviour is always in scope and always in "Done
means" — at minimum mobile ~375px, tablet ~768px, desktop ~1280px+, or the project's own
breakpoints from `DESIGN.md` / `AGENTS.md`; **Format** cites `DESIGN.md`'s components by name.
Before writing the brief, ask the user how it should look on smaller screens — **one question
per message**, wait, then the next; never batch. Full procedure:
**[references/responsive.md](references/responsive.md)**.

### 4. WORK
The sub-agent implements and reports back. You wait. You do not read along.

A sub-agent that errors, times out, stops to ask a question, or reports an out-of-scope need is
not a rejection. Handle each per **[references/failures.md](references/failures.md)** — a
needed dependency becomes a `deps` dispatch first.

### 5. ACCEPT
Yours alone. Read **[references/acceptance.md](references/acceptance.md)**.

```bash
git status --porcelain                                     # every changed AND created file
<the snapshot command, from "Record the baseline">          # prints AFTER; record it too
git diff --stat <BASE> <AFTER>
git diff <BASE> <AFTER>                                    # large? --stat first, then per file
```

Judge the diff against the spec *you wrote in step 3*. That is why the spec must be explicit
up front — a vague brief cannot be checked. `git diff <BASE>` alone misses files the sub-agent
created; diffing two snapshots includes them, whatever their names, without staging anything.

- **Accept** → say what landed, move to verify.
- **Reject** → re-dispatch with what was wrong and why. **Do not hand-fix it yourself** — that
  is how your context fills with the file contents you were avoiding. The one narrow exception
  (a one-token fix fully visible in the diff) is defined in when-not-to-dispatch.md.
- Two rejections on the same brief means the brief is wrong, not the agent. Rewrite the spec.
- **A third failure — after the rewritten brief — stops the loop.** Escalate to the user with a
  summary. Never dispatch a fourth time on your own.

### 6. VERIFY
Read **[references/verifier.md](references/verifier.md)**. Separate question from step 5:
step 5 asks *"does it do the job?"*, verify asks *"is it safe?"*

## The mandatory footer

Every dispatch prompt ends with this line, character for character, as the last line:

```
[ task list broken down into phases, each phase as a vertical slice, numbered ]
```

No exceptions, no paraphrase, no reordering. It goes last so it is the final instruction the
sub-agent reads.

**It is an instruction to the sub-agent, not a placeholder for you to fill.** The sub-agent
acts on it: before its first edit it writes a numbered list of phases, then works through them,
then reports per phase. A vertical slice means each phase is independently checkable end to
end — not "phase 1: write the CSS, phase 2: test the CSS", but "phase 1: mobile layout correct
and verified, phase 2: tablet layout correct and verified".

This does not contradict "the sub-agent never decides *what*". The **what** is the brief. The
phases are the **how** — an ordering of the briefed work, inside its scope. A phase that needs
an unbriefed file is not a phase; it is a stop-and-report. For read-only briefs (critic,
db-tester) a slice is one check or risk area, taken end to end: evidence, then judgement.

## Non-negotiables

- Never dispatch a job whose brief you could not check the result of.
- Never run more than 2 sub-agents at once, any kind. A plan needing more asks the user first.
- Every sub-agent runs at `effort: medium` unless the user says otherwise.
- Never let a sub-agent both decide *what* to do and *whether it worked*. Those are your calls.
- The security critic **only criticises** — it never edits. Enforced by instruction, checked by
  you: `git status --porcelain` after it returns must match before.
- Findings are advisory. Never auto-fix, never auto-commit, never push.
- If you skip acceptance because the change "looks fine", you have not used this skill.
