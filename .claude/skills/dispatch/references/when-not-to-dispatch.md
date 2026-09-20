# When not to dispatch

A dispatch costs a brief, a sub-agent run, and an acceptance pass. For a trivial edit that is
more than the edit. The rule is narrow on purpose; the default stays **dispatch**.

## Do it directly when all of these hold

- **≤ ~5 changed lines**, in one file.
- **You already hold the exact lines** — from a diff you accepted, from the user pasting them,
  from `AGENTS.md`, or from a `grep -n` hit. Read only those lines (Claude Code: `Read` with
  `offset`/`limit` around the `grep -n` line number — its `Edit` refuses a file not yet read
  this session), then make an exact-match replace. Elsewhere: `sed -n '<from>,<to>p' <file>`,
  then the runtime's edit tool. Never the whole file.
- **No judgement about surrounding code** is needed: a typo, a wrong constant, a one-line
  config value, a version string, a label.

Still take the baseline, still run the repo's lint/test from `AGENTS.md`, still report
`Verified` / `Not verified`. Skipping the dispatch does not skip acceptance.

If you find yourself reading the file to work out *where* the five lines go, stop — that is a
dispatch.

**Dependencies.** A version bump of a dependency already present — one line in a manifest or
lockfile you already hold, applied with the package manager's own command — may be done
directly, followed by install, test and audit as dependencies.md's "Done means" lists.
Everything else — a new package, a removal, a bump that needs code changes or pulls in new
packages — is a deps dispatch (**[dependencies.md](dependencies.md)**).

## The hand-fix exception during rejection

Default on rejection is re-dispatch. The one exception:

- the fix is **one token or one line**, and
- it is **entirely visible in the diff you already read** — a misspelt string, an off-by-one
  literal, a wrong operator in a hunk the sub-agent wrote, and
- nothing outside the diff has to be consulted to be sure.

Then make the edit, say in the report that you did, and count the dispatch as accepted with a
correction. Anything that needs context outside the diff is a rejection, re-dispatched with
the hunk quoted.

## Never do directly

- edits that touch generated or vendored paths (`AGENTS.md` names them)
- anything in a file you would have to read first
- anything where "small" is a guess about a file you have not seen
