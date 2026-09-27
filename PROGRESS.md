# GoodTechies HQ — Build Progress

> Source of truth: `docs/master-prompt-v1.md` (v1.2), condensed from the client spec *GoodTechies HQ — Agency Operating System v1.0 (Sept 2026)* + the client's "Application Visuals" design doc + current ClickUp workspace (`docs/design-refs/`).
> Updated at the end of every phase and after every gate. A new session must be able to continue from this file alone.

**Last updated:** 26 Sep 2026 · **Current phase:** 12 — Admin tools ✅ **complete** (all admin screens, the ClickUp importer, the security and performance passes, and the UX polish pass) · **Status:** **there is nothing left to build.** **GATE D** and **GATE E** are both open and both yours; GATE A and GATE B questions still open; Phase 11 is blocked on spec §46; the cutover itself needs your ClickUp export
**Test suite:** `php vendor/bin/pest` → **2776 / 2776 passed** (run it in the nine parts `AGENTS.md` lists — one process times out) · **Deployed on VPS:** no. The deploy kit passed in a fresh Ubuntu 24.04 container; see the deployment log. · **Execution method:** dispatch v1.5.1

> **This header and the table below had gone four phases stale** (they still said "Phase 0.5, 343 tests, waiting for GATE B" on 25 Sep, with Phases 7-9 marked unbuilt). The per-phase write-ups further down were correct throughout. Both are now current. `CLAUDE.md` says a new session must be able to continue from this file alone — that is only true if the top of it is true, so **update the header and the table in the same commit as the write-up**, not afterwards.

---

## Overall progress

**Phases 0-10 and 12 complete — GATE D and GATE E open; Phase 11 blocked on the client (≈ 95 %)**

`[██████████████████████████████  ]`

| Phase | Vertical slice | Gate | Status |
| --- | --- | --- | --- |
| 0 | Foundation: auth + 2FA, roles, three shells, audit/activity logs, CI, VPS deploy kit, backups | **GATE A** | ✅ built (gate questions still open) |
| 1 | Clients & Projects with the privacy model | **GATE B** | 🔄 built — waiting for GATE B |
| 0.5 | Design Foundation: brand tokens from the logo, the app shell, 8 base components, charts, every Phase 0/1 screen migrated | — | ✅ complete |
| 2 | Tasks: List (grouped) · Board · Calendar · tags · files · task discussion · in-app notifications | — | ✅ complete |
| 3 | Recurring task engine + fixed automation rules | — | ✅ complete |
| 4 | Remote timer + Timesheet + office attendance + schedules + workload | **GATE C** | ✅ complete — **GATE C passed 25 Sep** |
| 5 | Leave management + holidays | — | ✅ complete |
| 6 | Team communication (team/project chat, DMs, announcements, voice, Team directory) + realtime | **GATE D** | ✅ complete — **GATE D open** |
| 7 | Meetings + Google Meet (one-way) + action items → tasks | — | ✅ complete, except the Google Calendar API driver (blocked on GATE A) |
| 8 | Finance module + Accountant surface | — | ✅ complete |
| 9 | Payroll state machine + payslips | **GATE E** | ✅ complete — **GATE E open** |
| 10 | Reports + global search + Gantt view + dashboards finalized | — | ✅ complete |
| 11 | Activity-tracking browser extension (blocked until the client amends spec §46) | **GATE F** | ⬜ blocked |
| 12 | Admin tools (Users & Roles, audit viewer, settings, notification defaults), backup health, UX polish pass, migration import, hardening, cutover | **Final review** | ✅ built — the cutover *run* needs the client's export |

---

## How to run locally

Full steps are in `docs/runbooks/local-setup.md`.

- **Requirements:** PHP 8.3+ (pdo_pgsql, redis, intl, zip, gd, bcmath), Composer 2, Node 22, PostgreSQL 16, Redis 7.
- **Database:**
  1. Create `goodtechies_hq` and `goodtechies_hq_test`.
  2. For each one, as a superuser, run `psql -v db=<name> -v migrator_password=… -v app_password=… -v ro_password=… -f deploy/sql/roles.sql`.
- **`.env` keys:** copy `.env.example`, then fill in:
  - `DB_PASSWORD` (hq_app), `DB_MIGRATOR_PASSWORD`, `DB_RO_PASSWORD`
  - `SEED_PASSWORD`
  - `SEED_TWO_FACTOR_SECRET` (local only; any base32 secret, which you add to your authenticator app)
- **Build and run:**
  1. `composer install && npm install && php artisan key:generate`
  2. `php artisan migrate:fresh --seed --database=pgsql_migrator`. The schema is owned by `hq_migrator`; the app runs as `hq_app`.
  3. `npm run build` (or `npm run dev`), then `php artisan serve`.
- **Demo data:** `DemoSeeder` adds 4 clients and 7 projects (Buffalo Modular ×3, Heat Gap, APH — on hold and overdue, abc.com, and one internal project). Tapu is on the two SEO projects, Yaseen on the three maintenance projects plus the internal one.
- **Seeded logins:** `shahadat@goodtechies.test`, `faruk@goodtechies.test` (Admin), `tapu@goodtechies.test` (Remote), `yaseen@goodtechies.test` (Employee), `accountant@goodtechies.test` (Accountant).
  - Password: `SEED_PASSWORD`.
  - Admins and the Accountant also need a TOTP code from `SEED_TWO_FACTOR_SECRET` (local/testing only; production forces enrolment at first login).
- **Tests:**
  - **`php vendor/bin/pest`** runs everything (**2320**). Not `php artisan test` — it runs in
    parallel here and deadlocks on migration DDL. One process exceeds ten minutes, so
    `AGENTS.md` lists the six parts to run and add up.
  - `php vendor/bin/pest --group=phase0`, `--group=phase1` and `--group=permissions` run subsets.
- **Checks:** `vendor/bin/pint --test`, `npx vue-tsc --noEmit`.
- **Deploy kit test:** `deploy/test/run-install-test.sh` (needs Docker).

## Decisions confirmed with the client (GATE A)

Defaults from master prompt Part H §2; the "Confirmed on" column stays empty until the client answers.

| Topic | Value | Confirmed on |
| --- | --- | --- |
| Laravel major | *(default: current supported major, 12 or 13 — spec says 11, out of security support)* | — |
| App timezone (`timezone`) | *(default `Asia/Dhaka`)* | — |
| Currency (`currency`) | *(default USD, from the spec's examples)* | — |
| Late grace (`late_grace_minutes`) | *(default 15)* | — |
| Half-day auto (`half_day_auto`) | *(default off)* | — |
| Heartbeat timeout (`heartbeat_timeout_minutes`) | *(default 5 → stop at last heartbeat + flag)* | — |
| Timer max session (`timer_max_session_hours`) | *(default 10 → pause + flag)* | — |
| Manual time approval (`manual_time_requires_approval`) | *(default on)* | — |
| Notification group window (`notification_group_window_minutes`) | *(default 2)* | — |
| Payslip format | *(default browser print view + PDF via dompdf)* | — |
| Realtime driver (`.env`) | *(default `reverb`, `polling` fallback)* | — |
| Google Calendar driver (`.env`) | *(default `manual` until a Workspace account is provided)* | — |
| Object storage | *(S3-compatible bucket; MinIO only with versioning + off-box replication)* | — |
| Backup destination | *(a bucket in a different provider account or region)* | — |
| Idle pause threshold (`idle_pause_minutes`, Phase 11) | *(default 5)* | — |
| Idle flag threshold (`idle_flag_percent`, Phase 11) | *(default 25 %)* | — |
| Video-only rule + `<all_urls>` content script (Phase 11) | *(default: only `<video>` excuses idleness; install warning documented)* | — |
| Spec §46 amendment for Phase 11 (activity extension) | **not yet — Phase 11 blocked** | — |

---

## Phase 0 — Foundation 🔄 (built, waiting for GATE A)

### What you can click now
| Screen / action | Where | Notes |
| --- | --- | --- |
| Sign in | `/login` | Rate-limited (5 per minute); inactive accounts are refused |
| Two-factor check | `/two-factor/challenge` | 6-digit code or recovery code; replayed codes are rejected |
| Forced 2FA set-up (Admin, Accountant) | `/two-factor/enrol` → `/two-factor/recovery-codes` | QR code + manual key; 8 recovery codes shown once, with copy and download |
| Company dashboard (Admin) | `/admin/dashboard` | Greeting + date. "Active employees" is a real count; the other cards say "Arrives in Phase N" |
| Settings, read-only (Admin) | `/admin/settings` | Every settings key, the drivers from `.env`, and the **Backup health** card |
| Employee dashboard | `/employee/dashboard` | The five task cards (Phase 2) plus a role card: Time for Tapu, Attendance for Yaseen |
| Accountant dashboard | `/accountant/dashboard` | Separate shell: FINANCE (Income, Expenses, Payroll, Financial Reports) and ME (My Leave, My Payslip) |
| Profile | `/profile` (user menu) | Name, email, timezone, password change, 2FA on/off (off is refused for Admin/Accountant), recovery codes, active sessions with sign-out, last 20 sign-ins |
| Three shells | all pages | Navy sidebar at ≥ 1024 px, drawer below that; unbuilt items are disabled with a "P{N}" badge. Checked at 375 / 768 / 1280 |

### Built (files)
- **Database:**
  - `deploy/sql/roles.sql` creates three roles (hq_migrator owner with CREATEDB, hq_app runtime, hq_ro read-only).
  - Migrations: users (2FA columns), roles, permissions, role_permissions, user_project_permissions, employees, schedules, audit_logs (UPDATE/DELETE/TRUNCATE revoked from hq_app), activity_logs, settings, login_history, sessions.
  - Connections `pgsql`, `pgsql_migrator`, `pgsql_ro`, with the DB timezone set.
- **Seeders:** `RolePermissionSeeder::MATRIX` (Part C §1), `SettingsSeeder::DEFAULTS` (all 11 keys), `TeamSeeder` (the 5 real users).
- **Enums** (`app/Support`): Permission (23 dotted keys), RoleName, TrackingMode, UserStatus, AuditEvent, Surface.
- **Services** (`app/Services`): AuditLogger (the only writer), ActivityLogger, SettingsService, EmployeeAdministrationService (self-guard on role change and deactivation), TwoFactorService, SessionService.
  - Login recording listeners write login_history, last_login_at and the `user.login` audit row.
- **Middleware:** `EnsureSurface` (`surface:*`) and `EnsureTwoFactorEnrolled` (`two-factor`), one gate per permission, the policy base and `EmployeePolicy`.
- **HTTP:** `routes/{auth,admin,employee,accountant,shared}.php`, controllers under `app/Http/Controllers/{Auth,Admin,Employee,Accountant,Shared}`, Form Requests, and Inertia shared props (`auth.user` limited to 7 keys).
- **UI:**
  - `resources/css/app.css` (spec palette as shadcn tokens, light and dark), `components.json`, shadcn-vue primitives in `Components/ui`.
  - `AuthLayout` and the 4 auth pages; `AdminLayout`, `EmployeeLayout`, `AccountantLayout` (three separate files) with `Components/Shell/*` and `navigation/*.ts`.
  - The dashboards, Settings and Profile pages.
- **Ops:**
  - `deploy/`: nginx.conf, the Supervisor `hq-queue` and `hq-reverb` programs (reverb disabled until Phase 6), cron, `install.sh`, `deploy.sh`, `.env.production.example`, `test/` container harness.
  - `config/backup.php`: encrypted, retention 14/8/12, destination disk `backups` (an off-provider S3 bucket).
  - `hq:verify-backup`, and the schedule in `routes/console.php` (backup:clean 01:30, backup:run 02:00, backup:monitor 03:00, verify on Sundays 04:00).
  - `.github/workflows/ci.yml`.
- **Docs:**
  - `AGENTS.md`, `DESIGN.md`, `PROJECT_BRIEF.md`, `docs/decisions.md`.
  - `docs/runbooks/{local-setup,install,deploy,restore-from-backup}.md` and `install-log-2026-09-17.md`.

### Tests — `php artisan test` → 167 / 167 passed (1218 assertions)
- **Auth:**
  - Login: success, failure, unknown email, inactive account, 429 rate limit, audit row.
  - 2FA challenge: valid code, invalid code, recovery code, replay, throttle.
  - Forced enrolment for ADMIN/ACCOUNTANT, optional for others.
- **Surfaces:** each role lands in its own shell, and no secret keys appear in any Inertia payload.
- **Permission matrix** (`tests/Permissions/MatrixTest.php`, 19 routes × 6 roles), with a route-coverage guard that fails when a route has no row.
- **Guards:** changing or deactivating your own account is refused.
- **Audit rows:** login, role change, settings change, 2FA disable.
- **Database:** `audit_logs` is append-only (UPDATE, DELETE and TRUNCATE as hq_app give 42501); grants are checked for all three roles.
- **Services:** activity log writes, session revoke, breach check (HIBP faked), every settings key seeded with its default.
- **Backups:** the verify command end to end (real pg_dump and restore into a scratch DB), plus failure cases (no backup, wrong password, corrupt zip).
- **Schedule:** registration of all four jobs.
- **Checks:** `pint --test`, `vue-tsc`, `npm run build` all green.
- **Verify:** the security critic ran on the whole Phase 0 diff (see Known issues).

### Decisions made in Phase 0
See `docs/decisions.md` (0-1 … 0-20). The ones to confirm at GATE A:
- **0-1:** Laravel 13.
- **0-6:** a Sun–Thu working week.
- **0-7:** seeded email addresses.
- **0-10:** Node 22.

### Known issues / notes
- **Security finding, medium (not fixed yet):** a **deactivated** user who still has a "remember me" cookie can sign back in automatically and reach `/profile*` and `/two-factor/*`.
  - Why: those groups don't check `isActive()`, and `deactivate()` does not rotate `remember_token`. Surface routes (`/admin`, `/employee`, `/accountant`) are safe.
  - Proposed fix brief: rotate `remember_token` on deactivation, and add an active-user check to every authenticated group, with tests.
  - **Needs your go-ahead.** The dispatch rule is that critic findings are not auto-fixed.
- **Lower-risk notes from the critic** (plausible, not confirmed defects):
  - The login error tells an inactive account apart from bad credentials, which only matters if the attacker already has the password.
  - An unknown email answers slightly faster than a wrong password.
  - The rate-limit key (email + IP) can be sidestepped by rotating IPs.
  - The session is regenerated only after the 2FA step, not between the password and 2FA steps.
- **Not verified:**
  - **install.sh branches:** the systemd path, ufw and Certbot were not run (the container has no systemd), nor were the Ubuntu 22.04 branches (ondrej PPA, PGDG repo).
  - **CI:** the workflow has never run on GitHub; the repo has no remote yet.
  - **Clicks:** the mobile drawer, user menu, Profile dialogs and the RecoveryCodes page were checked by reading the code, not by clicking.
  - **Restore runbook:** its `7z` command was not run.
- **Tokens:** the shadcn-tokens MCP and the shadcn-vue MCP were not available in this build session; tokens were written by hand (decision 0-8).
- **Seed data:** the seeded 2FA recovery codes are random and unknown by design. For local work, use `SEED_TWO_FACTOR_SECRET`.

### Questions for the client (ask at GATE A)
1. Confirm every row of the decisions table above (Laravel major, timezone, currency, attendance and timer thresholds, drivers, storage/backup destination).
2. VPS details: provider, OS version, domain name, who holds DNS, whether an S3-compatible bucket already exists, and a **second** account/region for backups.
3. Google Calendar: does GoodTechies have a Google Workspace account? If yes: per-organizer OAuth or a service account with domain-wide delegation? If no: MVP stays on manual Meet links.
4. **Spec §46 vs. activity tracking:** the spec forbids "hidden monitoring"; the requested extension tracks active/idle/video state (no content, no screenshots, no score) and needs a content script on all sites (Chrome shows an install warning). Please confirm in writing that this is wanted so Phase 11 can be unblocked.
5. ClickUp export: can the client export the "PROJECTS" list (tasks + subtasks + time tracked) as CSV for the Phase 12 import?
6. Employees' salary currency and the BD public-holiday list for the current year (seed for Phase 5).
7. Office working week and hours: is Sun–Thu, 09:00, 8 h right (Tapu 5 h)? Or Sat–Thu?
8. Real email addresses for the five accounts (the seeds use `@goodtechies.test` until then).


## Phase 1 — Clients & Projects 🔄 (built, waiting for GATE B)

### What you can click now
| Screen / action | Where | Notes |
| --- | --- | --- |
| Clients list | `/admin/clients` | Search, status filter, project counts, row actions (View, Edit, Deactivate with a confirm) |
| Client detail | `/admin/clients/{id}` | Contacts, internal notes, the client's projects with their money line, and an activity timeline |
| New / edit client | `/admin/clients/create`, `/edit` | Repeatable contacts editor (up to 10), internal notes |
| Projects list | `/admin/projects` | Search plus client / type / status / PM filters and a "Show archived" toggle; overdue deadlines and Urgent priority stand out |
| New / edit project | `/admin/projects/create`, `/edit` | Client (or Internal), domain, type, billing type, priority, PM, dates, both note fields; members and finance can be set while creating |
| Project detail | `/admin/projects/{id}` | Tabs: Overview, Finance (edit in place), Members (add/remove + role), Activity, plus disabled Tasks and Files tabs for Phase 2. Change status (a cancel asks for a reason), Archive / Unarchive |
| Employee projects | `/employee/projects` | Only the projects that person is on, as cards headed by the **domain** — no client name, no money anywhere |
| Employee project page | `/employee/projects/{id}` | Domain, type, status, priority, dates, PM, team, the team's notes, and Phase 2 placeholders for Tasks and Files |

### Built (files)
- **Database:** `clients` (contacts **encrypted at rest**), `projects`, `project_finance` (money in its own table, so hiding it is a join to omit), `project_members`, and the deferred foreign key on `user_project_permissions.project_id`.
- **Enums:** `ClientStatus`, `ProjectType`, `BillingType`, `BillingFrequency`, `ProjectStatus`, `Priority`.
- **Privacy layer:** `ProjectResource` and `ClientResource` decide every field from the requester — a field they may not see is **absent**, never null. `ProjectPolicy`, `ClientPolicy`, and `Project::visibleTo()` scoping every list.
- **Services:** `ClientService`, `ProjectService` (create, update, members, status transitions, archive/unarchive), `ProjectFinanceService` (audited price changes).
- **HTTP:** `Admin/ClientController`, `Admin/Project{,Finance,Member,Status}Controller`, `Employee/ProjectController`, and seven Form Requests.
- **UI:** the Admin client and project screens, the Employee project screens, and the shared list blocks `FilterBar`, `Pagination`, `EmptyState`, `StatusPill`.

### Tests — `php artisan test` → 343 / 343 passed (2252 assertions); `--group=phase1` → 166
- **Privacy (the Part F §2 negative suite):** an employee's payload is walked recursively on all four project endpoints and contains no `client`, `internal_notes`, `billing_type`, `finance`, `price`, `recurring_amount`, `contract_value`, `contract_terms` or `profitability_snapshot` key; the admin's does.
- **Scoping:** a project an employee is not on returns **404**, never 403; the Accountant is refused on all 20 client and project routes.
- **Per-project grant:** a `projects.view_finance` grant reveals the money for that one project and no other.
- **Audit:** one `project.created` row per project and one `project.price_changed` row per price change, with old and new values.
- **Rules:** every allowed and forbidden status transition, archived projects refusing writes, cancel requiring a reason, members add/remove.
- **Encryption:** the raw `contact_info` column is ciphertext, and cascades on delete behave.
- **Matrix:** every new route × six roles, with the route-coverage guard still green.

### Decisions made in Phase 1
`docs/decisions.md` 1-1 … 1-10. The ones worth knowing: internal projects have no client row (1-1); status changes only through their own endpoints (1-2); money shows as USD until a currency setting is wired in (1-5).

### Known issues / notes
- **Fixed during the phase:** the security critic found that the general project-update endpoint accepted `status`, letting an assigned manager cancel or reopen a project and leave a project "archived" but still writable. Status now moves only through its own endpoints, with regression tests.
- **Follow-ups parked:** no "Internal only" option in the client filter (1-7); a member's role is set on the project page, not in the create form (1-8); the "You" badge matches on name (1-10).
- **Not clicked:** dialogs, tab bodies and form submissions were checked by their tests, not by clicking — the render script cannot click. Every screen was rendered and measured at 375 / 768 / 1280.

### Questions for the client (ask at GATE B)
1. Client contacts: is one contact per client enough, or do you want several with roles (the editor supports up to 10)?
2. Project types: the list is SEO, Website Maintenance, Website Development, WooCommerce, Web Application, Marketing, Internal, Other — anything missing?
3. Should an employee see the project's **priority** (they do now), or is that internal?
4. Archiving: unarchiving returns a project to Active. Should it return to the status it had instead?
5. Is "Internal" the right label for projects with no client?

## Phase 0.5 — Design Foundation ✅ complete

Ran out of order, after Phase 1 rather than before it, because it was written after Phase 1 was
already built. Spec: `docs/design-foundation-v1.md` v1.1, whose §0 status board is the
task-by-task record with a commit SHA per task.

### What changed for you

- **The app is the client's brand now.** Every colour is derived from
  `docs/design-refs/GoodTechies siteicon  1.svg` — `#F04E27` and `#0A0D12` — instead of the
  hand-picked navy and teal Phase 0 used while the token server was unreachable. The mark in
  the sidebar is the real SVG, and the favicon set is generated from it.
- **A real sidebar.** Nav rows are grouped into collapsible sections that remember whether you
  left them open; the rail collapses to icons; the 29 rows for phases that do not exist yet live
  in one closed *Coming soon* list at the bottom instead of cluttering the real navigation.
- **A top bar that tells you where you are** — a breadcrumb derived from the URL — and four
  things you reach from anywhere: `⌘K` search, quick create, the notification bell, your account.
- **Dark mode**, as a real second theme rather than a filter. Light / Dark / System is in the
  account menu and it survives a reload.
- **Every list is one component now.** `DataTable` gives them the same header, the same row
  menu, the same empty state, and a card layout on a phone instead of a sideways scroll.
- **Empty is no longer broken.** A panel with nothing in it says what will go there and when;
  a filter with no matches says so and offers to clear itself; slow pages show a skeleton.
- **Charts exist**, ready for the phases that have numbers to put in them.

### Verification

`npm run build`, `npx vue-tsc --noEmit`, `vendor/bin/pint --test` and `php artisan test`
(343 / 343, 2252 assertions) all green. Phase 0.5 changed no route, controller, model or
migration, so the suite is the Phase 1 suite unchanged — which is the point.

Beyond that, the close-out ran the three passes the spec's §6 demanded, against the running app:

- **Light and dark** on Login, the 2FA challenge, all three dashboards, Profile and Settings —
  plus a script that measured every visible run of text against its real composited backdrop.
  Zero below the AA threshold.
- **Keyboard only.** A 26-stop walk of each shell: every stop paints a visible focus ring, every
  overlay returns focus to whatever opened it, and nothing is reachable by mouse but not by key.
- **360 px** on all three shells: no page overflows, no tap targets overlap, no text is clipped.

Those passes found six defects, all now fixed (see below). `DESIGN.md` was regenerated from
`app.css` with 52 measured contrast pairs and is the file to read before writing any Tailwind
class.

### Decisions made in Phase 0.5
`docs/decisions.md` 0.5-1 … 0.5-30. The ones worth knowing:

- **0.5-1** The shell is light neutral, not dark — your choice; the app should not be the
  loudest thing on a screen it shares with a browser and a document.
- **0.5-2** The brand values are read out of the logo SVG. Closed — there is nothing left to
  sample.
- **0.5-22** `#F04E27` measures 3.50:1 against the canvas, which is below the readability floor,
  so it is split in two: `--brand` for graphics (the rail, the mark, the focus ring) and
  `--primary`, two steps darker, for anything with text on it. **This split must not be
  collapsed later** — it is the reason no button in the app is exactly the logo's orange.
- **0.5-4** Charts are unovis — your choice. Phase 10's Gantt is a custom component, not a chart.
- **0.5-5** The employee timer is a dashboard hero card — your choice.
- **0.5-6** Tasks default to a List grouped by status — your choice. This settles Phase 2's
  first build: a grouped `DataTable`, with the Board as a second view over the same data.
- **0.5-7 — still open.** There is no wordmark in the artwork you supplied, so the lockup is the
  mark plus "GoodTechies HQ" set in Inter Semibold. **If a real wordmark is coming, say so and
  it gets swapped in one file.**
- **0.5-16 / 0.5-19** `DataTable` ships sorting and rows-per-page, but they stay switched off
  until a controller can actually honour them. A control the server ignores is a lie in the UI.
- **0.5-11** is marked superseded: T1 left the Phase 0 navy in place for T2 to decide, and T2
  replaced it.

### Known issues / notes

- **Fixed at close-out**, found by the keyboard and 360 px passes, not by reading code:
  - On the two-factor screen, `Tab` could not get out of the six-digit code field, so a
    keyboard-only admin who had lost their phone could never reach **Use a recovery code** — the
    one control that gets them back in. The six boxes are now a single tab stop.
  - The same screen did not focus the code field when you arrived from the login form.
  - On a phone, the Login history on your Profile scrolled sideways and hid the IP and Device
    columns. It now stacks into cards like every other list.
  - An overdue deadline was red and nothing else, in three places — invisible to a screen reader
    and to anyone who does not see red. It now says **Overdue**.
  - There was no skip link, so reaching the page body meant 8–14 tab presses on every screen,
    reset by every navigation.
  - Two small ones: the command palette's filter had no focus ring, and the accountant's one
    button was greyed out with its reason hidden in a tooltip.
- **Not verified:** `DetailDrawer` is built but has no caller yet — no list opens a row — so the
  spec's `DataTable → DetailDrawer → Esc` hop could not be exercised. The underlying sheet
  primitive was tested through the mobile navigation instead. `DataTable`'s sorting, selection
  and bulk bar are likewise built but dark until a controller supports them (0.5-16, 0.5-19),
  and the charts render only their empty states because no phase has produced numbers yet.
- **Parked for a later phase:** Laravel's own error pages (403, 429, 500) are unthemed and flash
  white in dark mode. They belong to whichever phase owns error handling, not to this one.
- **Numbering repair:** the decisions log had ten rows sitting outside its table and four
  numbers that meant two different things. Repaired — it now runs 0.5-1 … 0.5-30 with no
  duplicate and no gap, and the spec's §0 board records how.

## Phase 2 — Tasks ✅ complete

Built as five vertical slices. All five are done; the phase close-out remains.

**Phase 2 has no gate** — the phase table in `docs/master-prompt-v1.md` (line 558) puts GATE C at the
end of **Phase 4**, not here. So the close-out is followed by Phase 3, not by a stop.

| Slice | What it is | State |
| --- | --- | --- |
| 1 | Tasks List, grouped by status, filters, both surfaces | done |
| 2 | Task detail, create/edit, the status machine | done |
| 3 | Board (drag between lanes, manual order) and Calendar | done |
| 4 | Tags, files, task discussion — backend and screens | done |
| 5 | Notifications, My Tasks, real dashboard task cards, `hq:flag-overdue` | done |

**Slice 4, in one paragraph.** Attachments hang off tasks, projects and clients through one
`files` table with an exclusive-arc CHECK, versioned by `superseded_at` + `version_of` with a
partial unique index that makes two current versions impossible. A file's link is signed *into
the application* and re-checked by `FilePolicy` on every fetch, so forwarding one gives 404 to
somebody who may not see the owning record (2-25). Tags carry a status-token name rather than a
hex colour (2-22). A task's discussion is one conversation per task, with membership computed
from `TaskPolicy::view` rather than stored (2-24). On screen: a Files tab on project and client
detail, an attachments panel and a Discussion panel on task detail (page mount and drawer mount,
both surfaces), and a tag manager beside the filter chips.

Five defects were found by review rather than by tests, and fixed in the same slice: a 422 that
leaked the existence of an invisible tag (2-27), a permission derived from a role in a Vue file
(2-28), a missing `permissions` block that forced a hard-coded `true` (2-29), file version
history that was unreachable and a payload key that could never be populated (2-26), and an
upload-refusal check that read a flash bag another component had already emptied.

**Slice 5, in one paragraph.** One engine: event → recipients → dedup/group → row. Recipients
pass two filters and neither names a role — the type's required permission, and `view` on the
object itself (2-31), so the Accountant receives nothing because they hold no `tasks.*` key.
Grouping happens at dispatch, as the spec insists: twelve comments inside
`settings.notification_group_window_minutes` are one row written with `count: 12`, proved end to
end through twelve real `ConversationService::post()` calls. `hq:flag-overdue` runs daily at
08:00 and only *sends* — the overdue buckets stay query-time. The bell polls every 15s and stops
when nobody is looking (2-39); the Notification Center is the same route that answers the bell's
JSON (2-41). `TaskBucket` is the single statement of each bucket predicate, so a dashboard card's
count and the list it opens are the same `where` (2-37). And the cancel-project side effect Phase
1 could only record as a sentence is now real: it *prompts* the PM and every Admin to close or
reassign the open tasks, and closes nothing itself (2-36).

**Client change requests** from 22 Sep are logged as C-1…C-7 in `docs/decisions.md`: the product
is **goodERP**, the Board is the default Tasks view, and the board-scroll work was built and then
withdrawn at the client's request (kept in history at `1148417`).

**Tests: 922 passing, 4982 assertions.** Baseline recorded in `AGENTS.md`.

### Open follow-ups from Phase 2

| # | What |
| --- | --- |
| 2-5 | Review and Waiting are hard to tell apart under deuteranopia |
| 2-8 | *(closed in slice 2 — the assignee filter shipped with its option list)* |
| 2-15 | A cross-project move drops foreign tags but leaves dependencies |
| 2-20 | `can_review` resolves per row — a gate call per card on List, Board and Calendar |
| 2-21 | An Admin board card carries the whole project finance fragment to print a name |
| 2-30 | The task detail payload ships an `attachments` array no screen reads |

## Phase 3 — Recurring tasks ✅ complete

**Goal (from the plan):** monthly retainer work generates itself with zero manual re-creation,
for at least two simulated cycles.

Engine and screens both built.

- `recurring_tasks` and `recurring_generation_log`; `tasks.recurring_template_id` renamed to the
  spec's own `recurring_task_id` (every value was still NULL) and given the partial unique index
  that *is* the duplicate prevention (3-1).
- `hq:generate-recurring-tasks` at 00:05 and `hq:notify-due-tomorrow` at 08:00, both
  `withoutOverlapping()` and both asserted in `ScheduleTest`.
- Monthly, weekly and custom rules, one period-key function (3-3). Time-travelling two
  consecutive months produces exactly two instances; running the scheduler twice in one day
  produces one task and one warning row.
- Three seeded templates: abc.com Monthly Maintenance (Yaseen, the spec's 8-item checklist),
  Heat Gap Monthly SEO (Tapu), Buffalo Modular Monthly SEO (Tapu). The seeder creates templates
  and generates nothing — a pre-generated month would be a second creation path.

**The screens.** A Recurring tab on admin project detail: the project's templates with their
recurrence in words, next run and last outcome; a create/edit dialog whose recurrence editor
sends `frequency` plus that frequency's parameters and lets one server-side `match` pick the
rule (3-9), with the next-run preview coming back from the server rather than from date maths
in Vue; "Generate now" calling the engine's own `force: true` entry point; and the generation
log with its duplicate warnings spelled as sentences. Task detail says which template made it
and for which period, on both surfaces and both mounts. Six Admin routes, all 403 for everyone
else, with 404 reserved for the record (3-8).

**Three Phase 2 follow-ups closed at the same time.** A handled review notification now resolves
— `resolved_at` is a *separate* column from `is_read`, because collapsing them would have broken
the dedup rule and told a reviewer the same thing twice (2-52, 2-53). The reviewer's reason
reaches the assignee's notification (2-54). And the Admin dashboard's attention panel and
"Tasks by status" donut are fed from real, scoped queries instead of showing an empty state under
cards counting six overdue.

**Tests: 1184 passing, 6280 assertions.**

## Phase 4 — Time & attendance ✅ complete (GATE C passed)

**Goal (from the plan):** Tapu's day is timer-tracked per task with the 5h target visible to him
and Admin; Yaseen and both Admins clock in/out; workload view exists. **Phase 4 ends at GATE C.**

| Slice | What it is | State |
| --- | --- | --- |
| 1 | Remote timer, `time_entries`, watchdog, offline replay, the Time page | done |
| 2 | Office attendance, schedules, roster, month grid, `hq:mark-absent` | done |
| 3 | Timesheet grid, Workload, the admin Time approval queue, dashboard cards | done |

**The timer.** Start / pause / resume / stop with the arithmetic tested to the second, one open
timer per employee guaranteed by a partial unique index rather than an `if` (4-2), and the
session cached in `localStorage` so a closed laptop loses nothing. On reconnect the batch
replays: only the heartbeats count as evidence, nothing increments, and the same batch landing
five times leaves the row unchanged (4-3). `hq:timer-watchdog` runs every minute — a silent
timer is stopped **at its last heartbeat** and flagged with a sentence a person can read; an
overlong one is paused and flagged once, not once a minute (4-4, 4-6). Office roles get no timer
UI and 403 from every endpoint, and the spec's grep test for "score"/"productivity" ships.

**Attendance.** Clock in and out from the dashboard, with Present / Late / Half day derived from
*that employee's* schedule and `late_grace_minutes` — never a constant. `hq:mark-absent` at 23:55
marks a missed working day and leaves a Friday alone, and Phase 5's leave and holiday skip plugs
into one method that returns false today (4-12). Tapu can never be marked Absent, structurally
(4-11). The Admin roster, the per-employee month grid and the schedule editor are built; an edit
needs a reason and is audit-logged with old and new values.

**The employee dashboard now shows the right hero** — the timer's figures for Tapu, the clock for
Yaseen — decided on the server from `tracking_mode`. Until this slice both saw the same disabled
button reading "Arrives in Phase 4".

**Tests: 1363 passing, 7317 assertions.**

**Slice 3.** Admin → Workforce → **Time** is the approval queue, ordered by date and never by
size or person, each row saying who, when, how long and *why* it is waiting — added by hand,
edited after the fact, or flagged by the watchdog with its own sentence. Approving makes the
hours count; rejecting keeps the row, its hours and the reason, and the employee's Time page
says "Turned down: …" (4-18). That closes 4-16, the thing that would have bitten at GATE C.

**`daily_work_summary`** is a view, not a table, as Part C rule 6 insists: a full outer join of
one CTE per source, so neither the office day nor the remote day can disappear (4-19). The
roster now reads "Tapu — Remote 4h 18m tracked", and it costs one query per roster rather than
one per row (4-20). The Company dashboard carries Present today, Absent, and AC2's
"Tapu 4h 18m / 5h" verbatim; On leave still names Phase 5.

**Timesheet** is a pure view over `time_entries`, with the week starting on the first working
day of *that employee's* schedule — never Monday by default, and there is a test with a Tue–Thu
schedule that a hard-coded Monday would fail (4-22). **Workload** is counts only: estimated and
tracked sit side by side, never divided, never sorted, people ordered by name, because a ratio
or a league table is the productivity score Part H forbids (4-23).

**Also fixed here, found by reading rather than measuring:** the admin Attendance and Schedule
pages shipped with **no layout at all** — no sidebar, no top bar, no skip link. Every
accessibility measurement on them had passed, because a page with no shell has nothing to
overflow and almost nothing to tab through (4-26).

## Phase 5 — Leave & holidays ✅ complete

**Goal (from the plan):** apply → approve → calendar/attendance/payroll-impact flow with
balances; company holidays. Built as two independent halves in parallel.

**Holidays.** A `holidays` table the Admin edits at Workforce → Holidays, seeded with 21
Bangladesh public holidays for 2026 as a *starting point* — which answers the question that
looked like a GATE A blocker. Fifteen of those are lunar and move each year, so each row carries
its certainty and the seeder prints the moving ones by name when it runs (5-5). The seeder plants
a year only if that year is empty, so a holiday the Admin deleted is not resurrected by the
launcher's nightly `db:seed` (5-4). A holiday is **derived, never stored** — adding one changes
what a past day shows, and the CHECK now refuses a stored one outright (5-1).

**Leave.** Three tables, `LeaveService`, and the whole flow: Apply Leave and My Leave on every
shell including the Accountant's, the Admin queue with Approve / Reject / Request Correction, the
balances editor, and the leave calendar. Approval is one transaction: status through the machine,
balance decremented, `unpaid_days` stored for Phase 9, attendance auto-marked Leave for each of
*that employee's* working days, and the task flag appears — no manual step, which is the
acceptance sentence. Overlapping requests are refused by a **database exclusion constraint**, not
a check in a service (5-7), and the status is guarded at the model with no escape hatch (5-8).

A task whose assignee is on leave is **flagged, never reassigned** — one `addSelect` in the single
query funnel, so every view gets it at no extra cost (5-11).

**A side effect worth knowing:** the Accountant now has a notification mailbox, because
*apply for own leave* is a key every role holds and nothing was carved out to give it to them
(5-14). Their `POST /notifications/{n}/read` moves from 403 to **404**, which is the privacy rule
getting stronger, and it closes follow-up 2-42 by itself.

**Also found and fixed:** two test files both defined `ENDPOINT_MONDAY`, and Pest declares a test
file's constants **globally**. Each file passed alone and the suite failed — a PHP warning, not an
error, so nothing stopped it (5-16).

**Tests: 1455 passing, 7903 assertions.** The suite now takes about 12 minutes, past a
ten-minute tool timeout; `AGENTS.md` records the two halves to run it in.

## Phase 6 — Communication & realtime ✅ complete (awaiting GATE D)

Built as two independent halves in parallel: the conversations and the transport.

**Conversations.** Phase 2's tables now carry all five types — `task`, `project`, `team`, `dm`,
`announcement` — and decision **2-24 generalised rather than bent**: nothing anywhere reads
`conversation_members` to decide who may do anything (6-1). The DM was the case that would have
broken it, and a schema choice stopped it: the pair lives in two ordered columns on
`conversations`, so a DM cannot grow a third person, "the DM between A and B" is a unique index,
and a stale member row on one still gets 404 (6-2).

`messages.use` is a new permission key. "The Accountant has no messaging" is now a rule about a
capability, not about a person — no policy, controller, route or Vue file contains the word
*Accountant*, and a test grants them the key by SQL and watches the page work (6-3).

**An ordinary channel message notifies nobody**; DMs, @mentions and announcements do. Fifteen
people with Messages open all day would stop reading a bell that fired per line, and that
silence is what makes an @mention worth typing (6-4). A mention suppresses the comment
notification for that same person, so one sentence is never two rows in one bell (6-5).

**Realtime.** Reverb and Echo, three private channels, each auth callback asking a policy that
already exists — a socket is a second door into the same rooms (6-7). A non-member gets 403 at
broadcast auth, proved on every channel. The bell is live and **keeps its poll** (2-39), because
the poll is now two things: the whole bell on a polling build, and the fallback while the socket
is down. A drop brings it back and the popover says so in words (6-9).

The polling fallback is a supported mode with the *same* payload assembly, and comparing the two
with `toBe()` caught a real bug: a broadcast has no request, so every deep link in the socket's
payload was silently null (6-8).

**Team directory** with today's availability from `AttendanceService::dayFor()` — no second
definition — ten keys, no salary, no tracking field, ordered alphabetically because an order is a
ranking the moment it is by anything a person could do better or worse (6-10).

**Deploy:** Reverb under Supervisor behind Nginx, bound to loopback, with a new
`deploy/test/run-reverb-test.sh` that opens a real websocket and publishes a real broadcast
through it — 22 checks, no Docker needed. It found a live bug on the way: Nginx's
`proxy_read_timeout` was 60s against Reverb's 60s ping, so every idle tab dropped about once a
minute with nothing logging an error.

**Also closed here: decision 5-18.** `daily_work_summary` now unions all four sources Part D §20
names. Leave had to be a FULL OUTER join, not a LEFT one — a remote employee's leave day has
neither an attendance row nor a time entry (5-9), so it would simply not have been in the view,
and Phase 9's payroll reads this view (6-14).

**Tests: 1585 passing.**

### Voice messages — 24 Sep 2026 ✅

The last thing Phase 6 owed. *"mic hold-to-record with waveform/timer/preview"* and *"playback
1×/1.5×/2×"*, on the tables Phase 2 already had — **no migration**.

What was actually missing turned out to be more than "a recorder": `FileService` accepted **no
audio at all**, so a recording was refused before it reached anything, and `StoreMessageRequest`
had no `kind` and no `duration`, so no HTTP caller could reach the two arguments
`MessageService::post()` had been carrying since Phase 6's core.

**Server.** A voice note is checked against its **own narrow list** (`VOICE_TYPES`), not the
application-wide one (6-20) — audio is uploadable as a voice note and nowhere else, and two tests
fail the day somebody merges them. The MIME pairings were **measured with real ffmpeg files, not
written from memory**, which caught the thing that would have broken this on the client's
machine: **an audio-only WebM is `video/webm` to finfo** (6-21), because finfo names the
container. 1–300 seconds, refused at the boundary and clamped at the write (6-23).

**Client.** `voice.ts` + `VoiceRecorder.vue` + `VoicePlayer.vue`. Hold-to-record **and**
click-to-latch on one button, split at 300 ms, so the plan's gesture exists without making the
control keyboard-hostile (6-24). Live waveform from a real `AnalyserNode`, a counting timer that
warns and stops itself at five minutes, and a preview you play, re-record, discard or send —
nothing uploads until Send. Every failure state is written out in words: permission refused, no
microphone, device busy, insecure page, recorder died, nothing recorded. Where `MediaRecorder`
is missing the control is **not drawn at all** (6-25).

**The microphone is released on all four exits** — stop, cancel, error, unmount — and *before*
the preview, not after sending. Proved by counting `MediaStreamTrack.stop()` calls **and**
asserting zero tracks still live, because either alone can lie (6-26).

Verified end to end in all four mounts: DM, team channel, task discussion, project Discussion
tab. Every speed sets `playbackRate`; starting a second note pauses the first; no horizontal
overflow at 360/375/768/1280 in both modes; every new contrast pair measured.

**One honest limitation shipped as a limitation** (6-28): the download route serves no byte
ranges, so a **sent** voice note cannot be scrubbed — the recorder's own preview can, because
that is a `blob:` URL. Rather than draw a slider the server ignores, the player **probes whether
a seek actually landed** and, when it did not, disables it and says *"(this one cannot be moved
through)"*. It re-enables itself the day ranges are served, with no client change.

Decisions 6-20…6-32. 37 new tests; suite **1668 passed**.

### Still to build before GATE D

- Nothing. Phase 6 is complete.
- Carried forward, not blocking: the **announcement banner app-wide** rather than on the Messages
  page only (6-18), which needs a prop in `HandleInertiaRequests` and the prop-shape tests that
  come with it.

### GATE D — your turn

Check **chat and voice on a phone and on a desktop**. Specifically the things that could not be
tested headlessly:

- the real microphone **permission prompt**, and the copy after you deny it;
- **iOS Safari** — whether `MediaRecorder` exists in your iOS version at all, whether it lands on
  the `audio/mp4` rung, and whether a hold gesture fights Safari's text-selection and callout;
- **hold-to-record with a thumb on glass**: 300 ms feels different from a synthetic mouse, and
  whether holding while the thread scrolls steals the pointer;
- the **microphone hardware light actually going out** after you send — the code proves
  `readyState === 'ended'`, but the light is the confirmation a person has to see;
- a screen reader on the live region and on the mic button's changing label.

### Messages redesign — 24 Sep 2026 ✅

A client-requested redesign of the **existing** Messages module, not a new page: the backend,
the routes, the schema, the policies and the messaging logic are untouched except for two new
read-only endpoints.

**The page is now a three-column workspace** — rail · conversation · context panel — sharing one
fixed-height row, so only the message log scrolls and the composer never leaves the screen.
Measured in `svh` rather than `vh`, so a phone's address bar cannot cut it off. Zero horizontal
overflow at 360, 375, 768 and 1280, in light and dark. At 1024 the panel becomes a Sheet; at 375
the rail *is* the page and a conversation opens over it with a Back control.

**New components** (all under `resources/js/Components/Messages/`): `MessagesRail.vue`,
`MessageRow.vue`, `AttachmentCard.vue`, `ConversationContextPanel.vue`, `NewMessageDialog.vue`.
`MessageThread.vue` was reworked **inside its frozen public API** (M-2), so the task Discussion
panel and the project Discussion tab got the same improvements and none of the risk.

**Two new endpoints**, both inside the existing `messages` group and therefore behind the same
`messages.use` key:

- `GET /messages/search?q=` — a real `ILIKE` over `messages.body`, scoped by ids taken from
  `inboxFor()` **before** the term runs (M-3). 30 hits, newest first, excerpt cut on the server
  and centred on the match.
- `GET /messages/{conversation}/context` — members, shared files, the linked project and its
  visible tasks. Fetched lazily, cached per viewer+conversation, and it marks nothing read (M-5).

**Preserved and re-checked:** DMs, the team channel, project channels, announcements, the
announcement banner's one rule (it goes quiet when read, it is not dismissed by a button),
attachments, mentions and the whole `MentionPicker` a11y wiring, unread counts, the unread
separator, `role="log"`, opening at the bottom, "Load earlier messages", `aria-current` and
`preserve-scroll` on the rail, `can_post` as the only thing that draws a composer, and
`defineOptions({ layout })` picking the shell from `auth.user.surface`.

**Deliberately not built** — reactions, pinned messages, threaded replies, presence and "last
seen", call and video, rich-text bodies, deep-link-to-a-single-message. Every one of them is in
the reference image and **none of them has a table, a column or an endpoint** (M-11). What each
would cost:

| Feature | What it needs |
| --- | --- |
| Reactions | a `message_reactions` table (message, user, emoji), a toggle endpoint, an aggregate on `MessageResource` |
| Pinned messages | `pinned_at`/`pinned_by` on `messages`, a pin/unpin endpoint, a policy for who may pin, a section on the context payload |
| Threaded replies | `parent_message_id` on `messages`, a per-thread read model, reply counts, an endpoint reading one sub-thread |
| Presence / last seen | a Reverb presence channel plus a `last_seen_at`, and a privacy decision this repo has not taken |
| Call / video | a third-party provider and a whole integration |
| Rich text | a sanitising store path and a render path; `MessageBody` renders *segments* precisely so a message is never HTML |
| Jump to a search hit | `GET /messages/{conversation}?around=<message>`. Without it a result opens the conversation, which is what it does |

**Tests:** 29 new (`tests/Feature/Messages/MessageSearchTest.php`,
`ConversationContextTest.php`) plus two rows in the permission matrix. Suite **1614 passed**.

## Deployment log

| Date | Commit | Server | Result |
| --- | --- | --- | --- |
| 2026-09-17 | `1ac6aa6` (kit tested at `7245aeb` + kit) | Fresh Ubuntu 24.04 **container** (not the VPS): `deploy/test/run-install-test.sh` | PASS 7/7: /login 200, / 302, 5 users, audit_logs `ar`, hq-queue RUNNING, production env, shellcheck. See `docs/runbooks/install-log-2026-09-17.md` |

---

### Chat colour — 24 Sep 2026 ✅

Built out of turn, at the client's request: they tested the redesign and said the chat *"have
not colorfull for chating"*. They were right — the redesign gave all five conversation types the
channel treatment, which is correct for a room with eight people in it and wrong for a DM.

**A DM is now two-sided.** The viewer's own messages sit right in a solid brand bubble
(`--primary` / `--primary-foreground`, 4.99:1 light and 7.31:1 dark — the only solid pair in the
token set that passes); the other person's sit left on `--muted`. No names, no avatars: there are
two people and the side says which. Bubbles cap at 80% of the column on a phone and 60% from
`lg`, so a one-word reply is a one-word bubble.

**Channels keep their shape** — avatars, author lines, one side — and gain a `--brand-tint` band
on the viewer's own messages, a real weight on the author name, and a `mentions_me` treatment
that reads as a highlight instead of a hairline.

`MessageBody` and `AttachmentCard` gained an `onAccent` prop, because a mention inside a brand
bubble was `text-primary` on `--primary`: **1.00:1, invisible.** On accent a mention is semibold
with a dotted underline and a link is a solid underline — weight and underline instead of hue,
which is what §5.6 always required anyway.

Every new pair was computed from `app.css` in both modes. **Two designs were changed because the
measurement refused them** (M-20: an own row in a channel does not darken on hover, because under
`--brand-tint-strong` the clock falls to 4.47:1; M-21: the focus ring on a brand bubble went
opaque, because `ring-ring/50` measured 1.19:1 there). **Two bugs fell out of it** — see below.

Decisions M-17…M-23. `MessageThread`'s public API is byte-identical, so the task Discussion panel
and the project Discussion tab are unchanged in shape and got the improvements for free.

### ⚠️ Found on the way: the focus ring is under the 3:1 floor **everywhere**

`app.css` sets `outline-ring/50` and every control in this repo writes
`focus-visible:ring-ring/50`. `DESIGN.md` §2.2 records the ring at 3.45:1 / 3.61:1 and marks both
✅ — but that is the ring measured **opaque**, and the app renders it at 50%. Composited it is
**1.87–1.93:1 in light and 2.31–2.44:1 in dark**. Computed twice, independently.

This is not a messaging bug: it is every button, link, input and row in goodERP, and it means the
"visible focus ring at every tab stop" line this repo has held itself to since Phase 0.5 was
measuring *presence*, not *visibility*. Written up in `POLISH-BACKLOG.md` §E.1, and it should be
fixed **before** the next gate makes an accessibility claim.

### Live sync for messaging — 24 Sep 2026 ✅

The client's report: *"i have send message as shahadat to yaseen .. but without reload yaseen
didnot seeen any message."* Built out of turn, because it is the one they actually feel.

**What was actually wrong.** `MessageService::post()` fired `MessagePosted` and the only
subscriber was `NotificationDispatcher`. The `conversation.{id}` channel and its policy-backed
auth callback had existed since Phase 6 and **nothing ever broadcast on them**; `MessageThread`
exposed `refresh()` precisely so a socket could call it and **nothing called it**. The seam was
real. The thing that hangs off it was never built — and "the seam is in place" was reported in a
way the client reasonably read as "it works".

**Server:** `App\Events\ConversationActivity` — `ShouldBroadcast` + `ShouldDispatchAfterCommit`,
on `PrivateChannel('conversation.'.$id)`, `broadcastAs` `conversation.message`, carrying
`{conversation_id, message_id}` **and nothing else** (M-25). Dispatched by
`App\Listeners\ConversationBroadcaster` off `MessagePosted`, so all five conversation types ring
by construction. 17 tests.

**Client:** `resources/js/Components/Messages/live.ts` — one composable, four states. Socket
connected → subscribe, **no timer at all**. Polling build, socket dropped, or no channel → poll.
Tab hidden → nothing at all, including the socket handler (M-30), because a refresh marks the
thread read and a hidden tab must not clear an unread count nobody looked at. Wired into
`MessageThread` (10 s) and the rail's partial reload (15 s).

**Measured, reproducing the client's own test** — Yaseen with the DM open, Shahadat posting from
outside that browser:

| Build | Thread | Rail |
| --- | --- | --- |
| **Polling — what their machine runs today** | 7.9–9.4 s, worst case 10 s | 8.9–14.2 s, worst case 15 s |
| Socket (Reverb + queue worker, built and tested) | **375 ms** doorbell→paint | still up to 15 s |

No reload, no navigation, no flash. Scroll position holds (scrolled to the top: `scrollTop 0 → 0`
while `scrollHeight` grew); a half-typed draft, a picked file and the focus all survive; hidden
for 25 s → **0 requests**, so nothing was marked read. Works on all three `MessageThread` mounts,
so a task comment appears in the Discussion panel too.

**Still needs a reload:** the Messages nav row's unread indicator anywhere else in the app; the
announcement banner outside the Messages page (6-18); the task board, the dashboards and
attendance (POLISH-BACKLOG §A.3). And on a socket build the **rail** is still a 15-second poll,
because there is no per-user inbox channel — say that plainly rather than letting "live" imply
the whole screen.

**The launchers now turn the socket on** (M-37…M-40, same day, at the client's request).
`start-hq.bat` and `dev-hq.bat` set `BROADCAST_CONNECTION=reverb`, `VITE_REALTIME=reverb`, the
Reverb keys and `QUEUE_CONNECTION=database` in the process environment — not in `.env`, for the
reason C-1a gives — and `start-hq.bat` opens two more windows: Reverb and a queue worker. The
database queue was chosen over Redis because the `jobs` table is made by the `migrate` the
launcher already runs, so it needs nothing installed on a Windows desktop.

Verified end to end in the container with exactly those values: `event(ConversationActivity)` →
a row in `jobs` → the worker → Reverb, **18.57 ms, no failure**, and Reverb starts and listens on
127.0.0.1:8080.

**Nothing there can stop the app starting.** If Reverb or the worker fails, the socket never
reaches `connected`, the interval starts in the same tick and the header says "Reconnecting" —
which is the behaviour of the day before. A listening 8080 means a pair is already up, so
neither is started twice.

**And a connected socket is not a working delivery chain** (M-39). A broadcast is a queued job,
so somebody who closes the worker window has a socket that connects, subscribes, reports itself
*live* and then never says anything again — silent, total, and worse than the poll it replaced.
`LIVE_SAFETY_MS` is a 45-second read that runs even while the socket claims to be up, which
turns that into a slow thread instead of a dead one. Four requests a minute on a visible screen,
none behind a hidden tab.

Decisions M-24…M-40. Suite **1631 passed**.

## Phase 7 — Meetings ✅ complete, except the Google API driver (GATE A)

### Slice 1 — the domain, 24 Sep 2026 ✅

Schema, service, policy, events, notifications, the 15-minute reminder and the calendar seam.
**No controllers and no Vue** — those are two dispatches on top of a domain that is already
provable. 65 new tests; suite **1737 passed**.

**Tables:** `meetings` · `meeting_participants` · `meeting_notes` · `tasks.source_meeting_id`,
exactly Part D's line and no more. **`migrate` runs on `start-hq.bat`, so restart it before
looking.**

**The Google API driver is deliberately not built** (7-2). Creating an event with
`conferenceData` needs a Workspace calendar, and a plain service account **cannot** make Meet
links — so it is per-organiser OAuth or domain-wide delegation, and that is **GATE A question 3,
still unanswered**. Part D says the manual driver is always available, so `ManualLink` is what
ships: the organiser opens Google's instant-meeting flow and pastes the link back. The seam has a
contract test the API driver will have to satisfy, so adding it later is a class and one arm of a
`match`.

**Permissions** are a key, not a role: `meetings.use` (the 25th) goes to the four non-Accountant
roles and the Accountant falls out by holding none. A meeting you are not on is **404** — you do
not learn it exists; a meeting you can see but may not edit is **403** — the act is refused, not
the record.

**The privacy case this slice exists to get right:** you can be on a meeting without being on its
linked project, so the project's name is **absent** from your payload — not null, not the id.
`MeetingService::linkedContextFor()` is the one place that answers it, and the test asserts
`array_key_exists` is false, because `=== null` passes for both the right answer and the wrong
one (7-10). The seeder demonstrates it with a real row, so the rule is not only true in the
suite.

**Three things worth knowing:**

1. **A fifth migration nobody asked for** (7-12). The notifications CHECK is generated from the
   enum *at the moment it runs*, so a fresh database knows every type and passes every test —
   while **the client's database, built at Phase 2, knows ten** and would have rejected the first
   meeting notification as a broken feature. Fourth time this has come up (3-6).
2. **My brief was wrong** and the agent refused it (7-11). I wrote "no policy here names a role";
   six do, for exactly this kind of administrative override, and Part C §1 explicitly forbids the
   alternative I was reaching for.
3. **The reminder's memory is the notifications table**, not a column and not a cache lock (7-5).
   A column can disagree with it after a restore; Redis is a cache here, so a flush would re-send
   every reminder in the window once a minute until the meeting started.

### Slices 2 and 3 — the screens, 24 Sep 2026 ✅

Built by two agents at once against one fixed contract. Suite **1783 passed**.

**Slice 2 — list, calendar, form.** One page, three views, the view in the URL so a month is
bookmarkable. The list is upcoming-then-past grouped by day; the month is a grid that **stops
being a grid below `sm`** and becomes an agenda, because seven columns across 360px is 44px a
cell — a calendar that tells you it has meetings without telling you what any of them are
(7-19). The week is seven columns of meetings rather than a time grid, and the reasoning is
written down (7-18). A calendar view fetches **its window**, not the table (7-21).

The create/edit form carries the **"Create Meet Link"** button: it opens Google's instant-meeting
page in a new tab and the organiser pastes the link back. **The rule for what a valid link looks
like exists once, in PHP** — the form writes no regex at all; its one check compares the paste
against a string the server sent (7-20). `https://meet.google.com/new` is the likeliest wrong
paste, because it is the button's own address, and it gets its own sentence.

**Slice 3 — the detail page.** Join, participants and RSVP, notes and decisions, action items →
**Convert to Task**, and Cancel. Plus the **"Upcoming meetings"** card on both dashboards, capped
at 5, ordered by `end_at` rather than `start_at` so the meeting you are five minutes late for
stays — the one the card is most useful for.

A participant answers only for themselves, and that is locked three ways: `user_id` is
**prohibited** on the request, the controller reads the subject from the session, and the policy
compares the subject against the actor. The test asserts the **outcome** rather than any one
lock — Tapu posts Yaseen's id and neither seat moves.

The cancel dialog says what will happen in words: everybody is notified, **the tasks this meeting
produced are not deleted**, the meeting stays on the calendar marked Cancelled — and, under the
manual driver only, that the organiser must cancel the Meet on Google by hand. That last line is
the same constant the service writes to the activity trail, not a second copy. The buttons are
*Keep the meeting* / *Cancel the meeting*, because "Cancel" in that dialog is genuinely
ambiguous.

### Two real defects I fixed myself, in the service rather than a screen

`convertActionItem()` asked only `view`, so:

1. it would convert on a **cancelled** meeting — the rule lived in one controller and no second
   caller would have inherited it;
2. it would create a task on **a project the actor cannot see**. The project comes from the
   *meeting*, and a meeting is visible to everybody in the room — so somebody invited to a review
   of a project they are not on could mint a task inside it. `TaskPolicy::create` is about the
   verb and would have allowed it, and `Task::visibleTo()` would then have hidden the result from
   its own author. **That is the worst shape a permission bug takes: it succeeds, and the
   evidence disappears.** (7-23)

And a smaller one worth the note: `can_convert_action_items` folds three refusals together, and
the **order** is load-bearing. The first version told a remote employee there was no project
while he was looking straight at its name — the refusal was real, the reason was false (7-24).

### Still open on Phase 7

- **`CalendarApiOneWay`** — blocked on **GATE A question 3**: per-organiser OAuth, or a service
  account with domain-wide delegation. A plain service account cannot create Meet links. The seam
  and its contract test are built, so this is a class and one arm of a `match`.
- Email calendar invites, which cannot exist without that driver.
- Follow-ups 7-30…7-32.

## Phase 8 — Finance & the Accountant surface ✅ complete

### Slice 1 — the domain and the finance-only project window, 25 Sep 2026 ✅

`income` · `expenses` · `finance_categories`, `FinanceService`, three policies, the audit trail,
and `GET /accountant/projects`. **No screens** — those are the next dispatch. 71 new tests; suite
**1854 passed**. **Restart `start-hq.bat`: this slice adds migrations.**

**The sharpest privacy boundary in the application** is this endpoint, and it is why Part D §13
exists. The permission matrix says ❌ projects and ❌ clients for the Accountant but 🟡 *project
finance, read-only*. So the resource carries **exactly four keys** — `id`, `name`, `domain`,
`finance` — and inside `finance` exactly the six money columns. No client, no contact, no
description, no note, no task, no member, and **no count of any of them**, because a count is a
fact about how busy a client's account is. Pinned by a recursive forbidden-key walk **and** an
exact allow-list, plus a test that no seeded client or contact *name* appears anywhere in the
body — which catches one arriving as the value of an allowed key.

It deliberately does **not** use `Project::visibleTo()`, which returns nothing for the
Accountant. Phase 1's test asserting that is untouched and still green: this is a second door,
not a wider one (8-9).

**Three things decided in the database rather than in PHP.** An expense category cannot be used
as income, enforced by a **composite foreign key** on `(category_id, category_kind)` — because
that constraint decides which line of the rollup money lands on, and `FinanceService` is only
*today's* sole writer (8-2). `amount > 0`, because the sign is the table and a negative income
is an expense in disguise (8-3). And `ON UPDATE RESTRICT`, so a category in use cannot change
sides — an Admin editing the list cannot reclassify eight months of income with one dropdown.

**Hard delete, and the argument is worth reading** (8-5): a soft delete would put
`WHERE deleted_at IS NULL` in front of every total in the app, where forgetting one means a
figure that silently includes a deleted row *and agrees with the ledger screen, which
remembered*. It would also be **weaker** — `deleted_at` lives on a table the app role can update
and delete from, and `audit_logs` is the one table it cannot. A test re-INSERTs a deleted income
from its audit JSON and proves the rebuilt row is identical.

Part D's acceptance sentence is a test verbatim **and** true in the running app after a seed:
*September 2026 — Maintenance $860, SEO $800, Website $1,250 → $2,910.*

### Closed on the way: decision 4-17

`tests/Unit/SurfaceTest.php` asserted a **count** of audit events, and that count was wrong three
times — once per phase that added one, each time as a red test in an unrelated file telling
whoever hit it nothing but a number to change. It now asserts the **shape**: every case is
`subject.verb`, lower snake_case, no duplicates. A count only catches *"somebody added an
event"*, which is not a defect; a badly-shaped value is, because `audit_logs.event` is an
indexed string with no CHECK behind it and every report and filter groups on it (8-15).

### Slice 2 — the screens, 25 Sep 2026 ✅

Two agents at once. Suite **1953 passed**.

**The screens are shared, not one copy per shell** (8-18) — `routes/shared.php` behind
`can:finance.view`, each page picking `AdminLayout` or `AccountantLayout` from the viewer's own
surface, exactly as Messages, Leave and Meetings do. Two copies would be two places for
*"Employees/Remote get 403 on every finance route"* to be answered differently.

**Ledgers:** Income and Expenses, a month at a time with the month in the URL, totals read from
`monthlyRollup()` rather than added up again in Vue. **Categories**, read by the Accountant and
owned by the Admin. A delete dialog that says what it means — *"It is not archived and it is not
hidden, and it cannot be undone… What remains is the audit log entry, which keeps the whole
record."*

**Numbers:** the Finance dashboard, the Monthly report in Part D's three cuts (by category, by
project, trend), and the Admin Company dashboard's **Row 3**. A twelve-month trend costs **five
queries, and so does a twenty-four-month one** (8-25) — the test that matters asserts the two
cost the *same*, which a loop over `monthlyRollup()` could never have passed.

**8-16 is superseded** (8-19): the project picker sends `id`, `name`, `domain` — the same three
keys for the Admin as for the Accountant. A picker on an income row does not need a client name,
so it does not get one, and one payload beats a branch.

### Three things found on the way, each worth more than the feature

1. **A client's name was seeded into an income note** (8-20). The Part C guard had shipped split
   in two, with `notes` stripped from the wide half, on the argument that Part C governs what the
   server *derives* rather than what a person typed. True, and the wrong call: that hands the
   Accountant through the back door exactly what `AccountantProjectResource` spends four keys
   refusing at the front — **in the one field no forbidden-key walk can see, because there the
   leak is the value and the key is `notes`.** The seeder changed and the exception went.
2. **Fixing that quietly lost $560** (8-21). `FinanceSeeder` keyed idempotence on the **note**,
   so making two notes equal merged two rows and September came to $2,350 instead of $2,910. It
   was caught only because that number is written down in Part D and asserted. Idempotence is now
   `(date, amount)`: a note is prose somebody edits, a payment is when it landed and how much.
3. **`sr-only` does not clip a `<table>`** (8-22). It pins `width: 1px; overflow: hidden`, which
   clips a box — and a table is sized by its content regardless. An **invisible** chart fallback
   was pushing the page 63px wide at 360 with nothing on screen to explain it. Fixed in all three
   chart components rather than per caller.

### Still open on Phase 8

Follow-ups 8-29…8-31: the month parser now exists five times and two screens answer a malformed
month differently; `DataTable` clips rather than scrolls a wide table, and what goes first is the
row's actions menu; three older money formatters still hard-code USD.

## Phase 9 — Payroll ✅ complete (awaiting GATE E)

### Slice 1 — the state machine, the scope and the lock, 25 Sep 2026 ✅

`employee_salaries` · `payroll_periods` · `payroll_items`, `PayrollService` with its guards, the
leave-impact rule, the auto-draft command, and **the locked-period check Phase 8 left as an empty
seam**. No screens. 114 new tests; suite **2067 passed**. **Restart `start-hq.bat`: three new
migrations.**

**The lock is live everywhere at once.** Phase 8 shipped `assertPeriodIsOpen()` empty but
*already called from every finance write path* (8-14), so filling the body was the whole job.
Four call sites cover five directions: a row **created** in a closed month, **edited** inside
one, **moved into** one, **moved out of** one, and **deleted** from one. That fourth is the one
that is easy to leave out — moving a record out of a locked month is the same act as changing one
inside it. Tested from the finance side, for `locked` and for `paid`, plus the control that an
**approved** month still accepts writes: Part D names two statuses and exactly two (9-13).

**Salary history is effective-dated rows** (9-1), and the test that matters re-runs September
*after* a November raise and gets September's number. The tempting alternative — one current row
with the old values in `audit_logs` — fails precisely there: that table is ADMIN-only, shaped as
JSON, unindexed by subject, and **the Accountant who runs payroll may not read it**. A payslip
that cannot be recomputed without access to the compliance log is not supportable.

**The daily rate closes** (9-2): `(base + allowance) ÷ working days on that person's own
schedule`. The divisor is working days because `unpaid_days` already is — divide by 30 and
somebody who took every working day of September unpaid gets docked 22/30ths and paid for the
other eight. There is a test called *"docks a whole month of unpaid leave down to zero — the rule
that closes"*.

**`net_salary` is a generated column** (9-3), computed by PostgreSQL on write, so an item whose
net disagrees with its parts cannot exist — not from a service, a seeder, a Phase 12 import,
`psql`, or a future screen that edits a bonus and forgets to recalculate. A test asserts a raw
`UPDATE … SET net_salary` is refused.

**Privacy.** Part B §3 rule 1's three halves are three named tests — the listing **omits** other
people, a direct id is **404**, and the attempt is **audit-logged**. The 404 comes from the query
rather than from a branch, so there is no code path that could produce a 403, and an id matching
nothing is byte-identical to an id belonging to somebody else. The audit row carries the item id
and **not** the figures: a row about a refused read must not carry what the read was refused
(9-9). `admin_notes` goes to an ADMIN and to nobody else — not the Accountant, and **not the
employee it is about** (9-10), pinned by an exact key set plus a recursive forbidden-key walk.

### Slice 2 — the screens, 25 Sep 2026 ✅

Two agents at once. Suite **2143 passed**. **`composer.lock` moved (dompdf), so `start-hq.bat`
must be restarted** — it runs `composer install` when the lockfile changes, and without that
every payslip PDF 500s on a missing class.

**The workbench** (shared, behind `can:payroll.draft`): the period list, the period detail with
its per-employee rows, Calculate, and the whole Admin side — Review, Approve, Lock, Reverse lock
with a required reason, Mark paid. The state machine is the screen's spine, and the controls are
**one function intersecting two server-resolved facts, naming no role** (9-22). When there are no
controls the screen says *why* (9-23).

**Lock and Mark paid are worded differently on purpose** (9-24). Lock: *"An Admin can reverse
this… so this is a closed door, not a final one."* Mark paid: *"If a figure on this month is
wrong, fix it before you press this; afterwards the only route is a correction in a later
month."*

**My Payslip** for every role including the Accountant, in their own shell — Part C §1's cell,
proved in a browser. **Only `PAID` is a released payslip** (9-28), because an item stays editable
right through `approved` and `locked → approved` is a legal move; a payslip is a receipt, and
issuing one from a still-editable figure produces the complaint the screen exists to prevent.
Earlier months are shown and **labelled**, and the PDF stamps *PROVISIONAL — NOT A PAYSLIP* and
names the file that way, because a file in somebody's Downloads outlives the screen that framed
it (9-29).

**Salary settings** (Admin only) writes a new effective-dated row per change, so September keeps
September's number. The **dashboard payroll card** is real: `SUM(payroll_items.net_salary)`
summed by PostgreSQL, and `null` — never `$0.00` — for a month with no period (9-35).

### Three things worth more than the screens

1. **A defect my own brief was wrong about, and the spec won** (9-33). I told the agent
   `itemsFor()` was self-scope. It is not: `scopeVisibleTo()` returns early for ADMIN and
   ACCOUNTANT, so *My Payslip* listed **all five people** for the Accountant. Phase 9's own test
   list requires the opposite, so the listing was narrowed — using the model's existing predicate,
   so there is still no hand-written `where('employee_id')` anywhere.
2. **Two focus defects no HTTP test could catch** (9-27): the item dialog stayed open showing the
   **pre-save** figures after a save, and **a transition deletes its own trigger** — locking
   removes the Lock button — so "return focus to the trigger" landed on `<body>`. Decision 5-20's
   outcome reached by a different route.
3. **dompdf embeds a whole font face by default** — 878 KB per payslip, brought to 22.6 KB by
   subsetting (9-34).

## 🚪 GATE E — your turn

Review **finance and payroll with the Accountant login**, and walk September through all six
states. Three answers are needed, and one has teeth:

1. **Is the daily rate `base + allowance`, or `base` alone?** (9-16)
2. **Is `PAID` really terminal?** (9-17) — a paid month can never be reopened to finance. The
   Mark-paid dialog is written on the assumption that it stands; if you say otherwise, that copy
   is the first thing to change.
3. **Is there a send-back from `REVIEWED` to `CALCULATED`?** (9-18)

### The three GATE E questions, in full

1. **Is the daily rate `base + allowance`, or `base` alone?** (9-16) Part D says *"× daily rate"*
   and never defines it. One line and one test to change.
2. **Is `PAID` really terminal?** (9-17) Part D names no verb after it, so **a month that has
   been paid can never be reopened to finance** — `LOCKED` and `PAID` both close the ledger and
   only a lock is reversible. An error found after payday has no route today but a correction in
   the following month.
3. **Is there a send-back from `REVIEWED` to `CALCULATED`?** (9-18) Today a wrong figure spotted
   at review is fixed by the Accountant editing in place, and "reviewed" quietly starts meaning
   something else with nobody told the review is stale.

## Phase 10 — Reports, search, Gantt, dashboards ✅ complete

### Global search, 25 Sep 2026 ✅

`tsvector` columns and GIN indexes on eight tables, `SearchService`, and the shell's **existing**
command palette extended rather than replaced — Phase 0.5 left the entity groups in it as the
seam this slice fills. 75 new tests. **Restart `start-hq.bat`: new migrations.**

**The rule this slice exists for is scope-then-rank**, and the query plan turned out to prove it
rather than fight it (10-2). Measured on 50 000 synthetic rows: the scope as a **predicate** runs
in **1.48 ms and keeps the GIN index**, with `Sort` and `Limit` sitting *above* the filter so only
accessible rows ever reach ranking. Materialising the scope as an id list is **19.6 ms and
abandons the index**. Nothing was traded — and the refactor that would silently undo both
properties at once is exactly that materialisation, so the service says so in its docblock.

**The invariant underneath it is stricter than "the searcher may read it"** (10-1): a column is
indexed only if *every* viewer the row's scope admits may read it. That is what makes the **count**
safe for free — a hit can only be caused by text the matcher was already allowed to read.

**The Accountant's restriction has no role branch in it** — `grep -i accountant SearchService.php`
finds only prose (10-5). They find finance records because they hold `finance.view` and nothing
else because they hold nothing else; **the Admin finds the books too**, which is the proof it is a
capability and not a shell.

Part D's own sentence is a test: *"Buffalo" as Tapu returns the project and never the client
record or price.* And the sharper one — a term matching **only** a restricted record answers
`total: 0` with no groups, the same answer a nonsense term gets (10-6).

### Tasks → Gantt, 25 Sep 2026 ✅

The fourth Tasks view: rows grouped by project, bars, milestone diamonds, dependency arrows,
drag and resize through the existing validation, zoom day/week/month in the URL, today line, the
same filter bar, and the List with a notice below `md`. 23 new tests.

**A task with no due date is an open-ended mark** (10-9) — the plan does not name that case, and
two of the three obvious answers lie: a one-day bar says the work takes a day, and a bar to the
window's edge says it is due at a date that moves when you page. The first build was 16px and was
a speck, which is the same failure quietly, so it was rebuilt at three days with the words *"no
due date"* beside it.

**A dependency out of the window is a count; out of the viewer's access it is nothing at all**
(10-11) — two different absences, and the test asserts the hidden task's title and id appear
nowhere in the encoded payload.

**Drag is move-then-confirm with one `draft` concept** serving the pointer preview, the keyboard's
staged edit and in-flight optimism — so *"the bar stays where it was dropped while the server said
no"* is **structurally impossible** rather than merely avoided (10-12). And **every bar has a full
keyboard equivalent**: `e` cycles what the arrows hold, arrows stage a day, `Shift` a week at every
zoom, `Enter` saves, `Escape` cancels (10-14).

### Found on the way

- **A latent 320px overflow** (10-16): `TaskViewSwitcher` was a non-wrapping `inline-flex` and
  three tabs fitted 320 by about 30px. The fourth spilled the page and clipped the new tab out of
  reach at 375. It would have bitten whoever added a tab next.
- **The Gantt and the Calendar now disagree about a half-dated task** (10-10), and the Gantt's
  answer is the honest one: `TaskService::span()` draws a start-date-only task as a one-day bar,
  silently asserting it is due the day it starts.
- **`DemoSeeder` seeds no messages and no files at all** (10-19) — two whole features with no demo
  data, which is why both were silently untestable, and why nobody has ever seen those screens
  populated.

### Reports — the 8 core ones, 25 Sep 2026 ✅

`ReportService`, the eight reports spec §43 puts first (Task, Employee Work, Project, Overdue,
Attendance, Time, Finance, Payroll), `/admin/reports` and Employee → My Reports. 79 new tests.
**Restart `start-hq.bat`: a new seeder.**

**A report is DATA in one shape, and one screen renders all sixteen** (10-21). The shape was
frozen in `docs/report-contract.md` before either half was written, which is what let the
backend and the surface be built at the same time. Adding the remaining eight is adding a
**builder**, not a page — they inherit the filter bar, the footer, the chart cap and the empty
state. `Show.vue` knows `ReportFormat` and nothing else; a `switch` on a report key anywhere in
it is the failure the contract exists to prevent.

**There is no `reports.view` permission** (10-22). A report requires the permission of **the
data it reads**, so the catalogue lists exactly the cards whose key the viewer holds and neither
route carries a `can:` — which key applies depends on which report was asked for. No role is
named anywhere in `ReportService` or `app/Support/Report*.php`.

**The Finance report over exactly one calendar month equals `FinanceService::monthlyRollup()`,
and a test says so** (10-23). The one thing a reports phase must not do is produce a second
opinion about money; for a one-month window the rows ARE the rollup's strings, with no
arithmetic, so the equality is exact by construction.

**Three charts is enforced by a constructor, not a convention** (10-24) — a budget kept by
convention holds until the fifteenth report.

**Employee Work is ordered by name and carries no chart** (10-25). Part H §1 forbids
productivity scores: a table sorted by output is a league table whatever the column is called,
and a bar chart of people is a ranking turned sideways.

### The finding that made this slice twice as long

**No seeder had ever created an `attendance_records` or a `time_entries` row** (10-27). Three of
the eight core reports opened on an empty state, against Part E's *"every report opens with real
data"* — and behind them, **Phase 4's whole feature area had never been seen with data by
anyone**, the client included: Attendance, Time, Timesheet, Workload, the dashboard's "Remote
time today" card. Same class as 10-19.

`WorkSeeder` fixes it: 117 attendance rows for the three office people, 66 time entries for
Tapu, both months, with a Late, an Absent and a Half day **per person per calendar month** so no
status column can be all zeroes, and approved **and** pending minutes so the awaiting-sign-off
path finally renders. Every predicate comes from `AttendanceService` — no clock time, weekday or
grace value is written down in the seeder — and idempotence is keyed on identity, not on a
sentence, which is the `FinanceSeeder` $560 lesson applied.

**Eleven existing tests assumed those tables were empty** (10-28). Every one was a wrong
assumption and none was wrong data.

### Found on the way

- **`Task::assignees()` had no `ORDER BY`** (10-29), and it took seeding tracked time to expose
  it: a list of names silently came back the other way round, reproducibly but only when another
  test file had run first. Fixed on the relation, so every surface gets one answer.
- **A status was printed two different ways a few centimetres apart** (10-30) — the Task
  report's donut said *"Waiting / Blocked"* and the table under it said *"Waiting"*, because
  `StatusBadge`'s default word for a tone is not this app's word.
- **The Attendance ring drew Late in green and Absent in blue** (10-32), from a categorical
  palette in series order — colour saying the opposite of the word beside it. Every slice now
  carries its status tone.
- **The ring and the footer disagreed** (10-31): 54 against 72, with Tapu's eighteen tracked
  days in no column. **Remote** is now the fifth bucket, and Part C rule 6's two tables read as
  one answer.
- **`AGENTS.md`'s suite command had silently stopped covering nine test folders** (10-34), so
  *"the suite passed"* meant nine folders nobody ran.
- **The Admin sidebar's REPORTS group is collapsed by default** (10-35), so a viewer who has
  never opened it will not see that reports exist. Left alone deliberately and put on
  `POLISH-BACKLOG.md` — it belongs to Phase 12's UX pass.

### The other eight reports, and the dashboards, 26 Sep 2026 ✅

All sixteen of Part D §15 now open, and every dashboard card on all three shells is real. 64 new
tests. **Restart `start-hq.bat`.**

**The contract's two gaps were closed first, on purpose** (10-36). A cell can now be a link and a
chart carries a format — retrofitting a link column across sixteen reports is a different job
from designing it into the contract while there were eight. The eight new builders use both: 21
linked columns, every href checked by following it.

**Adding eight reports added no screen.** That was the bet 10-21 made, and it paid: `Show.vue`
was not touched, the filter bar was not touched, and the whole slice is `ReportKey` cases, builders
and tests.

**Two definitions the spec left open, settled from Part D and not invented** — a Maintenance/SEO
*period* is the cycle the recurring engine generated the work for (10-38), and Completion measures
`start_date → completed_at` rather than `created_at`, which a reseed would make print noughts
(10-39). One access question was **refused rather than answered**: Meeting and In-app coordination
take no employee filter, because no existing scope says whose meetings or messages you may filter
by (10-40).

**Performance is per project and has no per-person row** — Part D §2, spec §30 and Part H §1 all
say so, and the percentage guard was rewritten to state the rule that actually matters: no report
whose rows name a person carries a percentage at all (10-42).

**Dashboards.** The Admin screen gained Tasks by employee, Projects by type and Upcoming
deadlines, and is now pinned at exactly three charts. Employee gained My Schedule and Recent
activity. **The Accountant dashboard was entirely Phase-0 placeholders** — four em-dash cards, a
source-less panel and a disabled button — and is now four real money cards, an Outstanding panel,
leave and payslip.

### Found on the way

- **Every linked cell rendered as plain text and nothing failed** (10-37) — the href was read off
  the table's `{id, cells}` wrapper instead of `cells`. `unknown` at that boundary, so no type
  complained and no test could. Found in a screenshot.
- **`AttentionList`'s root card had no `min-w-0`** (10-47) and pushed the Admin dashboard 51px
  wide at 375 the day it gained a second caller.
- **`TimerBar` fired a 403 on every page of the employee shell** for anybody on office attendance
  (10-48). The bar is `v-if="canTrack"`, but a `v-if` does not stop `onMounted`.
- **A dead chart** bound to a literal `[]` — "Attendance, last 14 days" — deleted (10-46).
- **Six console tests failed having passed every previous morning** (10-50): a test pinning an
  absolute date met a seeder that dates its rows relatively. Twenty-three test files pin a date;
  that is now a named hazard in `AGENTS.md`.
- **Four more seed gaps** of the 10-19 / 10-27 kind (10-51) — no seeded leave request, no meeting
  notes or action items, no generated recurring instances, and every seeded message is a task
  discussion. Every report still opens with real rows, but four dimensions have no demo data
  behind them.

## Phase 12 — Admin tools ✅ complete

### Employees / Users & Roles, 26 Sep 2026 ✅

The list, the detail, create, deactivate, reactivate, role, tracking mode, project-level
permission grants, and password re-issue. 65 new tests. **Restart `start-hq.bat`.**

**They are one screen family, not two features** (12-1). Part D §2 says so in a line, so the two
sidebar rows are one list asked two questions — `/admin/employees` and `?view=access`, which
swaps the workforce columns for the access ones.

**`EmployeeResource` can never carry a salary** (12-2), and the test asserts that against the
encoded response rather than against the class. A personnel screen with a pay column would be
the worst leak in this application; `/salaries` is where that lives, with its own permission.

**Role and tracking mode are two endpoints, not one `update`** (12-8) — the same reason
`PUT /admin/projects/{id}` ignores a `status` key. Both reuse the service methods that already
audit and self-guard, and **MANAGER is refused by both**, so a role Part C §1 assigns to nobody
cannot be reached by promotion either.

### The credential problem, which took three goes to get right

**First sign-in is a generated password said once** (12-3) — there is no email in the MVP and no
reset route, so that sentence IS the delivery.

It shipped on the generic `success` flash, which `FlashMessage.vue` renders as a **persistent,
undismissable Alert** in the same strip as *"Project archived"* — and the sentence it carried,
*"It is not shown again"*, **was false**: Inertia keeps page props in `history.state`, so Back
re-rendered the password (12-4). It now has its own one-shot prop, its own panel with a copy
control, and three things together make the promise true: the server spends the flash on one
render, `encryptHistory()` makes that entry ciphertext, `clearHistory()` throws the key away.
Verified by actually pressing Back.

**`clearHistory()` alone does not do it** (12-5) — it only drops the key, and an unencrypted
entry is a plain object popstate restores happily. And `encryptData` falls back to plaintext on
a non-secure origin, so over plain HTTP the Back protection degrades silently.

**An Admin can now re-issue a password** (12-6), which the first build could not. Without it an
account could become permanently unusable by nobody's mistake: no email, no reset route, and
Profile → Password needs the current password — so a forgotten one left a login **no Admin in
the agency could repair**, on a record that must be kept forever. The only remedy was to
deactivate the person and create a second one, splitting a human being's history across two
records. It ends their sessions, which is the point and not a side effect.

### Found on the way

- **`employees.manager_id` had no writer anywhere in the application** (12-12), so both *own
  team* branches of `attendanceVisibleTo` and `leaveVisibleTo` were unreachable code — two
  access rules written, tested against an empty case, and never once exercised.
- **The top bar said `#3` where the page said "Tapu"** (12-13). `resourceName()` understood only
  a wrapped resource; every screen that sends a resolved one had an id for a breadcrumb.
- **`activity_logs` is polymorphic and a test had forgotten it** (12-14) — an employee id and a
  meeting id were the same number, and `->sole()` found two rows on a clean tree.

### Audit Log, Settings and Notification defaults, 26 Sep 2026 ✅

The last three admin screens. 95 new tests, two migrations. **Restart `start-hq.bat`: new
migrations.**

**The Audit Log is the screen eleven phases were writing to and nobody had ever read** — every
salary change, price change, role change, lock reversal and restricted-access attempt has been
going in there since Phase 0. It is read-only because **there is no write route**, which is not
a policy decision: `audit_logs` is append-only enforced by PostgreSQL grants (12-16), and the
existing `AuditLogAppendOnlyTest` proves it with raw statements.

**The Accountant is 403 on it** (12-17), which is the row worth reading: they can open a
payslip, and an audit row legitimately carries a salary in its diff. The log is a different
question from the data it describes.

**The diff is server-side** (12-18), and **three ways of having no value are three different
words** — *Not recorded*, *Empty*, *Blank*. `jsonb` does not preserve key order (12-19), so
sorting by label is the only option that exists.

**Settings are editable at last.** `SettingsService::set()` had existed, audited, and refused
the read-only keys since Phase 0 — with **zero callers**. Every Part D §20 key now has a typed
range; the two drivers are read-only **and say why**, because they genuinely cannot switch at
runtime.

**Notification defaults** (`notification_preferences`) with the engine honouring them.
**No CHECK on the new table** (12-21) and the reasoning is in the migration: `notifications.type`'s
CHECK has cost four widening migrations because it froze an enum against the client's live
database, and the trade flips for a table whose rows are switches rather than records.
**Absent means default, not off** (12-22) — a pre-seeded table goes stale the moment a case is
added, and the engine would then silently stop announcing the newest thing in the app.

### Found on the way

- **`audit_logs` had one index besides its primary key** (12-20), and the viewer filters on four
  columns. It had been write-only for eleven phases. `actor_id` had none — PostgreSQL does not
  index a foreign key automatically.
- **`deliver()`'s in-app create was unguarded** (12-25): the lookup checked the channel list, the
  write did not.
- **One test had been a coin flip since Phase 4** (12-26). `ProjectFactory` names projects with
  Faker's `catchPhrase()`, whose word list contains *productivity* and *efficiency* — and
  `TimeScreenTest` greps the payload for exactly those. It failed on a clean tree and passed on
  the re-run. Fixed by pinning the data, not by weakening the scan.
- **A fresh install opens the Audit Log empty, and that is correct** (12-27) — seeding is not an
  actor doing things. It fills the first time anybody changes a setting.

### The cutover slice, 26 Sep 2026 ✅

`hq:import`, the security pass, the performance pass, the backup restore drill, and
`docs/runbooks/cutover.md`. 153 new tests, one index migration.

**The dry run IS the real run, rolled back** (12-29) — one code path, one report, so there is no
estimator that can disagree with the writer. It is idempotent on identity keys, never on prose
(12-30), and **nothing bypasses a service**: an imported task is walked through
`TaskService::transition()` rather than written at its final status (12-31).

**Imported hours arrive unapproved** (12-32) — they are a claim made by another system, and an
Admin signing them is when they become the agency's numbers. **Notifications are off during an
import** (12-33): 300 *"you were assigned"* rows at cutover is an outage of the bell, and a
broadcast would have escaped the dry run's rollback.

**The importer's biggest assumption is one it cannot resolve** (12-35): *"a top-level ClickUp row
is a client"* is right for the four real clients — and the client's own workspace also has
*SITES CREDENTIALS* and *SEO sheet Global template* at top level, with no column to tell them
apart. The dry run's created-clients list is where a human decides, and the runbook makes that a
step.

**Part E's "done when" for the import is NOT met** and is not claimed: no real export has been
through it. Every column name is a documented guess.

**Security.** A CSP that was **verified with it enforcing across 53 signed-in pages** — zero
violations. It is a middleware rather than an nginx directive (12-36), registered globally
because a group-scoped policy left every 404 as the only CSP-free HTML in the app.
`'unsafe-inline'` survives on `style-src` for two measured reasons and `script-src` stays strict
(12-37). HSTS, `fastcgi_param HTTPS`, and five rate limiters keyed on user id because the office
is one address (12-38). §E.13 is closed.

**Performance.** `TaskResource` had been lazy-loading four `User` belongs-tos on every row —
**39 of `/admin/tasks`'s 57 queries** (12-40). The heaviest surfaces went **57 → 22**, and the
per-row slope from +1 to **+0**. The ceilings were tightened in the same commit, because slack
absorbs a fix silently and lets it regress just as silently.

**The restore drill was executed** (12-42): real dump, real AES-256 zip, real decrypt, real
restore into a scratch database, row counts, drop, `backup_last_verified_at` written. Against a
**local** disk — this container has no bucket and no archive password — so the mechanism is
proven and the client's bucket is not. `docs/runbooks/backup-restore-drill-2026-09-26.md` says
exactly that, and does not say "backups are verified".

### Found on the way

- **`10-18`'s recorded fix was wrong** (12-39): `$hidden` is a serialisation filter and does not
  shorten `SELECT *`. It closed a leak, not the 56 KB.
- **The existing MIME tests never exercised finfo** (12-43) — the helper derives a type from the
  file *name*, so the test only proved its own two arguments agreed. The production code was
  already right; the test was not.
- **`User::hasPermission()` has no per-role cache** (12-41), measured at +37 queries per 10
  employees on `/messages`. **Deliberately not fixed**: the obvious static map is exactly what
  the permission matrix breaks, and that is the wrong trade in the last building slice.

### The UX polish pass, 26 Sep 2026 ✅ — the last building work in the project

`POLISH-BACKLOG.md` end to end, in three waves. **Restart `start-hq.bat`: two migrations.**
Decisions 12-44…12-67. 79 new tests (2697 → **2776**).

**The client found the bug the suite could not see.** The employee sidebar's **Notifications** row
sat in the *Coming soon* disclosure labelled `P2` for **ten phases** while `GET /notifications`
worked perfectly — so the people being notified were told the Notification Center was not built
yet. It was the last phase-gated row in the application, and **nothing had ever read
`resources/js/navigation/*.ts`**. `tests/Feature/Surfaces/NavigationTest.php` now matches every
row's href through the router and keeps the phase-gated inventory as an explicit empty list
(12-64). The *Coming soon* disclosure is gone from every shell.

**§A — live sync, closed on the polling build.** Deferred since 24 Sep at the client's own
instruction, and the one thing on that backlog they actually feel. `useLiveProps()` in
`Realtime/reload.ts` is the single pattern; every screen in §A.3 now updates with **zero document
loads**, verified on polling *deliberately*, because the client's machine is a polling build and a
socket-only fix is a fix they never see (12-44). 6-18 closed with it — the announcement banner is
app-wide, from one shared prop.

**And it found a defect my own security slice had shipped.** `connect-src 'self'` (12-36) killed
the Reverb socket on the client's machine: the app is on `:8000` and Reverb on `:8080`, and a
different port is a different origin. Nothing failed loudly — the screens fell back to polling
and said *"Reconnecting"*. The policy now names the origin the application's own configuration
says it will dial, and contributes nothing on the VPS, where the header is unchanged (12-45).

**§E.1 — the focus ring, opaque at ~84 call sites**, not by redefining `--ring`, which would have
silently moved every border that reads the same token. Every swept surface measures ≥3:1
afterwards. Two defects nobody had raised turned up doing it: **`--elevation-flat: none` was
deleting the focus ring outright** on every control carrying `shadow-flat` — Tailwind v4 composes
one `box-shadow` and `none` is only legal as the sole value, so the declaration was dropped and
took `--tw-ring-shadow` with it (12-49) — and **menu, command and select items had no ring at
all**, only `focus:bg-accent` at 1.05:1 (12-50).

**§E.17 settled after three flags in three slices**: `DESIGN.md` §5.13 was right and the code was
stale — `app.css` never defined `shadow-xs` at all. `tests/Unit/DesignVocabularyTest.php` greps
the source the way a reviewer would, and its exemption list is empty and documented to stay that
way (12-51).

**§C.3 — thirteen rows closed, and three of them had been closed for days.** 2-48, 2-49 and 2-50
were already built and still listed as open; a backlog index that lists finished work makes every
other row less believable, so each was checked against the tree rather than against the index
(12-63). The substantive ones: focus restoration after a confirm dialog, app-wide (12-52);
sub-second unread, which needed a migration because `timestamp(0)` *rounds* (12-53); an ungrouped
`orWhere` (12-54); statuses composed server-side (12-55); the tab in the URL (12-56); placeholders
out of a generated task's name (12-57); `attachments` and its eager-load out of the task payload
(12-60); one `ConversationPolicy::dm()` instead of two private copies (12-61).

**5-19 was filed as a client decision and it was a bug** (12-58). Somebody who booked the wrong
week had to be **rejected** to escape their own mistake — somebody else's refusal on their record
for something nobody disagreed about. Leave requests can now be withdrawn from `pending` and
`correction_requested`; the status is terminal, spends no balance, writes no attendance, and is
**not** a holding status, so the days are immediately re-bookable. Approved is deliberately not
withdrawable. Verified in a browser end to end: apply → Withdraw → *"Withdrawn: Sick, 2 days.
Those days are free again."*

**The enum-CHECK hazard, paid for the sixth time** (12-67). `leave_requests_status_is_known` was
generated from the enum at Phase 2 — which is the client's live database. On a `migrate:fresh`
tree the new status shows nothing wrong; on their copy every screen would have offered Withdraw
and the UPDATE would have been refused.

### Found on the way — the polish pass

- **The suite's baseline was checked rather than absorbed.** An agent measured 602 on part 6
  against `AGENTS.md`'s 565 and reported the 37 as a finding. It resolved exactly: three new
  files in those folders plus two added tests. The near-miss is worth keeping — **count with
  Pest, not with `grep -c '^it('`**, because a dataset is one block and many tests (12-65).
- **The employee dashboard's Notifications card is an `EmptyState` sitting under a bell badge
  reading 4.** The reasoning behind it is sound and written into the file; the screen still says
  the opposite of the bell. Not built — it is cosmetic and it was found by looking. `POLISH-BACKLOG.md`
  §E.20.
- **Three follow-ups the pass opened itself**, all in §C.3: the same-card board race (12-46),
  eight project screens still on the client-side tone map (12-55), and a withdrawal that does not
  quieten the approver's unread row (12-59).

## Deferred polish

**`POLISH-BACKLOG.md`** (root) is the debt column, opened 24 Sep 2026 at the client's
instruction — *"after all phases done then need to implement it perfectly on here"*. It holds
three things: **live sync** (nothing broadcasts a message, nothing subscribes to
`conversation.{id}`, and the client's machine is a polling build — so every screen but the bell
needs a reload), **chat colour** (the redesign draws a DM the same way it draws a channel, which
is wrong for a DM), and the full list of everything promised and not yet delivered. Read it
before promising anything else.

## Next step

**Nothing is left to build. Everything remaining is yours.**

The UX polish pass was the last building work in the project and it landed on 26 Sep 2026. Phases
0–10 and 12 are complete; Phase 11 is blocked on spec §46; `POLISH-BACKLOG.md` now holds only
client decisions, three follow-ups the pass opened itself, and M-11 (the messaging features that
have no table, column or endpoint — priced, not started).

**Restart `start-hq.bat` before opening Leave** — the polish pass added two migrations
(`2026_10_27_0001_unread_line_is_sub_second`, `2026_10_27_0002_leave_may_be_withdrawn`). Without
the second one every screen offers **Withdraw** and the database refuses the write.

So the project now moves on your answers, in this order:

1. **GATE D** — chat, voice and realtime on a phone and a desktop. Phase 6 has been waiting on
   this sign-off; live sync is now real on every screen, so there is more to look at than there
   was.
2. **GATE E** — 9-16, 9-17, 9-18. Payroll behaviour that is **already built**, and the longer it
   waits the more data exists under an assumption you may not agree with.
3. **The ClickUp export.** `hq:import` has never seen a real one and every column name it reads
   is a documented guess. `docs/runbooks/cutover.md` is written to be followed on the day.
4. **The VPS and the backup bucket.** The restore drill proved the mechanism against a local
   disk; whether *your* bucket is reachable, in another account or region, and openable with the
   archive password has never been tested, because it does not exist yet.
5. **Real email addresses**, then cutover.
6. **spec §46** unblocks Phase 11, and **Google Workspace** unblocks Phase 7's Calendar driver.

The final review is the remaining gate.

### What is waiting on you, in the order it blocks things

| | Blocks |
| --- | --- |
| **The ClickUp export** | `hq:import` has never seen a real one. Every column name it reads is a documented guess, and Part E's *"done when"* for the import cannot be met without it |
| **GATE E** — 9-16 the daily rate's base · 9-17 is `PAID` terminal · 9-18 the send-back | Payroll behaviour that is **already built**. The longer it waits, the more data exists under an assumption you may not agree with |
| **GATE D** — chat, voice, realtime on a phone and a desktop | Phase 6 sign-off |
| **The VPS and the backup bucket** | The restore drill has proven the mechanism against a local disk. Whether your bucket is reachable, in another account or region, and openable with the archive password has **never been tested, because it does not exist yet** |
| **Real email addresses** | Cutover. The seeded `@goodtechies.test` logins are not accounts anybody can use |
| **spec §46** | The whole of Phase 11 |
| **Google Workspace** | Phase 7's Calendar API driver |

**Restart `start-hq.bat` before testing** — this slice added an index migration.

**Two new things you can try:** `php artisan hq:import --from=clickup --file=... --dry-run`
(reads `--help` for the columns it expects), and the Settings screen's **Backup health** card,
which now shows a real verification instead of *"Not verified yet"*.

**Still open:** the rest of GATE A (real email addresses, VPS and backup bucket, Google
Workspace, spec §46, the ClickUp export) and GATE B (contacts per client, employee priority
visibility, the unarchive target status, the "Internal" label). **Phase 7 will need the Google
Workspace answer** — it decides whether the Meet link is pasted by hand or pushed through the
Calendar API.

**A client decision still waiting:** 5-17 — a leave day that is also a company holiday still
burns a day of Annual leave.

**Polish the user flagged at GATE C** — accepted as-is, to be revisited rather than fixed now.

**One thing only you can do:** the file bridge refuses to write `.env` (it holds the database and
seed passwords), so line 1 of `D:\goodtechies-hq\.env` still reads `APP_NAME="GoodTechies HQ"`.
The launchers set `APP_NAME=goodERP` in the environment, which wins, so the app is correctly
named — but making it permanent means editing that line by hand, and the same line 5 in
`deploy/.env.production.example`.
