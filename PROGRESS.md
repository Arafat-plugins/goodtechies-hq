# GoodTechies HQ — Build Progress

> Source of truth: `docs/master-prompt-v1.md` (v1.2), condensed from the client spec *GoodTechies HQ — Agency Operating System v1.0 (Sept 2026)* + the client's "Application Visuals" design doc + current ClickUp workspace (`docs/design-refs/`).
> Updated at the end of every phase and after every gate. A new session must be able to continue from this file alone.

**Last updated:** 20 Sep 2026 · **Current phase:** 0.5 — Design Foundation ✅ complete · **Status:** waiting for **GATE B** (GATE A questions still open)
**Test suite:** `php artisan test` → 343 / 343 passed · **Deployed on VPS:** no. The deploy kit passed in a fresh Ubuntu 24.04 container; see the deployment log. · **Execution method:** dispatch v1.5.1

---

## Overall progress

**Phases 0, 1 and 0.5 built — waiting for GATE B (≈ 17 %)**

`[█████                           ]`

| Phase | Vertical slice | Gate | Status |
| --- | --- | --- | --- |
| 0 | Foundation: auth + 2FA, roles, three shells, audit/activity logs, CI, VPS deploy kit, backups | **GATE A** | ✅ built (gate questions still open) |
| 1 | Clients & Projects with the privacy model | **GATE B** | 🔄 built — waiting for GATE B |
| 0.5 | Design Foundation: brand tokens from the logo, the app shell, 8 base components, charts, every Phase 0/1 screen migrated | — | ✅ complete |
| 2 | Tasks: List (grouped) · Board · Calendar · tags · files · task discussion · in-app notifications | — | ✅ complete |
| 3 | Recurring task engine + fixed automation rules | — | ✅ complete |
| 4 | Remote timer + Timesheet + office attendance + schedules + workload | **GATE C** | ✅ complete — **GATE C passed 25 Sep** |
| 5 | Leave management + holidays | — | ✅ complete |
| 6 | Team communication (team/project chat, DMs, announcements, voice, Team directory) + realtime | **GATE D** | 🔄 core built — voice remains, then GATE D |
| 7 | Meetings + Google Meet (one-way) + action items → tasks | — | ⬜ |
| 8 | Finance module + Accountant surface | — | ⬜ |
| 9 | Payroll state machine + payslips | **GATE E** | ⬜ |
| 10 | Reports + global search + Gantt view + dashboards finalized | — | ⬜ |
| 11 | Activity-tracking browser extension (blocked until the client amends spec §46) | **GATE F** | ⬜ blocked |
| 12 | Admin tools (Users & Roles, audit viewer, settings, notification defaults), backup health, UX polish pass, migration import, hardening, cutover | **Final review** | ⬜ |

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
  - `php artisan test` runs everything (343).
  - `php artisan test --group=phase0`, `--group=phase1` and `--group=permissions` run subsets.
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

## Phase 6 — Communication & realtime 🔄 (core built, voice remains)

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

### Still to build before GATE D

- **Voice messages** — hold-to-record with waveform and timer, preview, inline playback at
  1×/1.5×/2×. The seams are in: `MessageService::post()` already takes a kind and a duration,
  `MessageResource` prints it, and `MessageThread` already renders an `<audio>` for
  `kind === 'voice'`. It needs a recorder in the composer and two arguments at one call site.
- The **announcement banner app-wide** rather than on the Messages page only (6-18).
- Then **GATE D**: you check chat and voice on phone and desktop.

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

## Deferred polish

**`POLISH-BACKLOG.md`** (root) is the debt column, opened 24 Sep 2026 at the client's
instruction — *"after all phases done then need to implement it perfectly on here"*. It holds
three things: **live sync** (nothing broadcasts a message, nothing subscribes to
`conversation.{id}`, and the client's machine is a polling build — so every screen but the bell
needs a reload), **chat colour** (the redesign draws a DM the same way it draws a channel, which
is wrong for a DM), and the full list of everything promised and not yet delivered. Read it
before promising anything else.

## Next step

**Phase 6's voice slice, then GATE D.** The Messages redesign (24 Sep) is in and synced;
restart `start-hq.bat` before testing it — it seeds `messages.use`, without which every
messaging route answers 403.

1. **Voice messages**: hold-to-record in the composer with a live waveform and timer, preview
   (play / re-record / send), upload through the same `FileService` pipeline as every other
   attachment, `message_attachments.kind = voice` with `duration_seconds`, and inline playback at
   1×/1.5×/2×. No transcription (Part H).
2. The **announcement banner app-wide** (6-18), which means one prop in `HandleInertiaRequests`
   and the prop-shape tests that come with it.
3. Then **GATE D** — you check chat and voice on a phone and on a desktop.

After that: **Phase 7 — Meetings + Google Meet (one-way) + action items → tasks.**

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
