# Decisions log

This file records decisions taken where the spec or the master prompt was silent, or where the
build had to deviate from them. Each entry has a one-line reason. The same entries appear in
`PROGRESS.md` → the "Decisions made in Phase N" section for that phase.

## Phase 0 (17 Sep 2026)

| # | Decision | Reason |
| --- | --- | --- |
| 0-1 | Laravel **13** (13.32 at build time), not the spec's 11 | Laravel 11 no longer gets security fixes; the client confirms at GATE A |
| 0-2 | Vue pages and components are written in **TypeScript** (`<script setup lang="ts">`) | shadcn-vue components are TypeScript-first; the type check (`vue-tsc`) catches prop-contract drift |
| 0-3 | Tests run as **hq_app**; the schema is built as hq_migrator (`tests/Concerns/RefreshHqDatabase`) | Stricter than master prompt B§3 (which runs tests as the migrator): the tests exercise the real runtime grants |
| 0-4 | `user_project_permissions.project_id` has no foreign key until Phase 1 | The `projects` table does not exist yet; Phase 1 adds the FK |
| 0-5 | The MANAGER role lands in the **Employee** shell | MANAGER is dormant and has no UI in the MVP, so it gets the least-privilege shell |
| 0-6 | Default working week is **Sun–Thu, 09:00, 8 h** for office staff; Tapu's is Sun–Thu at 5 h, remote | The spec is silent; the client confirms at GATE A |
| 0-7 | Seeded logins are `*@goodtechies.test`; addresses can be overridden with `SEED_*_EMAIL`, passwords come from `SEED_PASSWORD` | No passwords or emails are hard-coded in the repo |
| 0-8 | Design tokens were derived by hand into DESIGN.md and `app.css` | The shadcn-tokens MCP was not reachable in the build session; its `get_theme` should read `app.css` back |
| 0-9 | Icons come from `@lucide/vue` | `lucide-vue-next` is deprecated upstream, and the shadcn-vue CLI generates `@lucide/vue` imports |
| 0-10 | Builds need **Node 22** (install.sh, CI) | The lockfile's packages require Node ≥ 22, and Node 20 is end-of-life |
| 0-11 | Admin → Settings is **read-only** in Phase 0, including the Backup health card | Settings editing belongs to Phase 12 |
| 0-12 | Login is throttled at 5/min per email + IP; the 2FA challenge at 5/min per pending user + IP | Brute-force protection (spec §36 rate limiting) |
| 0-13 | Password rule: at least 12 characters plus the HIBP k-anonymity breach check, no composition rules | Spec §36 |
| 0-14 | 2FA: TOTP with a ±30 s window and replay protection; 8 recovery codes, stored hashed and usable once | Spec §36 |
| 0-15 | The DB session timezone follows `DB_TIMEZONE` (default Asia/Dhaka) | Keeps `timestamptz` columns consistent with the app timezone on a UTC server |
| 0-16 | Backups dump the **database only** (as hq_migrator), encrypted with AES-256; hq_migrator gets CREATEDB for the `hq:verify-backup` scratch DB | Files are protected by bucket versioning and replication (master prompt B§4); the restore test needs a scratch database |
| 0-17 | The `hq-reverb` Supervisor program ships with `autostart=false` | Reverb is installed in Phase 6 |
| 0-18 | Changing the password rotates `remember_token` and deletes the user's other sessions | Otherwise a stolen device stays signed in |
| 0-19 | In Phase 0, "Active employees" is the only real number on the Admin dashboard; every other card is labelled "Arrives in Phase N" | No fake UI (master prompt 0.5) |
| 0-20 | Profile email is lower-cased on save | Login looks users up case-insensitively, so two accounts must never differ only by case |

## Phase 1 (20 Sep 2026)

| # | Decision | Reason |
| --- | --- | --- |
| 1-1 | An internal project has `client_id = null`; there is no "GoodTechies" client row | The spec calls a project without a client "Internal"; a placeholder client would show up in every client list |
| 1-2 | A project's status moves only through `POST …/status`, `…/archive`, `…/unarchive`; `PUT /admin/projects/{id}` ignores a `status` key | The security critic found that the general update route let an assigned MANAGER cancel or reopen a project and leave `archived_at` unset |
| 1-3 | MANAGER reaches no `/admin/*` route (it lands on the Employee shell, decision 0-5), so manager-scoped project editing is not usable in the MVP | MANAGER is assigned to nobody and has no UI until Phase 12; the policies still carry the scoped rules |
| 1-4 | The employee project list sorts by deadline with nulls last, then by name | A retainer without a deadline should not sit above work that is due |
| 1-5 | The UI formats money as USD in one small helper per screen | `settings.currency` is not yet a prop; the helper carries a comment to move it when it is |
| 1-6 | Demo dates are relative to `today()`, and one project is deliberately overdue and one has no deadline | The seed has to keep reading well and exercise the overdue styling |
| 1-7 | No "Internal only" option in the client filter yet | The server reads `client_id` numerically; adding a sentinel is a server change, parked as a follow-up |
| 1-8 | A member's role on a project is edited only on the project page, not in the create form | The store request validates member ids only |
| 1-9 | `ProjectResource` does not expose `created_at` | Nothing needs it yet, and every field it carries is a field to review for privacy |
| 1-10 | The employee project page marks "You" by matching the viewer's name | The shared props carry the user id, not the employee id; switch it when Phase 2 adds one |

## Phase 0.5 (20 Sep 2026) — Design Foundation

Spec: `docs/design-foundation-v1.md` (v1.1). Brand source of truth:
`docs/design-refs/GoodTechies siteicon  1.svg`, which holds exactly three colours and no
gradient — `#0A0D12` (tile), `#FFFFFF` (glyphs), `#F04E27` (one dot). Token implementation:
`resources/css/app.css`.

**A note on the numbering.** The spec's §7 reserved `0.5-1` … `0.5-7` for the seven decisions
the phase had to *close*. T1 was written first and reused those numbers for its token choices,
so four of them meant two different things at once. The reserved numbers win, because the code
cites them with the spec's meaning (`app.css:75` → 0.5-1, `navigation/types.ts:39` → 0.5-3,
`app.css:57` → 0.5-4, `TimerHeroCard.vue:9` → 0.5-5). T1's seven colliding rows moved to
**0.5-21 … 0.5-27**, in their original order and with their original wording. Nothing was
deleted and no number now means two things.

| # | Decision | Reason |
| --- | --- | --- |
| 0.5-1 | The shell is the **light neutral** model (Option A), not full dark (Option B) | The user chose it. An agency tool is read all day beside documents and a browser; a dark chrome around light content makes the app the loudest thing on the screen |
| 0.5-2 | ~~`--brand` exact value~~ **Closed.** `#F04E27` and `#0A0D12`, read directly out of `GoodTechies siteicon 1.svg`; the neutrals move to the logo ink's hue 260.6 | v1 of the spec sampled the colours from a raster with an orange gradient that the client has since replaced. There is no sampling left to do — the SVG states them |
| 0.5-3 | Unbuilt nav rows live in one closed **Coming soon** disclosure at the bottom of the sidebar, not in their groups and not on a Settings page | It keeps the roadmap visible without letting 29 dead rows outweigh the live ones; a row goes live by dropping its `phase` key |
| 0.5-4 | Charts are **unovis** (`@unovis/vue`); Phase 10's Gantt is a custom component, not a chart | The user chose it: Vue-native, reads our CSS variables, and light enough that no phase is tempted to add a second library |
| 0.5-5 | The employee timer is a **dashboard hero card**, not a header widget | The user chose it; the dashboard is the first screen after login and the timer is the one thing a remote day starts with |
| 0.5-6 | The default Tasks view is a **List grouped by status**, not a Board | The user chose it. It also settles Phase 2's first build: `DataTable` with grouping, not a Kanban — the Board can come later as a second view over the same data |
| 0.5-7 | The lockup is the mark plus "GoodTechies HQ" in Inter Semibold, cap-height matched; **open** until the client says whether a real wordmark is coming | There is no wordmark in the supplied artwork, and inventing one would be a brand decision, not a build decision |
| 0.5-8 | Dark status triplets use a **uniform mirror** — fg at L 0.80, bg at L 0.26, border at L 0.38 — rather than a literal fg↔bg lightness swap | Measured: the literal swap gives `cancelled` **3.65:1 (FAIL)** and collapses hue identity (progress bg `#495762` vs done bg `#4A5A4D` are indistinguishable). The uniform mirror holds every pair at 6.47–8.65:1 |
| 0.5-9 | The `--status-todo` dot moves to the neutral ramp (0.70 / 260.6) and the `--status-progress` dot to its own badge hue 240 | Both were Phase 0 leftovers — slate at hue 227.7 and teal at 195.1 — so a teal dot sat inside a blue badge and a 223-family grey survived the ramp change |
| 0.5-10 | `.dark` gets its own deeper `--elevation-raised` / `--elevation-overlay` (0.30/0.36 and 0.50 black) | A 5 % black shadow is invisible on a `#0A0D12` canvas, so the light-mode values would silently flatten every card and dialog in dark mode |
| 0.5-11 | ~~`--sidebar` / `--sidebar-primary` / `--sidebar-ring` keep their Phase 0 navy and teal~~ **Superseded by 0.5-1.** T1 deferred the shell colour to T2 and left the Phase 0 values in place; T2 then chose Option A and replaced all of them | The reason for deferring still stands — a token task may not pre-empt a shell decision — but the values it preserved are gone. `app.css` is the current answer |
| 0.5-12 | `--destructive` is left at the shadcn default in both modes | Out of scope for T1. Measured as rendered (`dark:bg-destructive/60` over `--card`) it passes at **6.00:1**; the raw token under white text is 2.89:1, which the app never renders |
| 0.5-13 | Trailing hexes in `app.css` are the sRGB render of the oklch value, verified token by token | Several spec hexes are out of gamut and clip differently (`--primary` renders `#D12D00`, the spec quotes `#CF3100`); the in-gamut neutral ramp matches the spec to the digit, confirming the conversion |
| 0.5-14 | `--primary-hover` in dark mode is **lighter** than `--primary` (L 0.78 vs 0.72), not darker | On a near-black canvas hover has to move away from the background, which is the opposite direction to light mode's L 0.50 |
| 0.5-15 | The bare mark's glyphs stay white rather than `currentColor` | It is only used on the near-black brand ink, where white is 21:1 and no caller can break it |
| 0.5-16 | `DataTable` ships sorting and rows-per-page, but both stay disabled until a controller accepts `sort`, `dir` and `per_page` | Phase 0.5's own non-goals forbid touching PHP; turning them on is a one-line change per list once the server learns them |
| 0.5-17 | The five chart hues are 34.43 (brand) / 131 / 212 / 265 / 329, with their own dark steps | Checked with a deuteranopia and protanopia simulation, not by eye; every hue also sits clear of the six status hues so a category cannot read as a status |
| 0.5-18 | Admin tier 1 keeps three placeholder KPIs (Present today, Overdue, Due today) beside the one real number, against the spec's "tier 1 is real numbers only" | Those four are the numbers the day is actually run on; dropping three of them until Phase 4 would teach the eye a layout that changes again |
| 0.5-19 | `Admin/Clients/Index.vue` gets the `DataTable` but **not** its sort headers or per-page control | `ClientController::index()` reads neither `sort` nor `per_page`; rendering controls the server ignores would be a lie in the UI. They turn on with one line each when Phase 2 teaches the controller |
| 0.5-20 | `Employee/Projects/Index.vue` keeps its card grid instead of moving to `DataTable` | An employee reads five projects by domain and deadline, not a spreadsheet; the table is for the admin who scans forty |
| 0.5-21 | Every brand value is read out of the logo SVG, never sampled from a raster; the Phase 0 navy/teal palette (decision 0-8) is retired | The Phase 0 palette was hand-derived and had no relationship to the client's mark |
| 0.5-22 | The brand splits into `--brand` (graphics only) and `--primary` (anything carrying text), and the split must not be collapsed later | `#F04E27` is **3.50:1** on the canvas — below the 4.5:1 AA floor, so it can never be a fill behind text |
| 0.5-23 | `#0A0D12` becomes the token `--brand-ink`, and every neutral moves to its hue **260.6** at chroma 0.006 (ramp 0.985 / 0.97 / 0.922 / 0.70 / 0.52 / 0.30) | The brand ships its own near-black; pulling the greys to that hue keeps them family with the mark instead of drifting blue at hue 223 / 237 / 250 |
| 0.5-24 | The dark canvas is `#0A0D12` exactly, not an approximation | It is the logo's own tile; an approximation would put the mark on a background that is nearly-but-not its own |
| 0.5-25 | `--card` / `--popover` stay pure white in light mode rather than taking the ramp's top step | Ramp step 1 (0.985) is already `--background`; a card at the same step would break the elevation rule ("one surface may not sit on another at the same elevation") and spec §3 T2 states cards stay white |
| 0.5-26 | `--ring` and `--chart-1` are `var(--brand)` | Focus ring and the series being read are exactly the "one important thing" `--brand` marks; both are graphics, not text |
| 0.5-27 | The Phase 0 teal is removed from the **content** tokens: `--accent-foreground` and `--secondary-foreground` move to the neutral ramp's 0.30 step | Teal text on a neutral hover fill was leftover Phase 0 decoration; the app should carry no teal outside the shell, which T2 replaces |
| 0.5-28 | `/profile`'s Login history keeps a hand-written `md:` card fallback instead of moving onto `DataTable` | It needs none of `DataTable`'s machinery, and `DataTable` renders its own `Card`, which would have put a card inside a card — the elevation rule again |
| 0.5-29 | The six slots of the 2FA code field are **one** tab stop (slots 2–6 are `tabindex="-1"`) | reka's `otp` mode bounces focus back to the first empty slot, which cancelled every forward Tab and left a keyboard user unable to reach "Use a recovery code". One tab stop is also how every OTP field a user has already met behaves |
| 0.5-30 | A skip link is added to all three shells, ahead of the spec, and it moves focus rather than only scrolling | WCAG 2.4.1 is Level A and the shells put 8–14 tab stops before the page body, reset on every Inertia visit. On the accountant dashboard it is currently the only way into the content at all |

### Measured contrast ratios

WCAG 2.x relative luminance on sRGB, after gamut clipping. Body pairs must reach 4.5:1;
graphics pairs are noted at their 3:1 floor. **No pair needed a lightness adjustment** —
every spec value passed as written.

The fuller table — 52 pairs including the ones below, measured by a script that parses
`app.css` itself — is `DESIGN.md` §2. This is the record of what T1 saw at the time.

| Pair | Light | Dark |
| --- | --- | --- |
| `--foreground` / `--background` | 13.06:1 ✅ | 17.84:1 ✅ |
| `--foreground` / `--card` | 13.63:1 ✅ | 16.25:1 ✅ |
| `--muted-foreground` / `--background` | 5.28:1 ✅ | 7.28:1 ✅ |
| `--primary-foreground` / `--primary` | 4.99:1 ✅ | 7.31:1 ✅ |
| `--primary` / `--background` (link text) | 4.92:1 ✅ | 7.31:1 ✅ |
| `--primary` / `--card` (link on a card) | 5.13:1 ✅ | — |
| `--primary-hover` / `--background` | 6.34:1 ✅ | — |
| `--sidebar-foreground` / `--sidebar` — **superseded** | 8.97:1 ✅ | 11.97:1 ✅ |
| destructive foreground (white) / `--destructive` | 4.76:1 ✅ | 2.89:1 ❌ raw / **6.00:1 ✅ as rendered** |
| `--destructive` / `--background` (`text-destructive`) | 4.56:1 ✅ | 6.73:1 ✅ |
| `--foreground` / `--brand-tint` (active row) | 12.31:1 ✅ | 14.45:1 ✅ |
| `--status-todo-fg` / `-bg` | 5.99:1 ✅ | 8.32:1 ✅ |
| `--status-progress-fg` / `-bg` | 6.43:1 ✅ | 8.38:1 ✅ |
| `--status-review-fg` / `-bg` | 6.08:1 ✅ | 8.20:1 ✅ |
| `--status-done-fg` / `-bg` | 6.26:1 ✅ | 8.65:1 ✅ |
| `--status-waiting-fg` / `-bg` | 6.18:1 ✅ | 7.87:1 ✅ |
| `--status-cancelled-fg` / `-bg` | 5.79:1 ✅ | 6.47:1 ✅ |
| `--brand` / `--background` (graphics, 3:1 floor) | 3.45:1 ✅ | 6.73:1 ✅ |
| `--primary-foreground` / `--brand` — **never rendered** | 3.50:1 ❌ | — |

The last row is the measurement that justifies decision 0.5-22: `--brand` is 3.50:1 under white
text, so it is graphics only. It is recorded as a failure on purpose — if a later task puts text
on a `--brand` fill, this is the number that says why it must not.

The sidebar row is marked superseded because T1 measured it against the Phase 0 navy it had
deliberately left alone (0.5-11). T2 then replaced the shell, and the same pair now measures
**13.06:1 light / 16.12:1 dark**. The old number is kept as the record of what T1 saw, not as a
current fact.

## Phase 2 (20 Sep 2026) — Tasks

| # | Decision | Reason |
| --- | --- | --- |
| 2-1 | Phase 0.5's design foundation is accepted as-is and Phase 2 builds on it unchanged | The user ran it on their own machine and approved it before any Phase 2 screen existed. Changing a token now costs one task; after ten screens it costs ten |
| 2-2 | Files go to the **local disk** behind signed, expiring URLs, not S3 — `FileService` and its tests are written against Laravel's `Storage` abstraction so the move is a `FILESYSTEM_DISK` change in `.env` | The user chose it. The spec calls for S3, but no bucket exists yet and the signing, validation, metadata and version-history behaviour is identical either way. The tests assert the behaviour, not the driver |
| 2-3 | Phase 2 runs to its gate without intermediate check-ins, as Phase 0.5 did | The user chose it |
| 2-4 | `BACKLOG` and `CHANGES REQUESTED` get their own status token sets (hues 196 and 344) rather than being mapped onto an existing tone | The spec has eight statuses; Phase 0.5 drew six before that list existed. A board where BACKLOG and TO DO are the same colour is a board that lies. Both new pairs measured over 4.5:1 in each theme, and both survive a deuteranope simulation against the existing six |
| 2-5 | **Follow-up, not fixed:** `review` (hue 75) and `waiting` (hue 50) are deltaE 0.16 apart under deuteranopia in light mode — effectively one colour to a red-green colourblind viewer | Found while placing the two new hues. It predates Phase 2 and re-tuning a shipped token is not this slice's business. It is survivable only because `StatusBadge` always prints the label, which is the evidence for the "colour is never the only carrier" rule rather than an exception to it. Worth its own brief |
| 2-6 | The employee Tasks table drops the Assignee column; the admin one keeps it | `Task::visibleTo()` already scopes an employee to their own tasks, so the column would print the reader's own name on every row. It is also why the employee surface offers no group-by-assignee: it would produce exactly one group |
| 2-7 | Grouped lists open from the top until ~15 rows are showing, then collapse the rest with counts | Eight expanded status groups is a wall and eight collapsed ones is a list that shows no list. The rule is a constant inside `DataTable`, not a per-screen prop, so Board and Calendar inherit the same answer instead of each inventing one |
| 2-8 | **Follow-up:** the Tasks list has no Assignee filter, because neither controller sends an employee list to build the picker from | `TaskService` already accepts `assignee_id`; only the props are missing. Shipping the control with an empty option list would be the "lie in the UI" that 0.5-19 forbids. Slice 2 adds the prop and the filter together |
| 2-9 | A task's `status` is guarded at the **model**: `Task` throws if `status` is dirty on an existing row and the write did not come through `applyTransition()` | The plan requires that every drag use the same checks as the form. A shared route is only intent — a future job, command or controller could still fill `status` from input. The guard makes the single path structural, and `applyTransition()` re-checks the transition map, so no path reaches an illegal status |
| 2-10 | "Explicit hand-off" is a recorded promotion of the other assignee to primary, with a required reason, audited as `task.reassigned`. The current primary may do it without a manager | The plan names hand-off without defining it. Storing the summary's author reduces completion to one question — is `work_summary_by` the primary's user — and the hand-off is the only thing that moves the subject of that rule. A primary handing their own work over before leave is the case it exists for; needing a manager would leave the task uncompletable until Monday |
| 2-11 | The original completion lives in columns on `tasks` (`first_completed_at`, `first_completed_by`, `first_work_summary`), not in the activity log | `activity_logs` carries a description string and nothing else, so the first completion could only survive there as prose — unqueryable, and "how many tasks were completed late and then reopened" becomes a string search. A log is what happened; the row is what is true. Reopen clears the live `completed_at`, since a row claiming a completion date while In progress contradicts itself |
| 2-12 | DESIGN.md §5.19 ("nothing routes a flash through the toaster") gains a sanctioned exception: a screen whose every write is a server round trip may claim the channel with `useFlashAsToast()`, which **moves** the flash rather than copying it | The task detail answers every write — including a refused transition — with `back()->with()`. The rule's intent is "announce once", not "never as a toast", and the collision was real: the first run showed the alert and the toast saying the same thing |
| 2-13 | The assignee list is filtered by holding `tasks.view`, not by role name or an exclusion list | The Accountant was being offered as an assignee for tasks they cannot open. A permission check means a future role that can carry tasks appears with no code change, and one that cannot never does — verified by moving the permission in both directions |
| 2-14 | The Tasks list tag filter shows globals plus the tags of every project `Project::visibleTo()` returns; a single task's picker shows only its own project's plus globals | Two different questions. The filter must cover every label a visible row could wear, or it hides work the list is showing; the picker must cover only what may legally go on that task. Sending every tag — which is what `index()` did — leaked other clients' project vocabulary through tag names on the Employee surface |
| 2-15 | **Follow-up:** a cross-project move drops the task's foreign *tags* but leaves its *dependencies*, so a moved task can still be blocked by a task in its former project | `addDependency()` refuses a cross-project pair, so the move can produce a state the validation exists to prevent. Found while building the move dialog; it needs a decision about whether the move detaches them, refuses, or warns |
