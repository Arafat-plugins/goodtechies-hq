# Failures — when the cycle does not converge

A sub-agent can come back five ways that are not "done". Each has one correct response.
Count failures per *task*, not per brief — a rewrite does not reset the count.

## 1. Rejected at acceptance

| Failure | Do |
| --- | --- |
| 1st | Re-dispatch: original brief + what failed, quoted from the diff + what correct looks like |
| 2nd | The brief is wrong. Rewrite the spec from [prompt-spec.md](prompt-spec.md); failures become "Out of scope" lines. Dispatch once. |
| 3rd | **Stop.** Escalate (below). No fourth dispatch without the user saying so. |

**Escalation** is a report, not an apology:

```
Stopped after 3 attempts: <task in one line>
Attempt 1: <what came back, why rejected — one line>
Attempt 2: <same>
Attempt 3 (rewritten brief): <same>
Current state: <files changed per git status --porcelain, left in place / reverted to BASE>
Likely cause: <brief unspecifiable / target file is not the owner / test cannot run here / ...>
Options: <split the task> / <you look at <path> directly> / <different approach>
```

Leave the working tree as it is unless the user asked for reverts; say which it is. To revert
only this dispatch, with the BASE and AFTER shas from your plan (acceptance.md):

```bash
git -c core.quotePath=false diff --name-only --diff-filter=A <BASE> <AFTER>   # files it created: delete these
git restore --source=<BASE> --worktree -- <edited or deleted files>          # the rest, back to BASE
```

`git restore --worktree` leaves the index alone; `git checkout <BASE> -- <files>` would also
stage the reverted content. (Git older than 2.23: `git checkout <BASE> -- <files>`, then
`git reset -q -- <files>`.) In `git status --porcelain` created files show as `??` — or as
` A` if anything marked them intent-to-add — so list them from the diff, not from status.

## 2. Sub-agent error or timeout

The runtime reports an error, or nothing comes back. First find out what landed:

```bash
git status --porcelain
<the snapshot command, acceptance.md>     # prints AFTER
git diff --stat <BASE> <AFTER>
```

- **Nothing changed** — re-dispatch the same brief once. Counts as a failure only if it errors
  again; then treat the second error as a rejection and rewrite (the brief may be asking for
  something the environment cannot do: a test that needs a DB, a build that needs a network).
- **Partial edits** — this is an attempt. Either revert to BASE (above) and re-dispatch, or
  re-dispatch with a line under Inputs: "A previous attempt left partial edits in `<files>`;
  finish them or revert them, do not start over." Never accept partial work as-is.

## 3. Sub-agent stopped to ask a question

It cannot reach the user; the question comes to you.

- Answer it yourself if `AGENTS.md`, your plan, or the diff so far answers it.
- Otherwise ask the user, in one line, and wait.
- Re-dispatch with the answer added to the brief under **Inputs**. Not a failure; do not
  count it. If the same agent asks twice on one brief, the brief is under-specified — rewrite.

## 4. "Stopped: <file outside Inputs> must change"

This is the agent doing the right thing. Decide, do not overrule:

- The file belongs in the task → new brief with the file added to Inputs and a line saying
  why. Same agent.
- It is a separate concern → two briefs, sequenced, second one dispatched after the first is
  accepted.
- The agent is wrong and the change fits in the briefed files → re-dispatch with the reason
  under Out of scope: "do NOT edit `<file>`; the change belongs in `<briefed file>` because …".
- **It is a dependency** — the agent needs a package the manifest does not have. Not a file to
  add to Inputs: pause the task, ask the user (package, constraint, dev or runtime, why), and on
  a yes run a deps dispatch per **[dependencies.md](dependencies.md)** — its own brief,
  acceptance and narrow verify. Then re-dispatch the original brief with the package present.
  Not a failure; do not count it. On a no, the task is re-planned without it.

Never reply "edit it anyway" without adding the file to Inputs — then it is unbriefed scope
creep you cannot check.

## 5. Oversize diff

More than ~300 changed lines or files outside the brief. Do not read it all. Reject with
`git diff --stat` quoted: "brief named N files; diff touched M". Counts as a failure. If the
task genuinely needs that much change, split it before the next dispatch.
