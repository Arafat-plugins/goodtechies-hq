# Brief 002 — board-card-redesign

model: Opus 5.5 (claude-opus-5-5) · effort: high (session default; general-purpose fallback)

## Role
You do frontend work on one briefed surface.

This template runs on **Opus 5.5** (`claude-opus-5-5`), as every dispatch sub-agent does —
a one-value CSS tweak or a whole layout system alike. The model is fixed; the brief varies.

The brief ends with `[ task list broken down into phases, each phase as a vertical slice, numbered ]`.
Before any edit, write a numbered phase list — one phase per width range or surface, each
"correct and verified" on its own. Work through them in order; report by them.

## Start here, every time

Read `AGENTS.md`, then `DESIGN.md` if it exists, then only the briefed files.

`AGENTS.md` maps which stylesheet owns which surface and says how you can render.
`DESIGN.md` is the design source: tokens, breakpoints, component patterns, references, and
what this project never does. If `AGENTS.md` → *Verification capabilities* names a design
skill, read its `SKILL.md` at the path given too.

**A token, breakpoint or component pattern that `DESIGN.md` defines is used as defined — a
brief cannot override it, only a change to `DESIGN.md` can.** If the brief asks for something
`DESIGN.md` contradicts, stop and report the conflict; do not pick one.
A brief that settled such a conflict in favour of a mock lists `DESIGN.md` under Inputs and says
which tokens to add — add them there first, then use them by name.

## Reference images

If Inputs lists **Reference image(s)**, the image is the spec — not the brief's description of
it. Open each one with `Read` before any edit.

1. **Phase 1 is a reference spec table**, written from the image's own pixels (÷2 for a 2x
   export): layout grid, each block top to bottom, spacing between blocks, type size and weight
   per text role, colours, radii, borders, shadows, icons. Build from the table.
2. **Compare, at most 3 passes**, at the width each reference names:
   `node .claude/dispatch/dispatch-measure.mjs <page url> <width> --compare <ref> --shot .claude/dispatch/shots`
   — then `Read` the `-compare.png` it writes (reference | render | diff, red = differs). Name
   each red region, fix it, re-run. Stop at 3 passes, or when what stays red is content (real
   data, real photos, different text length).
3. **Report** the `% differ` per pass (`38% → 12% → 4%`), the composite path, and every red
   region left with its reason. `Fidelity: exact` means spacing, size, weight, colour, radius
   and order all match; `close` means layout and hierarchy match and tokens win.

No renderer → write the spec table and build from it; report every comparison as
`not verified: no renderer`. Text visible in the image is used only when the brief's
**Verbatim** block carries it — otherwise ask; never retype copy from an image by guesswork.

## The rule that matters most here

**Do not invent breakpoints.** `DESIGN.md`, `AGENTS.md` and the file you are editing already
establish them. A new arbitrary width creates a range where two sets of rules disagree, and the
bug shows up somewhere you are not looking. Match what exists; if the existing set genuinely
cannot express the target, stop and report that rather than adding one.

## Scope

Edit only the files under **Inputs**. In particular, unless the brief explicitly says otherwise:

- do not change class names, IDs, or DOM structure — themes and tests consume them as a contract
- do not move a style into a different stylesheet
- do not touch markup or templates to make a style easier

If the fix genuinely requires a markup change, stop and report. That is a different brief.

## Specificity

Prefer the lowest specificity that works. Reaching for `!important` or a long descendant chain
usually means you are fighting a rule you have not found yet — find it. An override stack is a
bug that surfaces on the next change, not a fix.

## Verify by measuring

Check every width in the brief's "Done means", not just the one that was reported broken. A fix
at 375px that breaks 768px is a net loss. If the brief is a design/UI task and does not list
widths, verify at minimum mobile ~375px, tablet ~768px, desktop ~1280px+ (or the project's own
breakpoints from `DESIGN.md` / `AGENTS.md`) — the responsive check is part of the job, not an
extra.

Measure rather than eyeball. Your default `tools:` line has **no browser** — MCP browser
tools (`mcp__playwright__*`, `mcp__puppeteer__*`) only exist for you if `/dispatch setup`
added them to that line by name; a browser recorded `(main session only)` is not yours.
`AGENTS.md` → *Verification capabilities* says which. The pages to measure are the brief's **Page URL(s)** line under Inputs; no such line
on a UI brief → stop and ask for it rather than guessing a path. Check what you have, then in
order of preference:

1. **Browser tools present** — resize to each width, load the page, evaluate
   `document.documentElement.scrollWidth > document.documentElement.clientWidth` and the
   computed values the brief names.
2. **No browser tools, `.claude/dispatch/dispatch-measure.mjs` present** — run it from the repo
   root; never write your own Playwright script:
   ```bash
   node .claude/dispatch/dispatch-measure.mjs <page url> 320 375 768 1280 [--select <css> --prop <property>]
   ```
   A `renderer: …` line and one line per width come back; quote them in your report — the
   renderer line is the `<tool>` you rendered with. Exit 2 means the dev server is down or the
   page redirected (the line names where), exit 3 means no renderer — do not start servers,
   log in, or install anything; fall through to 3 and quote the line.
3. **Neither** — say, per width, `verified by reading the rules, not by rendering`. Do not
   write "verified" without that qualifier; the caller reports it as *Not verified* and
   measures it themselves.

Watch for the two that hide: horizontal overflow (`scrollWidth > clientWidth`) and collapsed or
zero-height containers.

## Verbatim text

Anything in the brief's **Verbatim** block is final: insert it byte for byte — no rewording,
no "fixing" grammar, case, punctuation or typos, no translating, no shortening to fit. If it
does not fit the design, stop and report; do not edit it.

## Report

**At most 40 lines**, organised by the phases you planned. Per rule changed: what it was,
what it is, and which width it fixes. Then, per width: `rendered with <tool>` or `read, not
rendered`. Name any width you could not check.

## Never

- commit, push, or change git state
- edit build output (`AGENTS.md` names the generated directories); edit the source and rebuild
- leave dead rules or commented-out CSS behind
- introduce a colour or breakpoint that `DESIGN.md` does not define, or — when `DESIGN.md`
  lists a spacing scale — a margin, padding or gap off that scale. Routine values (`border:
  1px`, a `line-height`) are fine. No `DESIGN.md` → the brief's **Format** is the rule

## Task
Redesign the Board card (`TaskBoardCard.vue`), which is user point **3**. Remove the project line and the tag chips. Show assignees as avatars only. Replace the calendar date with a one-unit relative countdown, driven by a new tested helper and one shared minute ticker. Replace the priority arrow and text with a coloured flag.

## User's words

```text
1. in this image here i am in tasks tab and list view . only list view show site popup .but i want to use this popup system in board view also 
2. in  the second image i have marked the. my task due today over due . these will be in the tasks tab and will add as dropdown filter system
3. in the third image here. is card . from that card have to remove top project name like in here buffalo modular seo wroted here , alseo the employe name will be shorter i mean only inside the name logo not out side. in the date here five days lift i mean the date not appear only will how much /hours/minute/days/weeks/monthe left that will show . will show one thing from all of them. and the priority will be flaged as colorful depand on its values.  SEO development those tags will remove from the card
4. 4th image .here showing every 20 second its refresh. this text have to remove from here. and after every 20 second later its relload like flashing so its like odd . client want it will refresh like only when anything happend . like someone message .or some one asigned to task to him/her. and the refresh will not seen like flash .
5. 5th image . here in the bottom line here showing drag inside a lane to set it order . here no need this instructions remove this .
6. in the 6th image here you can see that i have marked with border . client want to scroll horizontal via mouse by presing mouse left button and moved left right . its like clickup system . so client no need to tuch the scrollbar all that time.
7. in the seven image here . the user dont want serchbar here. also the title of the page and descriptions should be remove . and others . overdue, show archived , mnage tags aadd filter those will be in the right side.
```

(All seven points are quoted for context. This brief implements only the points named in **Task**.)


## Inputs
You work in an **isolated git worktree**: `/home/claude/ghq-d2` (branch `d2-card`, at the same commit as the main checkout). `vendor` and `node_modules` are symlinks into the main checkout, so never modify them. Work, build and test only here.

Files you may edit or create. These were located by `git grep`; confirm each, and name any other file you touched and why:
- `resources/js/Components/Tasks/TaskBoardCard.vue` (the card)
- new: a pure countdown helper, e.g. `resources/js/lib/dueCountdown.ts`. It must be **pure TypeScript with no imports** (no `@/` alias, no Vue), so Node can run it directly.
- new: one shared minute ticker (e.g. `resources/js/lib/minuteTicker.ts`) and a tiny `DueCountdown.vue` (or similar) that is the **only** thing that reads it
- new: a priority flag component, e.g. `resources/js/Components/Tasks/TaskPriorityFlag.vue`
- `resources/css/app.css` and `DESIGN.md`: only to add the priority tokens (light **and** dark) and document them, with their measured contrast (DESIGN.md is generated from app.css; keep both in step, in DESIGN.md's own format)
- `app/Http/Middleware/HandleInertiaRequests.php`: only if the app time zone is not already reachable by the browser. Check first; share the smallest thing that works (e.g. `app.timezone`).
- `app/Http/Resources/TaskResource.php`: only if the board payload lacks something the card now needs. Removing fields is out of scope.
- new tests: `tests/js/dueCountdown.test.ts` (run with Node's built-in runner: `node --experimental-strip-types --test tests/js/`; Node is 22.22), a shared case file `tests/fixtures/due-countdown-cases.json`, and a Pest test `tests/Feature/Tasks/DueCountdownAgreementTest.php` that reads the **same** JSON
- `package.json`: you may add one `"scripts"` entry (e.g. `"test:js"`) and nothing else. No dependency, and no lockfile change.
- `AGENTS.md` → Commands table: add one row for the JS test command

What the code does today (measured by the main session):
- `due_date` is a PostgreSQL `date` column (no time). `TaskResource` sends `due_date` as `Y-m-d` and `is_overdue` as a boolean.
- The server rule (`Task::scopeOverdue` / `isOverdue`, `app/Models/Task.php:~432`): overdue ⇔ `due_date < today` and the status is open (not completed/cancelled). `today` is in the app time zone: `config('app.timezone')`, default `Asia/Dhaka`, decisions 7-14 / 7-31.
- The card today (image 03) shows a project name line, the assignee name next to the avatar, a calendar date ("5 days left" style), a priority arrow + word, and tag chips (SEO, Development, …).

Target behaviour. The user approved these; do not re-decide them.
1. **Project line: remove.** Keep the `⋯` menu, floated top-right so no empty row is left.
2. **Assignees: avatars only**, in the card footer. Show initials in a circle and no name text beside it. The full name goes in the tooltip and in the `aria-label`. With several assignees, overlap the avatars, show at most 3, then `+N`. Unassigned: an empty dashed circle, with an accessible name you invent (list it). Keep the "is on leave until …" banner unchanged.
3. **Countdown label** instead of the date. The deadline of a date-only due date is the **end of that day in the app time zone**, i.e. 00:00 of the next day there, not the browser's zone. Show exactly one unit, the largest whole unit that fits, floored, never `0`, singular for 1:

   | Time left | Label | Time past due | Label |
   | --- | --- | --- | --- |
   | under 1 minute | `less than a minute left` | under 1 minute | `just overdue` |
   | 1–59 minutes | `45 minutes left` | 1–59 minutes | `12 minutes overdue` |
   | 1–23 hours | `6 hours left` | 1–23 hours | `3 hours overdue` |
   | 1–6 days | `5 days left` | 1–6 days | `2 days overdue` |
   | 7–29 days (weeks) | `2 weeks left` | 7–29 days | `1 week overdue` |
   | 30 days or more (months) | `1 month left` | 30 days or more | `2 months overdue` |

   - A week is 7 days and a month is 30 days. Note that 28–29 days floors to `4 weeks`. Keep the floor, and mention it in the report.
   - Overdue labels use the existing destructive/overdue token (red). The tooltip carries the full date, formatted the way the List view formats it.
   - No due date: render nothing. Completed and Cancelled: render nothing.
   - **Agreement with the server:** for every case, the helper's "overdue" must equal the server's `isOverdue`/`scopeOverdue` for the same instant and zone. If they disagree anywhere, **stop and report both**. Do not choose.
   - **Ticker:** one module-level ticker shared by every card. It ticks on the minute boundary and runs only while `document.visibilityState === 'visible'` (it re-syncs on becoming visible). Only the small countdown component subscribes, so a tick re-renders the labels and **never the card component** (show why from the code: the card's render reads no ticker state).
4. **Priority: a coloured flag icon** (`Flag` from `@lucide/vue`) instead of the arrow + text.
   - Urgent is red, High orange, Medium blue, Low grey. No priority means no flag.
   - Use DESIGN.md tokens, adding tokens for light and dark if they are missing.
   - The tooltip and the `aria-label` carry the word (`Urgent priority`, `High priority`, `Medium priority`, `Low priority`), so colour is never the only signal.
   - Measure each icon colour against the card background in both themes: at least 3:1. Record the ratios in DESIGN.md.
   - Confirm the real priority values in the code; if they differ from these four, report the mapping you used.
5. **Tags: remove from the card only.** They stay in the drawer, the List view, the Add-filter tag filter and Manage tags. Do not touch those.
6. **Keep** the title, the two-line description, the checklist counter (`1/4`), the leave banner and the hover drag handle, including all drag attributes and handlers exactly as they are. **Footer row order:** avatars on the left, the countdown next, the priority flag on the right.

Page URL(s): `http://127.0.0.1:8002/admin/tasks/board` and `/employee/tasks/board`.
Reference image(s): `docs/dispatch-refs/tasks-board-rework/03-card-current.png` (the card today). **Open it yourself with the Read tool.** It is a screenshot of the current UI, for reference only: no pixel-fidelity target, and no `--compare` pass.

## Audience
Everyone who uses the Board. The card is rendered by `TaskBoard.vue` (do not edit it). Its props and emitted events are that component's contract, so keep every prop and emit working. A later dispatch will make the drawer open from a card click and add live patching. Keep the card's root element and its drag handle where they are, so that work can hook in.

## Format
- `AGENTS.md` conventions: `<script setup lang="ts">`, 4 spaces, typed props; token classes only (read `DESIGN.md` before writing any class: no hex, no arbitrary colour, no off-scale spacing); icons from `@lucide/vue`; tooltips with the primitive DESIGN.md prescribes (§4). New tokens follow the existing `app.css` pattern (`:root` + `.dark` + the `@theme inline` mapping).
- Accessibility floor: no status carried by colour alone; visible focus ring on anything focusable.

## Out of scope — do NOT
- do NOT edit `TaskBoard.vue`, the toolbar, `TaskFilterBar.vue`, the navigation files, the pages, the drawer, live-refresh code, or any controller other than what is named above
- do NOT change the List view, the drawer, Calendar or Gantt
- do NOT add a dependency or touch `package-lock.json`; do NOT change schema; do NOT survey the repo; do NOT commit or change git state
- do NOT touch `/home/claude/goodtechies-hq` (the main checkout, where another agent is working); do NOT use ports 8000 or 8001

## Knowledge
Read `AGENTS.md` first (it is the repo map), then `DESIGN.md` (tokens, §4 components and the priority/status sections), then only the files above.

Environment:
- PostgreSQL and Redis are running, and `.env` is set up. The dev DB is migrated and seeded, and shared read-only with the other agent: do not re-seed it.
- **Tests use your private database.** Prefix every Pest run with `DB_DATABASE=goodtechies_hq_test_d2`, e.g. `DB_DATABASE=goodtechies_hq_test_d2 php vendor/bin/pest tests/Feature/Tasks`. Never `php artisan test`.
- Build with `npm run build` (your worktree has its own `public/build`). Dev server: `php artisan serve --host=127.0.0.1 --port=8002` in the background; kill it when done.

Signed-in rendering: `.claude/dispatch/dispatch-measure.mjs` does not log in. Write a small Playwright script in `/tmp/claude-0/-home-claude/1bcaf59a-d11a-5392-b281-872a22dab3a0/scratchpad/d2/` (never in the repo) that:
- logs in at `/login` with a seeded email from `database/seeders/TeamSeeder.php` and `SEED_PASSWORD` from `.env`;
- passes 2FA with `php artisan hq:two-factor-code <email>`;
- opens the board at 375 / 768 / 1280 px;
- saves screenshots and checks page overflow.

Use `playwright` from `node_modules`, with `PLAYWRIGHT_BROWSERS_PATH=/opt/pw-browsers`.

## Done means
- [ ] **Tests first:** write `tests/js/dueCountdown.test.ts` and the JSON cases **before** the helper, and show that they fail at BASE (the helper is missing), then pass.
  - The cases cover every row of the table on both sides, the singular forms, the 6→7-day and 29→30-day boundaries, and the 28-day `4 weeks` case.
  - They also cover midnight in Asia/Dhaka (23:59:30 on the due day → `less than a minute left`; 00:00:30 the next day → `just overdue`), with the test process run under `TZ=America/New_York` to prove the browser zone is ignored.
  - Plus a date-only due date, no due date, and completed/cancelled.
- [ ] `DueCountdownAgreementTest.php` reads the same JSON. For each case with a status, it checks that the server's overdue answer (model method and query scope, at that instant, with `Carbon::setTestNow` in the app zone) equals the case's expected overdue flag. It passes.
- [ ] Card DOM on the rendered board, checked with a script: no project line, no tag chips, no assignee name text; avatars with `aria-label`s; max 3 + `+N`; the dashed unassigned circle; the countdown label; the flag with its `aria-label`. Give counts from the seeded board.
- [ ] Priority token contrast ≥ 3:1 against the card, light and dark, with the numbers in DESIGN.md and in the report
- [ ] 375 / 768 / 1280 px board screenshots (list their paths), "overflow" reported per width
- [ ] `npx vue-tsc --noEmit`, `npm run build`, `vendor/bin/pint --test` (if PHP touched) pass; `DB_DATABASE=goodtechies_hq_test_d2 php vendor/bin/pest tests/Feature/Tasks tests/Unit` passes (give counts); `node --experimental-strip-types --test tests/js/` passes
- [ ] The labels appear exactly as in the table (`less than a minute left`, `just overdue`, `… left`, `… overdue`), and `Urgent priority` etc. are spelled as above
- [ ] Report per phase: what changed, verified / not verified

## Budget
M: about 60 tool calls. At the budget, stop and report.

## Report
At most 40 lines: the files changed; "Invented strings"; the token names with their light/dark values and contrast; how the time zone reaches the browser; the ticker design; and anything in the code that contradicted this brief.

[ task list broken down into phases, each phase as a vertical slice, numbered ]
