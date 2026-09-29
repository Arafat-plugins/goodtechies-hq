# Brief 003 — board-drawer-pan-hint

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
On the Board (`/admin/tasks/board`, `/employee/tasks/board`), make three changes:
- a card click opens the same detail drawer the List view uses, with `?detail=<id>` in the URL (user point **1**);
- remove the "Drag inside a lane…" footer hint (point **5**);
- let the left mouse button pan the board horizontally from empty background, ClickUp-style (point **6**).

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


## Verbatim — use exactly
No product text supplied. List every string you invent under "Invented strings".

## Inputs
Files you may edit or create. These were located by `git grep`; confirm each, and name any other file you touch and why:
- `resources/js/Pages/Admin/Tasks/Board.vue`, `resources/js/Pages/Employee/Tasks/Board.vue`: mount the drawer here the way `Pages/{Admin,Employee}/Tasks/Index.vue` do. Read how `Index.vue` wires `TaskDetailDrawer` (around lines 8 and 55-80) and copy that wiring. Do not re-invent it.
- `resources/js/Components/Tasks/TaskBoard.vue`: the lanes, the scroller, the footer hint (`git grep -n "Drag inside a lane"`), and the pan wiring
- `resources/js/Components/Tasks/TaskBoardCard.vue`: the card click and keyboard open. It was redesigned in the previous dispatch; keep its layout exactly.
- `resources/js/Components/Tasks/TaskDetailDrawer.vue` and `resources/js/Components/DetailDrawer.vue`: **read to reuse**. Edit them only if the Board truly cannot use them as they are, and then say exactly why.
- new: one small composable for drag-to-pan, e.g. `resources/js/lib/dragPan.ts`. `git grep` found no existing drag-scroll helper; the Gantt's `GanttBar.vue` pointer handlers move bars, not the viewport. Confirm this yourself, and reuse a helper if you find one.
- tests you add (see Done means)

What the code does today (measured by the main session):
- The List opens `TaskDetailDrawer` (built on `DetailDrawer`, which writes `?detail=<id>` and restores focus) from a row click. The Board has no drawer; a card's title is a link to the full task page.
- The Board's drag-and-drop is **native HTML5** (`draggable` in `TaskBoardCard.vue`); there is no drag library. The card also has a grip handle and a `⋯` menu (`data-card-menu`), and it imports `ArrowUp`/`ArrowDown`, which may mean keyboard reordering exists. Find out before binding Enter/Space.
- `TaskBoard.vue` renders the "Every 20s" label and a live-refresh hook. **Leave both exactly as they are**; the next dispatch owns them.

Target behaviour (the user approved these; do not re-decide them):

**R1 · Drawer on the Board**
- Clicking a card opens the same drawer as the List. Reuse `TaskDetailDrawer` and its body, and do not build a second one.
- The URL becomes `…/tasks/board?detail=<id>`, and a deep link opens the board with the drawer already open. Close and Esc return to the board with filters (including `?scope=`), scroll position (horizontal and vertical) and lane order untouched.
- The drawer's "Open" button still goes to the full task page.
- A plain left-click on the card title link opens the drawer. Ctrl/Cmd/Shift/middle-click keep the browser's normal new-tab behaviour.
- The `⋯` menu and its items, the grip/drag handle, and any buttons inside the card never open the drawer. A drag or a pan never counts as a click.
- A focused card opens on Enter, and on Space unless Space is already bound to keyboard reordering. If it is, report the conflict and keep reordering working. The card has an accessible name (its title).
- Opening and closing the drawer does **not** reload or re-render the board: no Inertia visit, no board props change. The List's drawer is expected to use `history` for `?detail=`; confirm this and do the same.
- Calendar and Gantt: do not change them. Report whether they have the drawer.

**R5 · Footer hint.** Remove the "Drag inside a lane to set its order…" line and the space it takes. Keep the horizontal scrollbar as a fallback.

**R6 · Drag to pan**
- Pressing the left mouse button on **empty board background** and moving scrolls the board's horizontal scroller. Empty background means the gap between lanes, the space below a short lane, the space right of the last lane, and the lane header.
- The cursor is `grab` over pannable background and `grabbing` while panning. Text selection is off while panning.
- A pan **never** starts on a card, the `⋯` menu, a link, a button, an input, or anything `draggable`, so HTML5 card drag-and-drop keeps working. Decide by walking up from `event.target` with `closest(...)`, and list the exclusion selector in the report.
- The board container fills the visible height (a `min-height` that reaches the bottom of the viewport), so the empty areas boxed in red in image 06 receive the press.
- The pan starts only after 4 px of movement. The click that ends a pan is swallowed (capture phase), so it can never open the drawer.
- Mouse only (`pointerType === 'mouse'`): touch and pen keep native scrolling. No inertia. Use pointer capture, and release it on `pointerup`, `pointercancel` and `lostpointercapture`.
- The composable exposes an `isPanning` ref, which the next dispatch will read to hold a live update until the pan ends. Also expose whether a card drag is in progress if `TaskBoard.vue` already tracks it (for example an `isDragging` / `dragging` state). If it tracks nothing, add a minimal `isDraggingCard` ref from the existing `dragstart`/`dragend` handlers and export it the same way.

Page URL(s): `http://127.0.0.1:8003/admin/tasks/board`, `/admin/tasks/board?detail=<id>`, `/employee/tasks/board`

Reference image(s): read all three yourself with the Read tool, because the red boxes carry meaning:
- `docs/dispatch-refs/tasks-board-rework/01-list-view-detail-drawer.png` (the List's drawer, which the Board must show);
- `05-footer-hint-text.png` (the hint to remove);
- `06-board-empty-space-pan-zones.png` (the red boxes are the empty zones that must start a pan).

They are screenshots of the old UI, for reference only: no pixel-fidelity target, and no `--compare` pass.

## Audience
Admins and employees on the Board. The next dispatch (silent live updates) will read `isPanning` and the card-drag flag to hold updates. It relies on the drawer staying open, with any text typed in it, across a partial reload of the board's props. So mount the drawer **outside** anything keyed on board data, and make sure the drawer's state does not depend on the board props object's identity.

## Format
- `AGENTS.md` conventions: `<script setup lang="ts">`, 4 spaces, typed props, token classes only (read `DESIGN.md` before writing a class), icons from `@lucide/vue`.
- `DetailDrawer` and `TaskDetailDrawer` are used through their DESIGN.md §4 signatures.
- Accessibility floor: overlays return focus to their opener, every tab stop shows a focus ring, no state is carried by colour alone.

## Out of scope — do NOT
- do NOT change the "Every 20s" label, the live-refresh hook, polling or broadcasting (the next dispatch owns them)
- do NOT change the card's visual layout (the previous dispatch's accepted work), the toolbar, `TaskFilterBar.vue`, `FilterBar.vue` or the navigation files
- do NOT change Calendar, Gantt, the List, the drawer's content, controllers, routes, permissions or schema
- do NOT add a dependency; do NOT survey the repo; do NOT commit or change git state
- use dev-server port **8003** only; no other agent is running now

## Knowledge
Read `AGENTS.md` first (it is the repo map), then `DESIGN.md` §4 for `DetailDrawer`, then only the files above.

Environment: `/home/claude/goodtechies-hq`, with PostgreSQL and Redis running and the dev DB seeded.
- Tests: `php vendor/bin/pest <paths>`; JS: `npm run test:js`.
- Build: `npm run build`. Dev server: `php artisan serve --host=127.0.0.1 --port=8003` in the background; kill it when done.
- The previous agents wrote Playwright login helpers you may copy: `/tmp/claude-0/-home-claude/1bcaf59a-d11a-5392-b281-872a22dab3a0/scratchpad/d1/` and `…/d2/`. They log in with a seeded email and `SEED_PASSWORD` from `.env`, and pass 2FA with `php artisan hq:two-factor-code <email>`. Put your own scripts and screenshots in `…/scratchpad/d3/`, never in the repo.

## Done means
Checked in a real browser with a Playwright script. Report each item as verified / not verified, with the numbers:
- [ ] **R1:**
  - A card click opens the drawer and the URL gains `?detail=<id>`.
  - It triggers no Inertia request. Count XHR/fetch requests to `/admin/tasks/board` while opening and closing it: the count must be 0.
  - Esc closes it, and `scrollLeft`/`scrollY` are the same before and after.
  - The deep link `…/board?detail=<id>&scope=mine` opens with the drawer open and keeps `scope`.
  - The drawer's Open button goes to the task page.
  - Ctrl+click on the title does not open the drawer.
  - A click on `⋯` and a click on the grip do not open it.
  - Enter on a focused card opens it, and focus returns to that card on close.
- [ ] **R5:** the hint text is absent from the DOM (`git grep -n "Drag inside a lane"` finds nothing under `resources/js`).
- [ ] **R6:**
  - A pan started in each empty zone of image 06 changes `scrollLeft` by roughly the mouse delta: gap between lanes, below a short lane, right of the last lane, lane header.
  - A pan started on a card does **not** pan.
  - Dragging a card to another lane still changes its status (reload and check). Use `page.dragAndDrop` or `DataTransfer`-dispatched events.
  - A pan that ends over a card does not open the drawer.
  - A touch-emulated drag does not trigger the pan code path.
- [ ] 375 / 768 / 1280 px on the Board, admin and employee, with the drawer closed and open: "overflow: no" at each width. List the screenshot paths.
- [ ] `npx vue-tsc --noEmit`, `npm run build` and `npm run test:js` pass. `php vendor/bin/pest tests/Feature/Tasks` passes (give counts).
- [ ] Report per phase: what changed, verified / not verified.

## Budget
M: about 60 tool calls. At the budget, stop and report.

## Report
At most 40 lines. Include:
- the files changed;
- "Invented strings" (any new aria-label or text);
- the pan exclusion selector;
- how drag-vs-click-vs-pan is disambiguated;
- the Space/keyboard-reorder finding;
- whether Calendar and Gantt have the drawer;
- what `isPanning` / the drag flag are called and where they live;
- anything in the code that contradicted this brief.

[ task list broken down into phases, each phase as a vertical slice, numbered ]
