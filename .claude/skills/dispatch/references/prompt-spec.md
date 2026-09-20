# The Spec — how a dispatch brief is assembled

A brief is an engineering recipe, not a request. The sub-agent has no memory of the
conversation, no view of your plan, and no reason to guess correctly. Everything it needs is
in the brief or it does not exist.

## Template

```
## Task
<one sentence: the observable change you want>

## Inputs
Files you may edit:
  - <exact/path/one>
  - <exact/path/two>
Current wrong behaviour:
  <what happens today, concretely — a measurement, not an adjective>
Target behaviour:
  <what must be true when you are done, checkable>
Page URL(s):
  <UI briefs only: the dev-server URL from AGENTS.md → Verification capabilities + the path of
  each page to measure, e.g. http://localhost:5173/catalog; not UI → delete this line>

## Audience
<what consumes this code and what contract it must keep>

## Format
<conventions of the file being edited: indent, naming, escaping, which layer owns what>

## Out of scope — do NOT
- do NOT touch <files/areas>
- do NOT refactor <thing> as a side effect
- do NOT survey the repository; read AGENTS.md, then only the files named above
- do NOT commit, push, or change git state
- do NOT add dependencies

## Knowledge
Read AGENTS.md at the repo root first. It is the map. Do not go exploring past it.
<+ any specific reference file that matters for this task>

## Done means
- [ ] <checkable condition>
- [ ] <checkable condition>
- [ ] you report per phase: what changed and why, file by file, verified / not verified

## Report
At most 40 lines. The caller reads the diff itself; the report is a map to it, not a copy.

[ task list broken down into phases, each phase as a vertical slice, numbered ]
```

## The four rules

**1. Inputs are paths, not descriptions.** "The product card styles" sends the agent
searching. `assets/css/product-card.css` does not. If you cannot name the file, you have not
finished step 2 of the cycle — run the grep, or dispatch a read-only scout to return paths.

**2. Wrong behaviour is measured, not adjectived.** "The layout is broken on mobile" is
unactionable. "Below 620px the card grid keeps 3 columns and overflows the viewport by ~180px"
can be fixed and can be checked.

**3. Out of scope is where briefs earn their keep.** An unconstrained agent refactors, renames,
reformats, and adds a dependency. Every one of those is a diff you now have to review. Name the
adjacent things it must leave alone — especially the ones it will be tempted by.

**4. "Done means" is the acceptance test, written before the work.** You will judge the diff
against this list in step 5. If you cannot write a checkable list, the task is not specified
well enough to dispatch yet.

**And one cap: the report is ≤ 40 lines.** Say it in every brief. A sub-agent left to itself
returns the files it touched, quoted. You are going to read the diff anyway; a long report is
the same content twice, in the context you are protecting.

## Worked example

Vague ask: *"make this CSS perfect for all devices"*

```
## Task
Make the product card grid lay out correctly from 320px to 1440px.

## Inputs
Files you may edit:
  - assets/css/catalog-discovery.css
Current wrong behaviour:
  The grid track count is set from an inline --columns custom property, so below 620px it
  stays at 3 columns and overflows the viewport by roughly 180px on a 375px screen.
Target behaviour:
  1 column below 620px, 2 up to 900px, 3 above. No horizontal overflow at any width in range.
Page URL(s):
  http://marketkit.local/shop/

## Audience
Storefront catalog page. Themes consume these class names as a public contract — class names
and DOM structure must not change, only the styles.

## Format
Existing breakpoints in this file are 900px and 620px. Match them; do not invent new ones.
Longhand properties, not shorthands, where the file already uses longhands.

## Out of scope — do NOT
- do NOT edit any other stylesheet
- do NOT edit PHP, templates, or markup
- do NOT rename or add class names
- do NOT survey the repository; read AGENTS.md, then only the file named above
- do NOT commit or push

## Knowledge
Read AGENTS.md at the repo root first. See its "Surfaces" table.

## Done means
- [ ] no horizontal overflow at 320, 375, 620, 900, 1024, 1440
- [ ] column counts are 1 / 2 / 3 at the breakpoints above
- [ ] no class name or DOM change
- [ ] you report each rule you changed and why, per phase, and how each width was verified

## Report
At most 40 lines. Say per width whether you rendered it or read the rules.

[ task list broken down into phases, each phase as a vertical slice, numbered ]
```

Note what happened: the vague ask became a measurable one **before** dispatch. That
conversion is the planner's job, and it is most of the value of this skill.

## The footer

The last line is always, verbatim:

```
[ task list broken down into phases, each phase as a vertical slice, numbered ]
```

It makes the agent commit to an ordered plan before editing, and each phase to a slice you can
check on its own. Last position matters — it is the final instruction read.

**What the sub-agent does with it.** It is an instruction, not a template field. Before its
first edit, the sub-agent writes a numbered phase list; it works through the phases in order;
its report is organised by those phases, each ending `verified: <how>` or `not verified:
<why>`. Acceptance checks for that shape (acceptance.md, "Then the phases").

**Phases are the HOW, not the WHAT.** The brief decides what changes; the sub-agent never
does. Phases are an ordering of the briefed work, inside its Inputs and Out-of-scope. A phase
that would need a file the brief did not name is not a phase — it is a stop-and-report
(failures.md, case 4).

**A vertical slice** is a piece of the target behaviour that is true and checkable on its own
when the phase ends: "phase 1: 1 column below 620px, verified at 320 and 375", not "phase 1:
edit the grid rules". For a read-only brief — critic, db-tester, scout — a slice is one risk
area or one check, taken end to end: evidence, then judgement.

## Design and UI briefs

Any brief that designs or changes UI carries responsive Target behaviour, stated per width —
see **[responsive.md](responsive.md)** for the one-question-at-a-time flow that produces it and
the default acceptance widths. "Done means" for such a brief always includes the widths
checked, at minimum mobile/tablet/desktop or the project's own breakpoints — not just the width
that prompted the task. **Inputs** carries the **Page URL(s)** line — the full URL of every page
the widths are measured on (dev-server base from *Verification capabilities* plus the path).
The frontend agent and your own acceptance both measure exactly those; without it neither
knows which page to load.

When the repo has a `DESIGN.md`, **Format** cites the relevant component by its name there —
"a stat card per DESIGN.md → Components; tokens and breakpoints from DESIGN.md only" — instead
of re-describing spacing, colour or type in the brief. The frontend agent reads `DESIGN.md`
before any UI work, and a brief cannot override what it defines; a design change that needs a
new token is a change to `DESIGN.md`, briefed as such. "Done means" names the widths in a form
`dispatch-measure.mjs` can check (see responsive.md).
