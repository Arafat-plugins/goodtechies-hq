# Routing — picking the agent

## Discover before you assume

Agents are per-repo. Never hardcode a name; look:

```bash
find .claude/agents -maxdepth 1 -name '*.md' -exec head -4 {} + 2>/dev/null
```

The `description` line in each agent's frontmatter says what it is for. Match the task to a
description. If the repo has purpose-built agents, **prefer them** — they carry conventions a
generic agent does not.

If the repo has none, `/dispatch bootstrap` installs the generic set below.

## Routing table

| The task is about | Agent | Why |
| --- | --- | --- |
| Server logic, APIs, data access, business rules | `dispatch-implementer` | Edits code, runs tests |
| Layout, CSS, responsive, anything judged by looking | `dispatch-frontend` | Needs to render and measure |
| "Which file does X?", "where is Y defined?" | a read-only scout (below) | Returns paths, edits nothing |
| Schema, queries, migrations, data integrity | `dispatch-db-tester` | Read-only DB inspection |
| "Is this change safe?" | `dispatch-security-critic` | Critic only, never edits |
| Reviewing a diff before commit | repo's own review agent, else `dispatch-security-critic` | |

**The scout.** In Claude Code, the built-in `Explore` agent. Anywhere else: any sub-agent
whose tools are `Read, Grep, Glob` only, or a general-purpose sub-agent briefed "return paths
and line numbers only; edit nothing; output ≤ 20 lines". The verify chain's "scout stage" is
**not** this — that one is you, reusing the diff you already read at acceptance (verifier.md).

## When the agent name is not recognised

Templates copied into `.claude/agents/` during this session are usually not loaded until the
session restarts or `/agents` reloads them. If the runtime rejects `dispatch-implementer` (or
any of the four), do not wait and do not skip the dispatch:

1. Use a general-purpose sub-agent.
2. Paste the template's body — everything below its frontmatter — at the top of the brief,
   under a `## Role` heading. Path: `.claude/agents/<name>.md` (installed) or
   `<SKILL_DIR>/agents/<name>.md` (the path bootstrap.md, Step 0, recorded in your plan).
3. State the tool restriction in words inside the brief ("you have no browser; you are
   read-only; do not write files") — a general-purpose agent has every tool.
4. The fallback inherits the session's effort — it cannot be set to `medium` per call. Note
   it in the plan (see [Effort](#effort)).
5. Tell the user a restart makes the named agents available.

## Rules

**One job per dispatch.** "Fix the CSS and also add the REST field" is two briefs to two agents.
A single agent given two jobs produces a diff you cannot accept or reject cleanly — half of it
is right.

**Read-only work goes to a read-only agent.** If you need to know *where* something is, dispatch
a scout that returns paths. Do not give edit tools to a question.

**Never dispatch the same brief twice hoping for a different result.** A second failure means
the brief is wrong. Rewrite the spec, then dispatch. A third failure stops the loop
(failures.md).

**Sequence, do not parallelise, when work touches the same files.** Two agents editing one file
produce a diff neither of them intended. Parallel dispatch is fine only for genuinely disjoint
file sets — say so explicitly in each brief, and give each sub-agent its own worktree
(`isolation: "worktree"` on the Agent tool in Claude Code) so the diffs stay separable
(acceptance.md). Without isolation, two parallel diffs are one diff. Parallel also means the
[concurrency cap](#concurrency-cap) below: at most 2 at once, whatever the file split.

## Concurrency cap

**At most 2 sub-agents running at once, counting every kind** — workers, scouts, the critic,
the db-tester. Track how many are in flight; a third one queues behind them, it does not run
alongside them.

A plan that fans out beyond 2 at once (several disjoint surfaces of one big project, say) is
not a default — ask the user before dispatching the third:

```
This needs 3 sub-agents in parallel: <job A>, <job B>, <job C> — disjoint files, listed above.
Capped at 2, job C waits ~<estimate> for one to finish. Run 3 concurrent instead, or keep the
queue?
```

Proceed past 2 only on an explicit yes, and only for that plan — the cap applies again on the
next dispatch.

**Read-only agents need an idle tree.** The critic and the db-tester are checked by comparing
`git status --porcelain` before and after they run (acceptance.md, "After the critic"). A worker
editing the same working tree meanwhile changes that output, and the check can no longer tell
whose change it was. So the second slot may hold one of them only while **no other agent is
editing the same tree** — a worker in its own worktree does not count; queue the critic behind
a worker that shares your tree.

## Model selection

The **main session** sets the model per dispatch, on the Agent tool call — its `model`
parameter overrides the agent file's frontmatter. Set it explicitly, every time, and say the
choice plus a one-line reason in your plan.

| Task kind | Model | Why |
| --- | --- | --- |
| Light — text/copy, docs, renames, config values, small mechanical edits, read-only scouting, DB checks, the security critic | `sonnet` | Simple, bounded work; spending `opus` (or `fable`, where the runtime offers it) here is waste. |
| Heavy — design work: UI/visual design, layout systems | `opus` | Judged by looking; the stronger model earns its cost on taste calls. |
| Heavy — core-level implementation: architecture, new subsystems, business logic, cross-file changes, anything the brief calls core | `opus` | Getting the shape wrong costs more than the extra spend to get it right. |

Unsure whether a task is light or heavy? If it touches design or core-level implementation,
choose `opus` and say why in the plan.

**Template defaults.** `dispatch-implementer` and `dispatch-frontend` ship with `model: opus`
in frontmatter — they exist for core and design work. The main session overrides *down* to
`sonnet` on the Agent tool call for anything light (a copy fix, a renamed field, a config
value). `dispatch-db-tester` and `dispatch-security-critic` stay `model: sonnet` — their job is
bounded evidence-gathering, not a judgement call. `haiku` is a legitimate further downgrade
from `sonnet` for the critic on a trivial diff (copy, styles, a one-file change with no input
handling) — **set it per call**, on the Agent tool's `model`, like every other choice here.
Editing the installed file's `model:` line does nothing: the per-call `model` always wins.

Nothing switches mid-task. The main session stays whatever the user is running.

## Effort

**Every sub-agent runs at `effort: medium`.** Model picks *how capable*; effort picks *how long
it thinks*. Medium is enough for a briefed job — the brief already did the hard thinking — and
keeps each dispatch fast and cheap, whether the model is `sonnet` or `opus`.

- **Where it is set:** the `effort:` line in each agent file's frontmatter. All four templates
  ship with `effort: medium`. The Agent tool has **no per-call effort parameter**, so there is
  nothing to set on the dispatch itself — only check the installed file still says `medium`.
- **Repo's own agents:** if a purpose-built agent has no `effort:` line it inherits the
  session's effort. Tell the user once, and suggest adding `effort: medium` to it.
- **Fallback sub-agent** (a general-purpose agent carrying a template body — see above): it
  inherits the session's effort and nothing in the call can change that. Say so in the plan.
- **Changing it** — `low`, `high`, `xhigh`, `max` — is the user's decision, per repo, in the
  installed copies. Never raise it yourself to rescue a failing brief; a failing brief is
  rewritten (failures.md), not thought about harder.
