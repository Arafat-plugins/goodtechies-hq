# Brief 001 — tasks-toolbar-scope

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
On the Tasks screen (all four views, Admin and Employee surfaces) replace the page header and search box with one toolbar row: view switcher + a new single-select scope dropdown (`All tasks` / `My Tasks` / `Due Today` / `Overdue`, in the URL as `?scope=`) on the left, and Overdue only, Show archived, Manage tags, Add filter, New task on the right. Then remove My Tasks, Due Today and Overdue from both sidebars, and redirect their old URLs into the Tasks page. This covers user points **2** and **7**.

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
Files you may edit. These were located by `git grep`. Confirm each before editing, and name any other file you had to touch and why:
- `resources/js/navigation/admin.ts` (lines ~44-55: the three items) and `resources/js/navigation/employee.ts` (lines ~36-41)
- `app/Http/Controllers/Admin/MyTaskController.php`, `app/Http/Controllers/Employee/MyTaskController.php`: turn `index` into the redirect described below
- `app/Http/Controllers/Admin/TaskController.php`, `app/Http/Controllers/Employee/TaskController.php` (`index`, `board`, `calendar`, `gantt`: they echo `filters` back), plus whatever parses the filters (`app/Services/TaskService.php` ~line 1955-1965 normalises `bucket` and `mine`, and the Form Request if the filters are validated there)
- `resources/js/Pages/{Admin,Employee}/Tasks/{Index,Board,Calendar,Gantt}.vue`: the `PageShell` title and description
- `resources/js/Components/Tasks/TaskFilterBar.vue` (the Tasks toolbar: search, Overdue only, Show archived, Manage tags, Add filter), plus the view switcher and New task button wherever they live
- `resources/js/Components/FilterBar.vue` **only** by adding a prop or slot. It is shared with other pages, which must render exactly as before.
- `resources/js/Components/Shell/AppSidebarNav.vue` only if its `activeItem()` / `my-tasks` special case breaks once the items are gone
- Dashboard / report link builders that point to `/…/my-tasks`: `app/Http/Controllers/Employee/DashboardController.php:~432`, `app/Services/ReportService.php:~292`, `app/Http/Controllers/Admin/DashboardController.php:~53`, `resources/js/Pages/Admin/Dashboard.vue:~166`. Leave them pointing where they point; the redirect handles them. Change one only if a test proves the redirect cannot.
- Tests: `tests/Feature/Tasks/*`, `tests/Permissions/MatrixTest.php`, `tests/Feature/Surfaces/NavigationTest.php`, and any test that asserts the my-tasks page renders

What the code does today (measured by the main session):
- The sidebar "My work" group links to a **separate page**: `My Tasks` → `/admin/my-tasks`, `Due Today` → `/admin/my-tasks?bucket=due_today`, `Overdue` → `/admin/my-tasks?bucket=overdue` (Employee: same under `/employee/`). That page (`MyTaskController@index` → `Components/Tasks/MyTasks.vue`) shows 8 buckets from `App\Support\TaskBucket` (`open`, `due_today`, `overdue`, `in_progress`, `waiting`, `in_review`, `completed`, `completed_today`). Dashboard tiles and reports deep-link to `/employee/my-tasks?bucket=<key>`.
- `TaskService` already has a `mine` filter (`onlyMine`) and a `bucket` filter (`TaskBucket::from(...)`), and the Tasks views already offer a bucket chip (`'buckets' => $this->options(TaskBucket::cases())`). **Reuse both. Write no new query logic.**
- The Tasks page shows a "Tasks" title, a description line and a search box above the toolbar (image 07).

Target behaviour (decisions the user approved; do not re-decide them):
1. **Scope dropdown.** Options in this order: `All tasks` (default, no query parameter), `My Tasks`, `Due Today`, `Overdue`. URL `?scope=mine|due-today|overdue`.
   - It maps onto the existing filters: `mine` → `mine=true`; `due-today` → `mine=true` + bucket `due_today`; `overdue` → `mine=true` + bucket `overdue`. The two date scopes are **personal**, exactly as the sidebar items were.
   - The mapping is done server-side, in one place, so all four views and both surfaces get it.
   - An unknown `scope` value is ignored (treated as All tasks), never a 500.
   - If the bucket chip and a date scope are both set, the scope's bucket wins. Say in the report what the chip shows then.
2. `?scope=` **survives** switching between List / Board / Calendar / Gantt and applying any other filter (the view switcher and the filter bar must carry it). It is shareable state, so it lives in the URL, not `localStorage`.
3. **Redirects**, both surfaces, 302 to the same surface's Tasks **List** page (`/admin/tasks`, `/employee/tasks`), keeping any other query parameters the old URL carried:
   - `/…/my-tasks` → `?scope=mine`
   - `/…/my-tasks?bucket=due_today` → `?scope=due-today`
   - `/…/my-tasks?bucket=overdue` → `?scope=overdue`
   - `/…/my-tasks?bucket=open` → `?scope=mine`
   - any other bucket `<b>` → `?scope=mine&bucket=<b>`, so every dashboard/report tile still lands on the same set of tasks
   - Keep the route names (`admin.my-tasks`, `employee.my-tasks`) so nothing that builds the URL breaks. Leave `MyTasks.vue` and the page files **in place**; delete nothing. Say in the report which files are now unreachable.
4. **Sidebars.** Remove the three items from both `admin.ts` and `employee.ts`. Keep the other "My work" items. If the group becomes empty or odd, say so in the report and do not invent a replacement. Rewrite the code comments above the items so they describe the new state.
5. **Permissions unchanged.** `Task::visibleTo` scoping stays as it is. A non-admin employee who opens `/employee/my-tasks` lands on `/employee/tasks?scope=mine` and sees **the same task ids** the old My Tasks page listed for them (Open bucket). Prove it with a test that compares the two id sets for a seeded employee. Also test that `scope=mine` never shows a task outside `visibleTo`.
6. **Toolbar (point 7), all four views, both surfaces:**
   - Remove the visible page title "Tasks" and its description line. Keep an `sr-only` `<h1>Tasks</h1>` (or pass a prop to `PageShell` that renders it `sr-only`). The breadcrumb "Work › Tasks" and the global Ctrl K search stay.
   - Remove the search input (UI only). The backend `search` / `q` parameter stays accepted.
   - One row at desktop width. **Left:** view switcher (List, Board, Calendar, Gantt), then the scope dropdown. **Right, pushed to the end:** Overdue only, Show archived, Manage tags, Add filter, New task (in that order).
   - Below it, the `N tasks · N overdue` line and the status chips stay as they are.
   - "Overdue only" stays a separate AND filter, not coupled to the dropdown. Note the overlap in the report (scope=Overdue + Overdue only).
   - Build the dropdown from the existing shadcn-vue `Select` or `DropdownMenu` primitives already in `Components/ui/` (DESIGN.md §4 says which the filter bar uses). Give it an accessible name. **No new shadcn component and no npm package.**
7. **Responsive:** at ~375 px the right group wraps to its own row(s) and nothing overflows the page horizontally. At ~768 px it fits in one or two rows without clipping. At 1280 px everything is on one row. Check all three widths on List and Board, for an admin and an employee.

Page URL(s): `http://127.0.0.1:8001/admin/tasks`, `/admin/tasks/board`, `/admin/tasks/calendar`, `/admin/tasks/gantt`, and the `/employee/…` equivalents (check `php artisan route:list --path=tasks` for the Gantt path).

Reference image(s): `docs/dispatch-refs/tasks-board-rework/02-board-sidebar-and-toolbar-marked.png` (red box 1: the three sidebar items; red box 2: the empty right side of the toolbar where the moved controls go) and `docs/dispatch-refs/tasks-board-rework/07-toolbar-header-crop.png` (the title, description, search and controls today). **Open both images yourself with the Read tool: the red boxes carry meaning.** They are screenshots of the current UI, for reference only. There is no pixel-fidelity target, and no `--compare` pass.

## Audience
Admins and employees who use the Tasks screen every day. `TaskFilterBar` / `FilterBar` props are consumed by other pages (FilterBar is shared); the `filters` echo is consumed by all four Tasks views. Dashboard/report tiles and old bookmarks consume the `/my-tasks` URLs, and those must keep landing on the same tasks.

## Format
- `AGENTS.md` conventions: Vue SFC `<script setup lang="ts">`, 4 spaces, typed props; token classes only (read `DESIGN.md` before any Tailwind class: no hex, no off-scale spacing); icons from `@lucide/vue`; validation only in Form Requests; PSR-12 + `vendor/bin/pint` on PHP you touch.
- Components by name from DESIGN.md §4: `PageShell`, `FilterBar`, `FilterChip`, the §4.5/§4.7 task blocks. Read their documented signatures in DESIGN.md rather than surveying.
- Every page must work at 375 / 768 / 1280 px, with a visible focus ring on every new control (AGENTS.md → Verification capabilities → accessibility floor).

## Out of scope — do NOT
- do NOT touch the board card, `TaskBoard.vue`'s lanes, the "Every 20s" label, the footer hint, drag/pan, the detail drawer, live refresh (other dispatches own them); do NOT touch `TaskBoardCard.vue`, `resources/css/app.css` or `DESIGN.md` (a parallel dispatch is editing them)
- do NOT change the content of the Calendar or Gantt views (only their shared toolbar and `?scope=`)
- do NOT change what other `FilterBar` pages show; do NOT change permissions, policies, `visibleTo`, schema, or dependencies
- do NOT delete `MyTasks.vue` or the my-tasks page files; do NOT survey the repo; do NOT commit or change git state
- do NOT run `npm run dev`; do NOT use port 8000. **Another agent works in this checkout at the same time.** It will not run `npm run build` or the Pest suite, so you may. Use dev-server port **8001** and the default test database.

## Knowledge
Read `AGENTS.md` first (it is the repo map), then `DESIGN.md` §4 for the components you touch, then only the files above. Environment: cloud checkout at `/home/claude/goodtechies-hq`; PostgreSQL and Redis are running, `.env` is set up, and the dev DB is migrated and seeded. Tests: `php vendor/bin/pest <paths>` (never `php artisan test`). Build: `npm run build`. Dev server: `php artisan serve --host=127.0.0.1 --port=8001` in the background; kill it when done.

Rendering a signed-in page: `.claude/dispatch/dispatch-measure.mjs` does not log in. Write a small Playwright script in the session scratchpad (never in the repo) that:
- logs in at `/login` with a seeded email from `database/seeders/TeamSeeder.php` and `SEED_PASSWORD` from `.env`;
- passes the 2FA challenge with the code from `php artisan hq:two-factor-code <email>`;
- then reports, at each width, whether `document.documentElement.scrollWidth > clientWidth`, and saves a screenshot.

Use `playwright` from the repo's `node_modules` (Chromium at `PLAYWRIGHT_BROWSERS_PATH=/opt/pw-browsers`).

## Done means
- [ ] Old URLs redirect as in target 3, both surfaces, covered by Pest tests (every row of the list, including one "other bucket" row and extra query parameters kept)
- [ ] `?scope=` mapping tested server-side: each value gives the expected filters on index/board/calendar/gantt, unknown value ignored, date scopes are personal
- [ ] Employee same-result test (target 5) and a negative `visibleTo` test, both passing
- [ ] The three sidebar items are gone on both surfaces; `NavigationTest` / `MatrixTest` updated rather than deleted, and each changed assertion explained in the report
- [ ] Toolbar layout as in target 6 on all four views, both surfaces; no visible title, description or search box; `sr-only` h1 present
- [ ] `?scope=` survives view switching and filter changes (show the hrefs, or a scripted click-through)
- [ ] 375 / 768 / 1280 px on List and Board, admin and employee: "overflow: no" each, with screenshots saved under the scratchpad (list their paths)
- [ ] `vendor/bin/pint --test`, `npx vue-tsc --noEmit`, `npm run build` pass; `php vendor/bin/pest tests/Feature/Tasks tests/Permissions tests/Feature/Surfaces tests/Feature/Employee` passes (give counts)
- [ ] `All tasks`, `My Tasks`, `Due Today`, `Overdue` appear exactly as spelled
- [ ] Report per phase: what changed, verified / not verified

## Budget
M: about 60 tool calls. At the budget, stop and report what is done and what is not.

## Report
At most 40 lines. Include:
- the files changed;
- "Invented strings" (every new user-visible string or aria-label);
- the scope + bucket-chip interaction and the scope=Overdue + Overdue-only overlap;
- which files are now unreachable;
- anything in the code that contradicted this brief.

[ task list broken down into phases, each phase as a vertical slice, numbered ]
