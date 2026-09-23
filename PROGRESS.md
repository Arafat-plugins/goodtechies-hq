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
| 2 | Tasks: List (grouped) · Board · Calendar · tags · files · task discussion · in-app notifications | — | ⬜ |
| 3 | Recurring task engine + fixed automation rules | — | ⬜ |
| 4 | Remote timer + Timesheet + office attendance + schedules + workload | **GATE C** | ⬜ |
| 5 | Leave management + holidays | — | ⬜ |
| 6 | Team communication (team/project chat, DMs, announcements, voice, Team directory) + realtime | **GATE D** | ⬜ |
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

## Deployment log

| Date | Commit | Server | Result |
| --- | --- | --- | --- |
| 2026-09-17 | `1ac6aa6` (kit tested at `7245aeb` + kit) | Fresh Ubuntu 24.04 **container** (not the VPS): `deploy/test/run-install-test.sh` | PASS 7/7: /login 200, / 302, 5 users, audit_logs `ar`, hq-queue RUNNING, production env, shellcheck. See `docs/runbooks/install-log-2026-09-17.md` |

---

## Next step

**Phase 4 — remote timer, Timesheet, office attendance, schedules, workload.** It is the next
phase in the table and it ends at **GATE C**, the next place this build stops for you.

Its shape, from the plan (line 660): a timer widget for the remote employee on task detail and
as a persistent bar, with offline handling and manual entries; the weekly Timesheet grid; clock
in/out for the office roles; the admin's attendance roster, month grid and per-employee schedule
editor; the Time approval queue for flagged and manual entries; and the Workload view. Plus the
dashboard cards those produce — including AC2's "Tapu 4h 18m / 5h" on the Company dashboard.

**Still open, and blocking nothing yet:** the GATE A questions (Sun–Thu week, real email
addresses, VPS and backup bucket, Google Workspace, spec §46, the ClickUp export, holidays) and
the GATE B ones (contacts per client, employee priority visibility, the unarchive target status,
the "Internal" label). **Phase 4 will need the GATE A answer about the working week and holidays**
— attendance cannot be scored without knowing which days are working days.

**One thing only you can do:** the file bridge refuses to write `.env` (it holds the database and
seed passwords), so line 1 of `D:\goodtechies-hq\.env` still reads `APP_NAME="GoodTechies HQ"`.
The launchers set `APP_NAME=goodERP` in the environment, which wins, so the app is correctly
named — but making it permanent means editing that line by hand, and the same line 5 in
`deploy/.env.production.example`.
