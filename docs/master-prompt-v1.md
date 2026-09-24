# GoodTechies HQ — Claude Code Master Prompt v1.4

> **Source of truth:** the client specification *"GoodTechies HQ — Agency Operating System, Complete Product & Operations Specification, Version 1.0, September 2026"* (50 sections + appendices A–K), plus two client-supplied references added in v1.1: the **"Application Visuals"** design doc (`docs/design-refs/`, Part I) and the client's **current ClickUp workspace** (`docs/design-refs/10-clickup-current-workspace.png`). This prompt condenses those into a build plan. Everything here follows the spec as written — **no stack substitutions, no removed features**. Where this prompt and the spec disagree, the spec wins; where the spec is silent, choose the simplest option that satisfies the spec and record the decision in `PROGRESS.md`. **Naming rule:** "Phase N" in this prompt always means *this prompt's* build phases (Part E). The spec's own post-MVP scopes (§44, §45) are always written "spec post-MVP Phase 2 / Phase 3".
>
> **v1.1 additions (client-requested after v1.0):** ClickUp-parity views (List grouped by status, Board, Calendar, Gantt, Timesheet, tags, start date — Part D §5/§7), the **activity-tracking browser extension** for remote timer users (Phase 11, Part D §7a — requires the client to amend spec §46, see Part H §2), the design-reference map (Part I), and the `/dispatch` working method (Part J).
>
> **v1.2 (review pass, 17 Sep 2026):** an independent review of v1.1 against the spec found 60+ gaps — spec items missed (2FA timing, audit assertions, task delete/archive/links, file backup rules, meeting-cancel calendar side effect, AC2 admin card), internal inconsistencies (table/column/setting names, phase dependencies), unactionable rules, and technical errors (PostgreSQL grants, MV3 service-worker lifetime, content-script permissions, Laravel 11 support status). All are fixed in place; the list is in "Changes in v1.2" at the end. Nothing was added to scope.
>
> **Deployment target:** the client will host this on **their own VPS** (single server). Part B §4 describes the exact server layout every phase must stay compatible with.
>
> **Build method:** **vertical slices**, never horizontal layers. Every phase delivers one complete, clickable, tested feature through all layers (migration → model → policy → serializer → controller → Inertia page → tests → seed → docs). Never build "all migrations first" or "all models first". Part E defines the phases; Part G defines how progress is recorded so any new session can continue alone. When the repo uses the `dispatch` skill, Part J says how a phase is split into dispatch briefs.

---

# PART 0 — SESSION PROTOCOL (read first, every session)

## 0.1 Files that carry the build across sessions

| File | Purpose |
| --- | --- |
| `docs/master-prompt-v1.md` (this file) | The plan. Read Part 0, Part C and the current phase in Part E every session. |
| `PROGRESS.md` (repo root) | What is built, tested, decided, and what is next. Updated at the end of every phase and after every gate. A new session must be able to continue from it alone. Template in Part G. |
| `docs/decisions.md` | Running log of every decision taken where the spec was silent (also mirrored in PROGRESS.md "Decisions"). |
| `docs/design-refs/` | The client's visual references (Part I). Look at the relevant image before building a screen; borrow the pattern, never the product. |
| `PROJECT_BRIEF.md` + `AGENTS.md` | Only when the repo is driven with the `dispatch` skill (Part J). `PROJECT_BRIEF.md` is derived from this file, never re-asked. |

## 0.2 Start of every session

1. Read `PROGRESS.md` fully. Identify the current phase and its status (not started / in progress / waiting for gate).
2. Run the full test suite (`php artisan test`). It must be green before new work starts. If it is red, fix it first and note why in `PROGRESS.md`.
3. Read the current phase's section in Part E and the non-negotiable rules in Part C.
4. Tell the user in 3–5 lines what you are about to build in this phase, then build.

## 0.3 End of every phase

1. All tests for this phase pass **and** all earlier phases' tests still pass.
2. Seed data updated so the feature can be clicked immediately after `php artisan migrate:fresh --seed`.
3. `PROGRESS.md` updated (phase status, what can be clicked, files built, tests count, decisions, known issues, next step).
4. Commit (if git exists) with message `Phase N: <slice name>`.
5. If the phase is marked **GATE**, stop and wait for the user's confirmation before starting the next phase.

## 0.4 Kick-off prompts (paste one of these to start a session)

**A. Phase 0, with the `dispatch` skill (recommended for the whole build):**

```
Read docs/master-prompt-v1.md — Part 0, Part B, Part C, Part J — and PROGRESS.md.
This repo is built with the dispatch skill. Follow Part J.1 exactly: write PROJECT_BRIEF.md from the master prompt (do NOT run the /dispatch new intake), then /dispatch bootstrap, then /dispatch setup (attach the browser tools and the shadcn-tokens MCP to the frontend agent; DESIGN.md is generated from Part I + shadcn-tokens get_theme/get_spacing/get_typography).
Then build Phase 0 as the briefs listed in Part J.2, one at a time, opus for core, sonnet for light. Run php artisan test after every accepted brief. Update PROGRESS.md at the end of the phase and stop at GATE A.
```

**B. Any later phase / resuming:**

```
Read PROGRESS.md first, then docs/master-prompt-v1.md Part 0, Part C, Part J and the section of Part E for the current phase. Run php artisan test — it must be green before new work. Continue the current phase with dispatch briefs per Part J.2; re-run /dispatch bootstrap if AGENTS.md is older than the last phase. Stop at the next GATE.
```

**C. Without dispatch (plain session):** same as B but replace "with dispatch briefs per Part J.2" with "vertically, slice by slice, per Part 0.5".

**D. Design/UI work in any session:** add the line *"Before writing any Tailwind class, call the shadcn-tokens MCP (get_theme, get_spacing, get_typography, get_component_styles for the component you are building) and use only token classes; never hard-code a colour or an off-scale spacing."*

## 0.5 Rules that apply to every phase

- **Vertical only.** A phase is done when its feature works end to end in the browser with real data. Half-built screens or endpoints "for later" are not allowed. The one permitted placeholder is a navigation item, tab or dashboard card that is **disabled and labelled "arrives in Phase N"** — it renders nothing else and has no route behind it.
- **Always runnable.** After every phase the app boots, login works, every earlier feature still works.
- **Privacy tested, not assumed.** Every phase that touches projects, payroll, clients, finance or audit data ships its negative permission tests in the same phase (Part C §1).
- **No scope creep.** Part H lists what must NOT be built. Do not add anything not in the spec, even if it seems small.
- **Spec silent → simplest option + record it.** Never stop the build to ask about small things; ask only at gates. Collect open questions in `PROGRESS.md` → "Questions for the client".
- **No fake UI.** Every number on a dashboard comes from a real query; every notification from a real event.
- **A screen that shows somebody else's action refreshes itself.** Every screen is one Inertia response, so a list, queue, badge or count that *another person's* action changes is wrong from the moment it is painted and says nothing about it — an Admin with the leave queue open reads "Nothing waiting" while the request sits in the database. Any such screen therefore refreshes on its own: a **20 s** `router.reload({ only: [...] })` naming only the props that change, until that surface's Reverb channel exists (Phase 6), and the channel afterwards — **the poll is deleted when the channel lands, never left running beside it**. The reference implementation is the notification bell (`resources/js/Components/Notifications/notifications.ts`, decision 2-39) and it is not to be reinvented: one interval however many components mount it, nothing requested while the tab is hidden, one request in flight, the listener leaving with the last unmount. A refresh must not move focus, close a dialog, reset a filter or replay an enter transition — if it cannot be noticed except by the number changing, it is right. **The test of whether this rule applies is one question: can somebody who is not looking at this screen change what it says?** If yes, the screen polls, and its brief says so.

---

# PART A — WHAT IS BEING BUILT

## 1. Summary

**GoodTechies HQ** is a single internal operating system for GoodTechies, a small digital agency (WordPress development, WooCommerce, SEO, website maintenance, digital marketing). It replaces ClickUp, Asana, Telegram, spreadsheets and disconnected attendance/finance tools.

One relationship chain connects everything:

```text
CLIENT → PROJECT → TASK → COMMENTS/CHAT → FILES → TIME/ATTENDANCE → STATUS → COMPLETION → REPORTING
                                                                                     ↑
FINANCE (parallel) → CLIENT / PROJECT / INCOME / EXPENSE / PAYROLL ─────────────────┘
```

The defining design constraint is **privacy by role**, enforced at the backend authorization layer (never only hidden in the UI):

- Employees see operational data (domain, task, deadline) — never client pricing, contracts, or profitability.
- Remote employees are tracked by task-based timer; office employees by clock-in/clock-out. These are separate mechanisms and separate tables.
- The Accountant sees finance and payroll only — never client conversations, SEO strategy, or task data.
- No employee can see another employee's salary, ever — enforced server-side.

The business goal: **full replacement of ClickUp/Asana** for project/task management and a meaningful reduction of Telegram dependency (Acceptance Criteria, Part F §3).

## 2. The team today (seed data)

| Person | Role in system | Function | Tracking mode |
| --- | --- | --- | --- |
| Shahadat Hossain | ADMIN | Founder — PM, SEO, website work, ops | `office_attendance` |
| Faruk Ahmed | ADMIN | Co-Founder — client comms, maintenance, marketing | `office_attendance` |
| Tapu | REMOTE_EMPLOYEE | Remote SEO Executive | `remote_timer` (5 h/day target) |
| Yaseen | EMPLOYEE | Office maintenance / web app manager | `office_attendance` |
| Accountant | ACCOUNTANT | Finance only | `none` |

Shahadat and Faruk hold ADMIN **equally** — there is no OWNER role and no rank between them. The only guard: an Admin cannot change their **own** role or deactivate their **own** account.

ADMIN is a permission level, not "only manages": Admins are valid task assignees, see a personal **My Work** view (My Tasks / Due Today / Overdue / My Attendance / My Leave) beside the **Company** dashboard, clock in/out like any office employee, apply for leave, and chat as participants.

## 3. Business rhythm

- **Project cadence:** one-time projects move start → build → review → launch → close.
- **Retainer cadence:** recurring monthly cycles (SEO, maintenance) auto-generate a fresh batch of tasks every period, executed, reviewed, completed, and rolled into a monthly client report. Most day-to-day tasks come from retainers — the **Recurring Task Engine is the highest-value module**.
- One client commonly has several projects of different types (e.g. Buffalo Modular → Website Development + SEO + Maintenance + future Web App). Model as client-has-many-projects.

## 4. Three experience surfaces, one data model

1. **Admin/Management surface** — full visibility (Shahadat, Faruk, future managers).
2. **Employee operational surface** — narrow, task-focused: "What should I do now?"
3. **Accountant surface** — structurally separate navigation shell, finance + payroll only. Not a filtered admin view.

Standalone web application; desktop-first for Admin, responsive for Employee (phone: view task, update status, comment, upload photo, record voice message, start/stop timer, notifications, meetings, request leave).

---

# PART B — LOCKED STACK, ARCHITECTURE & VPS TARGET

## 1. Pinned technical decisions (do not evaluate alternatives)

| Layer | Choice | Notes |
| --- | --- | --- |
| Backend | **Laravel — current supported major (12 or 13, whichever is current at Phase 0), PHP 8.3+** | Spec/Appendix K say "Laravel 11", but Laravel 11's security-fix window ended March 2026, so pinning it in September 2026 would ship an unsupported framework. Confirm the major with the client at GATE A and record it; everything else in the spec's stack choice is unchanged. Policies (RBAC), Form Requests, Queues, Broadcasting, Scheduler, Sanctum (device tokens for the Phase 11 extension; future mobile) |
| Database | **PostgreSQL 16** | Full-text search (tsvector), JSON for audit old/new values, table-level grants to make `audit_logs` insert-only (Part C §4) |
| Frontend | **Vue 3 (Composition API) + Inertia.js** | No separate SPA/API deployment; Vite build |
| Realtime | **Laravel Reverb** (self-hosted WebSockets) + Laravel Echo | Polling fallback (10–15 s) acceptable in MVP if Reverb is troublesome; same Echo code either way |
| Queue | **Redis + Laravel Queues** | Recurring task generation, notification fan-out, payroll draft creation |
| File storage | **S3-compatible object storage** | Spec names Cloudflare R2 or AWS S3; any S3-compatible bucket works (`FILESYSTEM_DISK=s3`). Files never stored as DB BLOBs; DB holds metadata only |
| Auth | Laravel session auth (web); Sanctum personal access tokens for the Phase 11 extension (long-lived, revocable, optional `expires_at`; re-pair to renew — Sanctum has no refresh flow) and a future mobile app | 2FA (TOTP) — required for ADMIN/ACCOUNTANT **from Phase 0**, optional for others in MVP |
| Search | PostgreSQL full-text (MVP) | No Elasticsearch/Meilisearch |
| Calendar/Meet | Google Calendar API, **one-way create-only** in MVP | Manual "paste Meet link" must always work; two-way sync is spec post-MVP Phase 2 |
| Tests | **Pest** (feature + unit) | Permission-matrix suite runs against every endpoint |
| Hosting | **Single VPS** (client-provided) | See §4 |

Rejected by the spec (do not use): Node/NestJS backend, React, Pusher or any hosted realtime SaaS, Elasticsearch/Meilisearch in MVP, native mobile app in MVP, WordPress plugin, storing files in the DB.

## 2. Application structure

```text
goodtechies-hq/
├── app/
│   ├── Console/Commands/        (hq:generate-recurring-tasks, hq:create-payroll-draft, hq:flag-overdue, hq:verify-backup …)
│   ├── Events/                  (TaskAssigned, TaskStatusChanged, LeaveApproved, MeetingCreated, PayrollApproved …)
│   ├── Listeners/               (NotificationDispatcher — the ONE listener that maps event → recipients → channels)
│   ├── Http/
│   │   ├── Controllers/{Admin,Employee,Accountant,Shared}/
│   │   ├── Middleware/          (EnsureRole, EnsureSurface, TwoFactor)
│   │   ├── Requests/            (Form Requests — all validation lives here)
│   │   └── Resources/           (field-level serializers: ProjectResource, ClientResource, PayrollItemResource …)
│   ├── Jobs/                    (GenerateRecurringTasks, SendNotification, CreatePayrollDraft …)
│   ├── Models/
│   ├── Policies/                (one per model; deny-by-default)
│   ├── Services/                (RecurringTaskEngine, TimerService, AttendanceService, LeaveService, PayrollService, NotificationService, AuditLogger, ActivityLogger, SearchService, ReportService)
│   └── Support/                 (Permissions enum/keys, TrackingMode enum, statuses)
├── database/migrations/         (added phase by phase — never all at once)
├── database/seeders/            (RolePermissionSeeder, TeamSeeder (the 5 real users), DemoSeeder (Buffalo Modular etc.))
├── resources/js/
│   ├── Layouts/                 (AdminLayout.vue, EmployeeLayout.vue, AccountantLayout.vue, AuthLayout.vue)
│   ├── Pages/{Admin,Employee,Accountant,Auth,Shared}/
│   └── Components/              (TaskCard, TaskDetail, KanbanBoard, NotificationBell, StatCard, DataTable, Composer (chat), TimerWidget, AttendanceWidget …)
├── routes/                      (web.php split by surface: admin.php, employee.php, accountant.php, shared.php; api.php added in Phase 11 via `php artisan install:api` — extension endpoints only)
├── .github/workflows/ci.yml     (Pest + Postgres service + `npm run build` on every push — Phase 0)
├── tests/
│   ├── Feature/                 (per phase)
│   ├── Unit/
│   └── Permissions/             (the role × endpoint matrix suite — runs on every phase)
├── deploy/                      (nginx.conf, supervisor/*.conf, install.sh, deploy.sh, sql/roles.sql, .env.production.example)
├── docs/                        (master-prompt-v1.md, decisions.md, runbooks/)
└── PROGRESS.md
```

Three layout shells are **separate components**, not one shell with `v-if` on role — the Accountant shell must never import admin components.

## 3. Architectural non-negotiables (spec Appendix K rules 1–6, Appendix I decisions 7–12)

1. Every API/Inertia response touching a **project** or **payroll_item** passes through the field-level serializer (`ProjectResource`, `PayrollItemResource`). No endpoint may return raw model JSON for these two models. Restricted fields are **absent**, not null, not an error. **The one rule for "not allowed":** a *field* the requester may not see is absent from the payload; a *record* the requester may not see is absent from list endpoints and returns **404** on a direct id (never 403, which confirms existence); both cases are audit-logged when the spec names them (salary, project price). A whole *route or surface* the role may not use (an Accountant on `/admin/*`, an office employee on the timer endpoints) returns **403** from `EnsureSurface` / the Policy — a route's existence is not sensitive.
2. Every payroll/salary query defaults to `employee_id = current_user.employee_id` unless the requester's role is explicitly ADMIN or ACCOUNTANT.
3. `audit_logs` is insert-only, enforced by PostgreSQL grants, which requires **two database roles and two Laravel connections**: `hq_migrator` owns the schema and is the only role that runs migrations (connection `pgsql_migrator`, env `DB_MIGRATOR_USERNAME/PASSWORD`; `deploy.sh`, local setup and CI run `php artisan migrate --database=pgsql_migrator`); `hq_app` is the runtime user (default connection `pgsql`). `roles.sql` gives `hq_app` DML on every table via `ALTER DEFAULT PRIVILEGES FOR ROLE hq_migrator … GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO hq_app` and `… GRANT USAGE, SELECT ON SEQUENCES TO hq_app` (Laravel `id()` columns are serial columns; inserts need the sequence) — which is exactly why the `audit_logs` migration must **explicitly `REVOKE UPDATE, DELETE, TRUNCATE ON audit_logs FROM hq_app`** right after `CREATE TABLE` (default privileges would otherwise grant them). Because `hq_app` is not the owner it cannot re-grant itself. Tests: `phpunit.xml` points the default connection at `pgsql_migrator` so `RefreshDatabase` can build the schema; the append-only test opens `DB::connection('pgsql')` (`hq_app`) and runs raw UPDATE / DELETE / TRUNCATE inside a savepoint, expecting `insufficient_privilege`.
4. Recurring task generation checks for an existing open instance for the current period before creating a new one.
5. `time_entries.entry_type ∈ {auto, manual}`; manual entries require `manual_reason` and support `approved_by`.
6. `attendance_records` (office) and `time_entries` (remote) are separate tables — never merged into one "worked hours" table. A `daily_work_summary` **view** joins them for reporting only.
7. `project_finance` is a separate table from `projects`, so exposing price/profit is a join to omit, not a per-field filter.
8. Two note fields on projects: `internal_notes` (admin/manager) and `employee_notes` (assigned team) — never one field with a visibility flag.
9. `activity_logs` (friendly per-object timeline, visible to anyone with access to the object) is a separate table from `audit_logs` (compliance, ADMIN-only).
10. Search scopes the query to permitted object IDs **before** ranking — never filters results after a full-text match.
11. Employee departure = `status = inactive`, never delete.
12. No productivity score anywhere. Workload views show raw counts/hours only.

## 4. VPS deployment target (must stay compatible from Phase 0)

The client runs everything on one VPS. Phase 0 ships the deploy folder; every later phase must keep working under it.

| Component | Setup |
| --- | --- |
| OS | Ubuntu 22.04/24.04 LTS |
| Web | Nginx → PHP 8.3-FPM; document root `public/`; HTTPS via Certbot (Let's Encrypt) |
| DB | PostgreSQL 16 on the same server; two roles: `hq_migrator` (owner; used for migrations only — deploy, local setup and CI) and `hq_app` (runtime, no UPDATE/DELETE/TRUNCATE on `audit_logs`); plus `hq_ro` (SELECT only, with `ALTER DEFAULT PRIVILEGES … GRANT SELECT ON TABLES` so later phases' tables are covered) for the dispatch db-tester / read-only reporting |
| Cache/Queue | Redis 7 |
| Workers | Supervisor programs: `hq-queue` (`php artisan queue:work redis --tries=3`), `hq-reverb` (`php artisan reverb:start`) |
| Scheduler | cron: `* * * * * php /var/www/goodtechies-hq/artisan schedule:run` |
| WebSockets | Nginx location `/app` proxied to Reverb (127.0.0.1:8080) with upgrade headers |
| Storage | S3-compatible bucket (R2/S3 as the spec suggests). MinIO on the VPS is acceptable for the **live** files only if bucket versioning is on **and** replication/backup goes to an off-box provider — spec §37 requires file backups to survive a provider-level outage |
| Backups | `spatie/laravel-backup`: daily DB dump, **encrypted archive** (`BACKUP_ARCHIVE_PASSWORD`, spec §36 "backups encrypted at rest"), retention 14 daily / 8 weekly / 12 monthly, stored in a bucket in a **different provider account or region** from the primary DB host (spec §37); file storage protected by bucket versioning + cross-region/cross-provider replication rather than a second pipeline; weekly `hq:verify-backup` restores the latest dump into a scratch DB and runs row-count smoke checks and writes `settings.backup_last_verified_at`; shown in Admin → Settings ("Backup health: ✅ Verified <date>") |
| Deploy | `deploy/install.sh` (first-time: packages, roles, Nginx, Supervisor, cron, Certbot — the runbook as a script, testable in a fresh Ubuntu container) and `deploy/deploy.sh` (every release: `git pull` → `composer install --no-dev --optimize-autoloader` → `npm ci && npm run build` → `php artisan migrate --force --database=pgsql_migrator` → `config:cache route:cache view:cache` → `queue:restart` → `supervisorctl restart hq-reverb`) |
| Env | `.env.production.example` lists every key (APP, DB_* for `hq_app`, DB_MIGRATOR_* for `hq_migrator`, DB_RO_* for `hq_ro`, REDIS, S3, BROADCAST_CONNECTION + REVERB_* + VITE_REVERB_* + VITE_REALTIME, MAIL, GOOGLE_CALENDAR_DRIVER + GOOGLE_*, BACKUP_* incl. BACKUP_ARCHIVE_PASSWORD) |
| Firewall | ufw: 22, 80, 443 only; Postgres/Redis/Reverb bound to localhost |

Runbooks (`docs/runbooks/`): install, deploy, restore-from-backup, rotate-secrets, add-employee.

---

# PART C — NON-NEGOTIABLE RULES (apply to every phase)

## 1. Privacy by role — the permission matrix

Legend: ✅ full · 🟡 own records / scoped · ❌ none

| Permission | ADMIN | MANAGER | EMPLOYEE | REMOTE_EMP | ACCOUNTANT |
| --- | --- | --- | --- | --- | --- |
| View clients (full commercial) | ✅ | 🟡 assigned | ❌ | ❌ | ❌ |
| Edit clients | ✅ | ❌ | ❌ | ❌ | ❌ |
| View projects (operational view) | ✅ | ✅ | 🟡 assigned | 🟡 assigned | ❌ |
| View project finance (price/profit) | ✅ | ❌ | ❌ | ❌ | 🟡 read-only, linked to invoicing |
| Create/edit projects | ✅ | 🟡 assigned | ❌ | ❌ | ❌ |
| View tasks | ✅ | ✅ | 🟡 assigned | 🟡 assigned | ❌ |
| Create/assign tasks | ✅ | 🟡 own team | ❌ | ❌ | ❌ |
| Delete tasks | ✅ | ✅ | ❌ | ❌ | ❌ |
| Use task timer | — | — | ❌ (n/a) | ✅ own | — |
| View/manage own attendance | ✅ | ✅ | ✅ | ✅ | 🟡 optional |
| Manage others' attendance | ✅ | 🟡 own team | ❌ | ❌ | ❌ |
| Approve leave | ✅ | 🟡 own team | ❌ | ❌ | ❌ |
| Apply for own leave | ✅ | ✅ | ✅ | ✅ | ✅ |
| View own payslip | ✅ | ✅ | ✅ | ✅ | ✅ |
| View others' payroll | ✅ | ❌ | ❌ | ❌ | 🟡 amounts, no personal notes |
| Manage payroll (draft/calculate/approve) | ✅ | ❌ | ❌ | ❌ | 🟡 draft/calculate only; ADMIN approves |
| View finance (income/expense) | ✅ | ❌ | ❌ | ❌ | ✅ |
| Manage finance | ✅ | ❌ | ❌ | ❌ | ✅ |
| Send team-wide announcements | ✅ | ❌ | ❌ | ❌ | ❌ |
| Manage roles/permissions | ✅ (not own account) | ❌ | ❌ | ❌ | ❌ |
| View audit log | ✅ | ❌ | ❌ | ❌ | ❌ |
| Manage system settings | ✅ | ❌ | ❌ | ❌ | ❌ |

Project-level overrides (`user_project_permissions`) layer on top — e.g. a MANAGER may be granted `projects.view_finance` for one project. MANAGER exists in the roles table from Phase 0 but is **assigned to nobody** and gets no dedicated UI in MVP.

**Permission keys** are dotted, `<resource>.<action>`, and are the only spelling used anywhere in code, seeds and tests: `clients.view_full`, `clients.edit`, `projects.view`, `projects.view_finance`, `projects.edit`, `tasks.view`, `tasks.create`, `tasks.delete`, `timer.use`, `attendance.view_own`, `attendance.manage_others`, `leave.apply`, `leave.approve`, `payroll.view_own`, `payroll.view_others`, `payroll.draft`, `payroll.approve`, `finance.view`, `finance.manage`, `announcements.send`, `roles.manage`, `audit.view`, `settings.manage`. The 🟡 cells are implemented as the key **plus** a scope check in the Policy (assigned / own team / own record), never as a separate key.

**Two cells the matrix implies but the sidebar does not show:** the ACCOUNTANT may *apply for own leave* and *view own payslip*. The Accountant shell therefore carries **My Leave** and **My Payslip** (decision recorded, spec §24 omits them) — nothing else outside finance.

## 2. Project privacy model (field level)

| Field | Admin sees | Employee sees |
| --- | --- | --- |
| Client name | ✅ | ❌ (sees **domain** instead) |
| Contact info | ✅ | ❌ |
| Project price / recurring fee | ✅ | ❌ |
| Contract terms | ✅ | ❌ |
| Profitability | ✅ | ❌ |
| Internal notes | ✅ | ❌ |
| Employee-visible notes | ✅ | ✅ |
| Domain, project type, status, deadline | ✅ | ✅ |
| Assigned tasks | ✅ | ✅ (own) |

Example: Admin sees *Client: Buffalo Modular Homes / Project Price: $200/mo / Profit / Contract / Internal notes*. Employee sees *buffalomodular.com / SEO / Assigned to: Tapu / Task: Optimize Home Model pages / Due: Friday*.

## 3. Security baseline

- Server-side session auth; CSRF on every mutating request; rate limiting on auth and API routes.
- Policies on every model, deny by default. Route groups per surface with `EnsureSurface` middleware (an ACCOUNTANT hitting any admin/employee route gets 403 — never a partial page; record-level denials are 404 per Part B §3 rule 1).
- 2FA (TOTP) required for ADMIN and ACCOUNTANT from day one — built and enforced in **Phase 0** (enrolment at first login, `TwoFactor` middleware), so finance and payroll never exist without it; optional for others (they can enrol from Profile).
- Password policy: minimum length + breach-list check (k-anonymity API), no arbitrary complexity rules.
- Session management: user and Admin can revoke active sessions; login history visible to the user and to Admin.
- Uploads: server-side type/extension validation (virus scan if available), stored in object storage, served through **signed, expiring URLs** — never public buckets.
- Secrets only in `.env` / secrets manager; DB backups encrypted at rest.
- Sensitive fields (project price, salary) are filtered server-side — never trusted to frontend hiding.
- Every access attempt to another employee's salary or to project price by an unauthorized role is **logged to audit_logs** and returns a response with the field simply absent.

## 4. Audit log — events that must be recorded (immutable)

project created · project price changed · employee created · role changed · task assigned/reassigned/deleted · task status changed · leave approved/rejected · salary changed · payroll approved · payroll lock reversed · expense created/edited · finance record deleted · permission changed · user login · configuration changes.

Each record: actor, event type, target type/id, old value, new value (JSON), timestamp, IP/device metadata. **Every phase's tests assert the audit rows its events produce** (Phase 0: user login, role change, settings; Phase 1: project created, price changed; Phase 2: task assigned/reassigned/deleted, status changed; Phase 5: leave approved/rejected; Phase 8: expense created/edited, finance record deleted; Phase 9: salary changed, payroll approved, lock reversed; Phase 12: employee created, permission changed).

---

# PART D — DOMAIN SPECIFICATION (condensed from the spec; authoritative for the build)

## 1. Roles

`ADMIN`, `MANAGER` (dormant), `EMPLOYEE`, `REMOTE_EMPLOYEE`, `ACCOUNTANT`. Stored in `roles` (table-driven, `employees.role_id` FK). `employees.tracking_mode ∈ {remote_timer, office_attendance, none}` is a per-employee field, not a role rule. REMOTE_EMPLOYEE and EMPLOYEE stay separate roles because their primary dashboard widget and sidebar item differ (Timer vs Attendance).

## 2. Navigation

**Admin sidebar**

```text
MY WORK           — My Tasks · Due Today · Overdue · My Attendance · My Leave
COMPANY DASHBOARD — agency-wide overview
WORK              — Clients · Projects · Tasks · Calendar · Meetings · Team · Messages
WORKFORCE         — Employees · Attendance · Time · Workload · Leave · Work Schedule   (Time and Workload added — decision, see table below)
REPORTS           — Task · Employee · Project · Time · Attendance · Performance
FINANCE           — Income · Expenses · Payroll · Financial Reports
ADMIN             — Users & Roles · Notifications · Settings · Audit Log
```

**Employee sidebar:** Dashboard · My Tasks · Projects · Calendar · Meetings · Messages · Notifications · Attendance (or **Time** if remote) · Leave · My Reports · Profile

**Accountant sidebar (separate shell):** Dashboard (finance-only) · Income · Expenses · Payroll · Financial Reports · My Leave · My Payslip (the last two per Part C §1)

**Where each sidebar item is built, and two placements the spec leaves open:**

| Item | Phase | Note |
| --- | --- | --- |
| WORK → Team | 6 | Team directory: name, role, availability today (present / remote-tracking / on leave), DM button — no salary, no tracking data |
| WORKFORCE → Employees | 12 | Users & Roles is the same screen family (Employees list → employee detail → role/schedule/tracking_mode) |
| WORKFORCE → **Time** and **Workload** | 4 | Not in spec §24; placed under WORKFORCE (decision) — Admin timer/timesheet/activity views and the workload view |
| REPORTS → Performance | 10 | Spec §21 has no "Performance" report; defined here as **project performance**: estimated vs tracked hours per project, completion rate per project/period, overdue rate — per project, never per person (spec §30 forbids scoring) |
| ADMIN → Notifications | 12 | Notification defaults per event type / channel |

## 3. Dashboards

**Admin (Company):** Row 1 stat cards — Active employees, Present today, On leave, Absent, Tasks due today, Overdue tasks, Tasks awaiting review, Tasks completed today, Active projects, Unread messages, Upcoming meetings, plus (spec §50 AC2) a **Remote time today** card per remote employee — "Tapu 4h 18m / 5h", plus two cards taken from the design references (recorded additions, Part I): **Pending approvals** (leave requests + manual time entries + tasks awaiting review, with links) and **Upcoming holidays**. Row 2 — Tasks by status (donut), Tasks by employee (bar), Projects by type (bar), Upcoming deadlines (list) — that is the 3-chart budget; no attendance donut, no activity feed on this screen (spec §22 excludes surveillance-style feeds). Row 3 (ADMIN only) — This month's income, expense, payroll, operating result. No productivity scores.

**Employee:** My Tasks, Due Today, Overdue, In Progress, Completed; secondary: My Schedule, Upcoming Meetings, Upcoming holidays (recorded addition from Part I), Notifications, Recent Activity; role card: Tapu "Time Today — 4h 18m / 5h Target", Yaseen "Attendance Today — Present, 8:58 AM". Served by a dedicated `/me/dashboard` endpoint scoped to the requesting user.

**My Work (Admins too):** same personal view as the Employee dashboard, as a separate destination beside Company.

**Accountant:** finance-only summary.

UX principle: each home screen answers one question — Employee "What should I do now?", Admin "What needs attention?", Accountant "What came in and what went out?", Founder "How is the agency operating?". Plain English states ("Waiting", not "Blocked/Impediment"), inline validation, one consistent filter/sort pattern on every list.

## 4. Clients & Projects

- Client: name, contacts (PII encrypted at rest), commercial notes (admin-only), many projects.
- Project: client (nullable → "Internal"), name, domain, project type (SEO, Website Maintenance, Website Development, WooCommerce, Web Application, Marketing, Internal/Company, Other), billing type (One-Time, Monthly Recurring, Custom Recurring), start date, deadline, priority, status (incl. Cancelled, Archived), PM, members, `internal_notes`, `employee_notes`. Financial fields (price, recurring amount, billing frequency, contract value, profitability snapshot) live in `project_finance`.
- Archived project = read-only, hidden from active dashboards, retained for reports; Admin can unarchive. Cancelled project = open tasks prompted for bulk-close/reassign; recurring generation stops immediately.

## 5. Tasks

Statuses: `BACKLOG → TO DO → IN PROGRESS → IN REVIEW → COMPLETED`, plus `WAITING/BLOCKED`, `CHANGES REQUESTED` (→ back to IN PROGRESS), `CANCELLED` (any point).

Fields: title, description, project, assignee(s) with one **primary**, priority, **start date** (v1.1, for Gantt/Calendar), due date, created date + `created_by`, status, estimated duration, actual tracked time (timer roles), checklist/subtasks, **tags** (v1.1 — colour-coded labels; Admin/Manager create them, global or scoped to one project; employees only assign existing tags), attachments, links (`task_links`: url + label), discussion (the task's conversation — see §10), activity history, dependencies, recurring rule link + period, completed-by, completed-at, mandatory **work summary** on submit-for-review/completion, `position` (manual order inside a Kanban column), `archived_at`.

Rules: Admin approves (→ COMPLETED) or requests changes (→ IN PROGRESS). **Reviewer** = the project's PM; if none, every ADMIN (used for "notify reviewer"); "original assigner" = `created_by`. Two assignees: both see/edit; completion requires the primary's work summary (or an explicit hand-off). Reopen: Completed → In Progress, logged with actor + reason; original completion timestamp and summary preserved in history. Overdue: a task is overdue when `due_date < today` and status ∉ {COMPLETED, CANCELLED} — computed at query time for the buckets; the daily `hq:flag-overdue` job only sends the notifications (once per task, not per day). Assignee on leave: task flagged "assignee on leave" for Admin — never auto-reassigned. **Delete** (ADMIN/MANAGER, audit-logged, soft-delete so history survives) and **Archive** (hides from active views; Admin can unarchive) exist from Phase 2.

**Drag rules on Board / Calendar / Gantt:** every drag goes through `TaskService` with the same transition and permission checks as the form. An employee may drag only between the statuses they may set (TO DO ↔ IN PROGRESS ↔ WAITING, and to IN REVIEW — which opens the work-summary modal before the move commits); dragging to COMPLETED is Admin/Manager only. Date drags (Calendar/Gantt) are Admin/Manager only; employees see the handles disabled.

Views (ClickUp parity, v1.1 — the client runs List / Board / Gantt / Calendar in ClickUp today):

- **List** — grouped by status (collapsible groups with counts, exactly like the ClickUp screenshot), columns Name · Assignee · Status · Due date · Time tracked · Tags · Subtask count; standard filter/sort; "Add Task" inline at the bottom of each group.
- **Board (Kanban)** — columns = statuses, drag between statuses; card shows project (domain for employees), title, assignees, due date, priority, tags, checklist/comment/attachment counts (Moru card pattern, Part I).
- **Calendar** — month/week grid of tasks by due date (and start→due span when a start date exists); drag to reschedule; meetings from Phase 7 overlay as a toggle.
- **Gantt** — horizontal timeline per task from start date to due date, grouped by project, dependency arrows from `task_dependencies`, drag to move/resize. Built in Phase 10 (needs dependencies + start dates to be populated first).
- **Task detail** — drawer/page with tabs (Details, Checklist, Files, Discussion, Activity, Time).

Group-by (status / assignee / project / priority) and "show subtasks" are toggles on List and Board, as in ClickUp. Every view uses the same filter bar.

## 6. Recurring Task Engine

Template under a project: title template, checklist template, recurrence rule (monthly / weekly / custom), default assignee, `next_run_at`. On rollover (scheduler, e.g. 1st of month): generate instance(s) → if an open instance for the current period already exists, **do not duplicate**, log a warning for Admin. The period identity is `tasks.recurring_period` (e.g. `2026-10` or `2026-W41`) with a **unique index on (`recurring_task_id`, `recurring_period`)** — the duplicate check is a DB constraint, not only code. Previous period's still-open task is flagged, not silently duplicated. New task lands in assignee's TO DO with due date per rule + notification. Stops immediately when the project is cancelled/ended.

The spec (§31) suggests "a cron-triggered job + a rules table"; the automation rule set (§19 below) is fixed and small, so it is **implemented as scheduled commands + event listeners with no `automation_rules` table** (decision recorded; a table adds nothing until Admin-editable rules exist, which spec §46 defers).

Example: *abc.com — Monthly Maintenance* → 8-item checklist (WordPress updates, plugin updates, backup verification, security check, broken-link check, performance check, form testing, general maintenance).

## 7. Remote timer (REMOTE_EMPLOYEE)

Open task → **Start** → running → **Pause/Resume** → **Stop** → `time_entries` row (employee, project, task, date, start, end, duration, pause duration, entry_type=auto). Dashboard: "4h 18m / 5h". Manual entry: requires reason, `entry_type=manual`; `settings.manual_time_requires_approval` (default **true**) routes it to Admin approval before it counts in totals. Admin views: hours today/week, by employee/project/task — for capacity planning, **not** productivity scoring.

**Safeguards (two rules, both flag the entry for review):**

1. **Heartbeat timeout** — the running client (web widget or extension) pings every 60 s. If no ping arrives for `settings.heartbeat_timeout_minutes` (default 5) the server **stops the entry at the last heartbeat** and flags it "stopped: connection lost" — a closed laptop never logs hours.
2. **Max session** — a single entry running longer than `settings.timer_max_session_hours` (default 10) even with heartbeats is paused and flagged "review: very long session".

Offline: the client keeps its state (start, pauses, pending heartbeats) in `localStorage` / `chrome.storage.local` with a client-generated `time_entries.client_uuid`, and replays on reconnect; the server is idempotent on that uuid and, when the client replays a stretch the server already stopped by rule 1, extends the entry only up to the replayed heartbeats (server wins on conflicts).

**Timesheet (v1.1, ClickUp parity):** a weekly grid — rows = tasks/projects the employee worked on, columns = Mon…Sun, cells = tracked hours (from `time_entries`), row and day totals, target line (5 h/day for Tapu), "Add time" in a cell opens the manual-entry form. Employee sees own week; Admin sees any employee. It is a view over `time_entries`, not a new data source. (Exports are spec post-MVP Phase 2 — Part H.)

## 7a. Activity tracking for remote timer users (v1.1 — client-requested, browser extension)

**Requirement as given by the client:** while the remote timer is running, the system must know whether the employee is actually active. Being inactive **while watching a video** (a tutorial, a client call recording) is acceptable; being inactive at any other time is not.

**Spec conflict — read before building:** spec §46 lists "keylogging, hidden monitoring, or unrequested screenshot capture" as must-not-build, and §12/§30 forbid productivity scoring. Activity-state tracking is **not** hidden (the employee installs and sees it), captures **no** content (no keystrokes, no URLs, no screenshots) and produces **no score** — but it is monitoring, so the client must amend §46 in writing before Phase 11 starts. Record the amendment in `PROGRESS.md` → Decisions. Until then Phase 11 is planned but not built.

**Form factor: a Chrome/Edge extension (Manifest V3)** — not a desktop app. Reasons: the remote timer already lives in the browser; `chrome.idle` reports system-wide keyboard/mouse idle (not just the browser); a content script can tell whether a video is playing in the focused tab, which is exactly the rule the client asked for; no screenshots means no desktop agent is needed.

**Behaviour:**

- **Pairing:** Profile → "Connect timer extension" shows a **10-minute, single-use code**. The extension posts it to `POST /api/extension/exchange` (unauthenticated, rate-limited 5/min per IP) and receives a Sanctum personal access token with abilities `timer:read-tasks`, `timer:track`, `timer:heartbeat` only — it cannot read projects, finance or anything else. A `devices` row records the pairing; token revocable from Profile and by Admin; deactivating the employee revokes it. The API lives in `routes/api.php` (created by `php artisan install:api` in Phase 11); the extension's manifest declares `host_permissions` for the app's origin.
- Popup = the timer widget (task picker from assigned tasks, Start / Pause / Stop, today's total vs target). The web app's timer widget and the extension share the same `time_entries` and the same "one running timer" rule — starting in one shows as running in the other.
- **Background service worker (MV3 workers are killed after ~30 s idle, so no `setInterval`):** `chrome.alarms.create('heartbeat', {periodInMinutes: 1})`; in the alarm handler call `chrome.idle.queryState(60)` and read the media flag for the focused tab — every frame's content script (top frame and iframes, `all_frames: true`) posts `{videoPlaying}` to the worker on change, the worker keeps the latest value per frame and ORs them per tab (a `chrome.tabs.sendMessage` round-trip would return only the first frame's answer) — then send one heartbeat `{time_entry_id, client_uuid, minute, state}` with `state ∈ {active, idle, video}`. `chrome.idle.onStateChanged` is also subscribed so a lock/sleep transition is recorded immediately.
  - `active` — `queryState` returned `active`.
  - `video` — no input, **but** the focused tab reports a `<video>` element that is playing (`!paused && !ended && readyState ≥ 2`). Only `<video>` counts — audio-only playback (music) does **not** excuse idleness; that matches the client's rule ("watching a video") and is confirmed at GATE F. Detected on any site the content script can run on — YouTube, Loom, Drive, Meet recordings — without recording which site.
  - `idle` — no input, no playing video. `chrome.idle` states `idle` and `locked` both map here; a stretch with **no heartbeats at all** (laptop asleep, browser closed) is counted as `idle` server-side up to the heartbeat-timeout stop (Part D §7 rule 1).
- **Content script permission, stated honestly:** reporting "is a video playing" on any site requires a content script with `<all_urls>` (+ `all_frames`) host permission, and Chrome will show the "read and change all your data on all websites" warning at install. The script reads one boolean and sends nothing else; the privacy text (below) says exactly this. `activeTab` is **not** sufficient (it only grants access after a user gesture). Fallback if the client rejects that permission: `chrome.tabs.query({active: true})` → `tab.audible` (no host permission; misses muted video and counts audio as "video") — a GATE F decision.
- Idle rule: `settings.idle_pause_minutes` (default 5) consecutive `idle` minutes → timer auto-pauses, the extension shows "You were idle for N min — keep this time or discard it?"; discarded minutes are subtracted and logged. `video` minutes never trigger the pause.
- Heartbeats are stored as `activity_samples` (one row per minute, per running entry, unique on entry + minute). The entry's `active_minutes`, `video_minutes`, `idle_minutes` are **rolled up on every pause and stop** (so Admin views are current), with a nightly reconciliation job as a safety net. `activity_source ∈ {extension, web}`. Entries tracked from the web timer without the extension carry `activity_source = web` and a "no activity data" badge — the web timer keeps working; the extension is the expected path for REMOTE_EMPLOYEE.
- Offline: heartbeats queue in `chrome.storage.local` and replay on reconnect (idempotent on `client_uuid + minute`).

**What is shown and to whom — the "activity graph" (confirmed by the client 17 Sep):** the point of the extension is one chart Admin can read at a glance: *when was this person working, and when was the timer on while they sat idle*. Build it as:

- **Day timeline** (Admin → Workforce → Time → employee → day, and the employee's own Time page): one horizontal bar per timer session from start to stop, coloured per minute — green `active`, blue `video`, grey `idle`, with pause gaps blank; hover shows the minute and state; a summary line "6h 10m tracked · 5h 02m active · 38m video · 30m idle (8 %)".
- **Week view** (same pages): seven stacked bars (active / video / idle minutes per day) with the daily target line, so a week of "timer on, nobody there" is visible without opening each day.
- **Admin overview** (Admin → Workforce → Time): a table of remote employees for the selected week — tracked, active, video, idle, idle % — sortable, with entries above `settings.idle_flag_percent` (default 25 %) marked for review. That is the whole analytics: no ranking, no score, no comparison chart between people.

Admin also gets idle % per entry and a list of entries above the threshold. **No** URLs, window titles, keystrokes, screenshots, or per-site breakdowns are collected or shown, and no ranking or score is derived — workload views stay raw counts/hours.

**Never collected:** page URLs, page titles, keystroke content, screenshots, clipboard, other tabs' state. The content script reports one boolean (media playing) and nothing else. Put this sentence in the extension's description and in Profile → "Connect timer extension".

## 8. Office attendance (office employees, incl. both Admins)

Clock in → clock out → `attendance_records` (date, in, out, status). Statuses: Present, Late, Absent, Leave, Holiday, Half Day, Remote, Off Day. How each status is set:

- **Present / Late** — on clock-in; Late when clock-in is later than the schedule's `start_time` + `settings.late_grace_minutes`.
- **Half Day** — Admin marks it (or clock-out before half of `working_hours_per_day`, when the setting `half_day_auto` is on; default off).
- **Absent** — the end-of-day job (`hq:mark-absent`, 23:55 app time) for every office employee whose schedule says today is a working day and who has no record, **skipping** days covered by an approved leave, a holiday, or an off day.
- **Leave** — written by leave approval (Phase 5). **Holiday** — from the `holidays` table (Phase 5). **Off Day** — from the schedule's non-working days.
- **Remote** — derived, never clocked: for `tracking_mode = remote_timer` employees, a day with at least one `time_entry` is Remote in `daily_work_summary` and the roster (Tapu never clocks in).

Admin dashboard: "Today's Attendance — Yaseen: Present, 8:58 AM".

## 9. Leave

Types: Annual, Sick, Emergency, Personal, Unpaid, Other. Per-employee balance per type for Annual / Sick / Emergency / Personal (seeded per employee by Admin; no accrual logic in MVP — Admin adjusts balances, audit-logged); **Unpaid and Other have no balance cap**. `leave_requests.status ∈ {pending, approved, rejected, correction_requested}`; overlapping pending/approved requests are refused. Apply (type, dates, reason) → Admin/Manager Approve / Reject / Request Correction → on approval: calendar shows unavailable, attendance auto-marked "Leave" for those dates, `leave_requests.unpaid_days` computed for Unpaid type (Phase 9 payroll reads it), balance decremented, history retained. A `holidays` table (date, name) drives the Holiday attendance status, the calendars' holiday markers and the dashboard "Upcoming holidays" card.

## 10. Communication

Conversation types: `team` (company-wide), `project` (members), `task` (inline in task detail), `dm` (1:1), `announcement` (admin broadcast, optional read receipts). Every message: text, links, file/image attachments, @mentions, **voice messages**. Task/project discussion is permanently linked to its object and appears in its activity history.

**One store for discussion (decision):** the spec lists both a `task_comments` table (§32) and a `task`-type conversation (§15/§32). To avoid two stores and a later migration, **every task gets a `conversations` row of type `task` when it is created (Phase 2)** and "comments" are its `messages`. `task_comments` is not created. Phase 2 builds the minimum of the communication tables (text + file attachments, `task` type only); Phase 6 adds the other types, voice, mentions UI and realtime on the same tables.

Voice message: composer has a hold-to-record / tap-to-record mic → live waveform + timer → preview (play / re-record / send) → posts as an inline audio bubble with duration, playable at 1×/1.5×/2×. Captured client-side with MediaRecorder (Opus/WebM or AAC/M4A), uploaded through the same files pipeline, `message_attachments.kind = voice` + `duration_seconds`. No transcription in MVP.

Realtime channels: `conversation.{id}`, `notifications.{user_id}`, `task.{id}`.

## 11. Notifications

One central engine: event → recipients → priority (High/Normal/Low) → dedup/group → stored row → realtime push. Channels in MVP: **in-app only** (spec §43); browser push and email digests are spec post-MVP Phase 2 (§44) — the engine keeps a `channels` list per notification type so they can be switched on later without a rewrite, but no Web Push or mail sending is built. Notification Center tabs: All / Tasks / Messages / Meetings / Leave / Payroll / System; unread badge; mark read / mark all read; deep-link to the object. **Dedup rule:** one event = one notification; repeated events on the same object within `settings.notification_group_window_minutes` (default 2) collapse into one row with an updated count — "12 new comments in [task]" — grouped at dispatch time (not display time).

## 12. Meetings

Create: title, date/time, participants, linked project/task, agenda → Meet link (MVP: "Create Meet Link" opens Google's instant-meeting flow and the organizer pastes the link back, **or** a one-way Calendar API push that creates the event + conferenceData) → participants notified (in-app + calendar invite when API is configured) → reminder 15 min before → notes + decisions recorded → action items → **Convert to Task** (tasks get `source_meeting_id`). Cancel: status Cancelled, participants notified, linked tasks not deleted, and — with the API driver — the Google event is **deleted/cancelled through the API** (spec §47 "Calendar event cancelled via API"); with the manual driver the record notes "cancel the Meet manually".

**Permissions (spec §17 says "Admin/Employee creates"; the matrix is silent):** any non-Accountant active user creates a meeting; the organizer and any Admin edit or cancel it; participants see it; the Accountant has no meetings.

**Google API driver, the honest version:** creating an event with `conferenceData` needs a Google Workspace calendar. Either the organizer connects their own Google account once (OAuth, tokens stored encrypted per user) **or** a service account with **domain-wide delegation** impersonates a Workspace user — a plain service account cannot create Meet links. Decide at GATE A (PROGRESS.md question 3); the manual driver is always available.

## 13. Finance & Accountant

Income (category: Maintenance / SEO / Website / Other; optional project link) and Expenses (Payroll / Office / Hosting / Software / Marketing / Utilities / Operations / Project Cost / Other). Monthly totals per category auto-computed. Admin sees income/expense/net by category, by project, trend. Example rollup: September 2026 — Maintenance $860, SEO $800, Website $1,250 → $2,910. Accountant edits are audit-logged with old/new values and are blocked inside a **locked payroll period** — defined as: the record's `date` falls in the month of a `payroll_periods` row whose status is `LOCKED` or `PAID` (Phase 9 adds the table and the check; Phase 8 ships without it).

**Accountant's view of projects:** the matrix says ❌ "view projects" and ❌ clients, but 🟡 "view project finance, read-only, linked to invoicing". Implemented as a **dedicated finance-only endpoint** (`/accountant/projects` → id, project name, domain, `project_finance` fields, read-only — **no client name or contacts**, AC5) used by the income form's project picker and the finance-by-project report; the Accountant gets 403 on every ordinary project route.

## 14. Payroll

State machine per `payroll_periods.status`: `DRAFT → CALCULATED → REVIEWED → APPROVED → LOCKED → PAID`. Draft auto-created on the 1st for all active employees from `employee_salaries` (current base + allowances, with history; changes audit-logged as "salary changed"). Accountant fills/adjusts base salary, allowance, bonus, deduction, advance; Calculate applies leave-impact rules (`leave_requests.unpaid_days` in the period × daily rate = `leave_impact`); Admin reviews and approves (read-only to Accountant afterwards); Lock closes the period; only ADMIN can reverse a lock (requires reason, audit-logged); Paid → payslip generated (browser print view + PDF via dompdf — decision) and released. `payroll_items.admin_notes` is the "personal notes" column the Accountant never receives. Each employee sees **only their own** payslip (query-level scope).

## 15. Reports

Admin: Task, Employee Work, Project, Overdue, Completion, Time, Attendance, Leave, Maintenance, SEO, Website Project, Meeting, Finance, Payroll — build the **8 MVP core reports first** (spec §43: Task, Employee Work, Project, Overdue, Attendance, Time, Finance, Payroll), then the other six in the same phase. Plus **In-app coordination** (v1.2, for AC6): messages per week in task/project conversations vs team/DM, per project — a count, not a score; the 80 % judgement itself is the client's. Employee (self-scoped): My Tasks, Completed, Pending, Overdue, Time (timer roles), Weekly/Monthly work summary. All reports query the live tables — no separate reporting DB. PDF/CSV export is spec post-MVP Phase 2 (§44) — not built.

## 16. Files

Attachments on tasks, projects, messages: upload, inline preview (images/PDF), download, delete (uploader or Admin), basic version history for re-uploads with the same logical name. Object storage; DB stores metadata only.

## 17. Search

Global search over Projects, Tasks, Messages, Meetings, Employees, Clients (where permitted), Files. Query is scoped to the requester's accessible object IDs **before** ranking. Postgres tsvector.

## 18. Workload

Admin view: task count per employee, overdue per employee, available capacity, projects with too much pending work; estimated vs tracked hours where applicable. No score.

## 19. Automation rules (fixed set; scheduler + queue jobs, no workflow engine)

| Trigger | Action |
| --- | --- |
| Task due tomorrow | Notify assignee |
| Task overdue | Notify assignee + manager/admin |
| Recurring period rolls over | Generate next task instance |
| Leave approved | Update calendar + attendance |
| Meeting created | Notify participants, create Meet link |
| Task marked In Review | Notify reviewer |
| Task completed | Notify original assigner/reviewer |
| Payroll period starts | Auto-create payroll draft |
| Recurring project cycle starts | Generate that period's batch |

## 20. Data model (authoritative table list — migrations are added in the phase that first needs them)

Column names follow Laravel conventions where the spec's names would not work in PHP (`password` not `password_hash`; `two_factor_secret` not `2fa_secret` — an identifier cannot start with a digit). `price`, `recurring_amount`, `billing_frequency` live in `project_finance`, not `projects` (spec §32 keeps them on `projects`; moved per spec Appendix I decision 1 so the privacy rule is a join to omit — recorded as a deliberate deviation). Every table has `id`, `created_at`, `updated_at` unless noted. **(Phase N)** marks columns/tables that are created only in that phase.

**Identity & Access:** `users` (name, email, password, two_factor_secret, two_factor_recovery_codes encrypted JSON, two_factor_confirmed_at, timezone, status, last_login_at) · `roles` (name) · `permissions` (key) · `role_permissions` · `user_project_permissions` (user_id, project_id, permission_id) · `login_history` (user_id, ip, user_agent, succeeded, created_at) · `sessions` (Laravel's) · `personal_access_tokens` (Sanctum, **Phase 11**) · extension pairing codes are **cache entries** (`extension_pair:<code>` → user_id, TTL 10 min, deleted on use — no table)

**Workforce:** `employees` (user_id, employee_number, role_id, manager_id, phone, employment_type, tracking_mode, joining_date, status) · `schedules` (employee_id, working_days JSON, working_hours_per_day, start_time, office_or_remote) · `holidays` (date, name — **Phase 5**) · `attendance_records` (employee_id, date, clock_in, clock_out, status, note, edited_by) · `time_entries` (employee_id, project_id, task_id, client_uuid unique, date, start_time, end_time, duration_minutes, pause_minutes, entry_type[auto|manual], manual_reason, approved_by, approved_at, flagged, flag_reason, last_heartbeat_at; **Phase 11:** active_minutes, video_minutes, idle_minutes, activity_source[extension|web], pending_idle_decision JSON {from, to, minutes} nullable) · `activity_samples` (time_entry_id, minute_at, state[active|idle|video], unique(time_entry_id, minute_at) — **Phase 11**) · `devices` (user_id, kind[extension], name, token_id, last_seen_at, revoked_at — **Phase 11**) · `leave_types` (name, has_balance) · `leave_balances` (employee_id, leave_type_id, balance_days) · `leave_requests` (employee_id, leave_type_id, start_date, end_date, days, unpaid_days, reason, status[pending|approved|rejected|correction_requested], approver_id, decided_at, decision_note)

**Clients & Projects:** `clients` (name, contact_info encrypted JSON, internal_notes, status) · `projects` (client_id nullable, name, domain, project_type, billing_type, start_date, deadline, status, priority, pm_id, internal_notes, employee_notes, archived_at) · `project_finance` (project_id unique, price, recurring_amount, billing_frequency, contract_value, contract_terms, profitability_snapshot) · `project_members` (project_id, employee_id, role_on_project)

**Tasks:** `tasks` (project_id, title, description, status, priority, start_date, due_date, position, estimated_minutes, actual_minutes, created_by, completed_by, completed_at, work_summary, archived_at, deleted_at; **Phase 3:** recurring_task_id FK, recurring_period, unique(recurring_task_id, recurring_period); **Phase 7:** source_meeting_id FK) · `task_assignees` (task_id, employee_id, is_primary) · `task_checklists` (task_id, item, is_done, position) · `task_dependencies` (task_id, depends_on_task_id) · `task_links` (task_id, url, label) · `task_attachments` (task_id, file_id) · `tags` (project_id nullable = global, name, colour) · `task_tags` (task_id, tag_id) · `recurring_tasks` (project_id, title_template, checklist_template JSON, recurrence_rule, next_run_at, default_assignee_id, active) · `recurring_generation_log` (recurring_task_id, period, outcome[generated|skipped_duplicate|skipped_inactive], task_id, created_at — **Phase 3**)

**Communication (Phase 2 creates the tables with `type = task` only; Phase 6 adds the rest):** `conversations` (type[team|project|task|dm|announcement], linked_project_id, linked_task_id, title) · `conversation_members` (conversation_id, user_id, last_read_at) · `messages` (conversation_id, author_id, body, created_at) · `message_attachments` (message_id, file_id, kind[file|image|voice], duration_seconds) · `message_mentions` (message_id, user_id — **Phase 6**)

**Meetings:** `meetings` (title, start_at, end_at, project_id, task_id, google_event_id, meet_link, organizer_id, agenda, status) · `meeting_participants` (meeting_id, user_id, rsvp_status) · `meeting_notes` (meeting_id, notes, decisions) · `google_oauth_tokens` (user_id, encrypted access/refresh tokens, expires_at — only with the OAuth variant)

**Finance & Payroll:** `finance_categories` (kind[income|expense], name) · `income` (project_id nullable, category_id, amount, date, notes, recorded_by) · `expenses` (category_id, amount, date, notes, recorded_by) · `employee_salaries` (employee_id, base_salary, allowance, effective_from, set_by — **Phase 9**) · `payroll_periods` (month, status[draft|calculated|reviewed|approved|locked|paid], locked_at, lock_reversed_by, lock_reversal_reason — **Phase 9**) · `payroll_items` (payroll_period_id, employee_id, base_salary, allowance, bonus, deduction, advance, leave_impact, net_salary, admin_notes — **Phase 9**)

**Platform:** `notifications` (user_id, type, payload JSON, group_key, count, is_read, read_at, created_at) · `notification_preferences` (type, channel, enabled — **Phase 12**) · `files` (storage_key, filename, mime_type, size, uploaded_by, linked_type, linked_id, version_of) · `audit_logs` (actor_id, event, target_type, target_id, old_value JSON, new_value JSON, ip, user_agent, created_at — **append-only**, no updated_at) · `activity_logs` (object_type, object_id, actor_id, description, created_at)

**`settings` (key/value, seeded in Phase 0 — this is the complete key list):** `timezone`, `currency`, `late_grace_minutes`, `half_day_auto`, `timer_max_session_hours`, `heartbeat_timeout_minutes`, `manual_time_requires_approval`, `notification_group_window_minutes`, `idle_pause_minutes`, `idle_flag_percent`, `backup_last_verified_at` (written by the backup job, read-only in the UI). Nothing else is added without a recorded decision. **Not settings, because they cannot switch at runtime:** the realtime driver (`BROADCAST_CONNECTION` + `VITE_REVERB_*`, baked at build time) and the Google Calendar driver + credentials (`GOOGLE_CALENDAR_DRIVER=manual|api`) live in `.env`; Admin → Settings shows them read-only. **Notification defaults** live in `notification_preferences` (type, channel, enabled — global defaults set by Admin, Phase 12; the engine reads them).

**Views:** `daily_work_summary` (per employee per day: attendance status or derived Remote, clock in/out, tracked minutes, leave/holiday flags — union of `attendance_records`, `time_entries`, `leave_requests`, `holidays`; reports only).

## 21. Edge cases the build must handle (from spec §47)

| Situation | Handling |
| --- | --- |
| Employee on leave with tasks due | Flag "assignee on leave" to Admin; no auto-reassign |
| Task overdue | Overdue bucket + notifications; stays actionable |
| Employee leaves company | `status=inactive`, login disabled, open tasks flagged for reassignment, history retained |
| Project cancelled | Open tasks prompted bulk-close/reassign; recurring generation stops |
| Recurring project ends | Rule deactivated; history intact |
| Role/team change | Permissions update on next request; assignments untouched |
| Two assignees | One primary; completion needs primary's summary or hand-off |
| Task reopened | Completed → In Progress, reason logged, original completion preserved |
| Timer left running | Heartbeat timeout (5 min) stops the entry at the last heartbeat; max-session (10 h) pauses it; both flag for review (Part D §7) |
| Forgot to stop timer | Edit with required reason; approval per `manual_time_requires_approval` |
| Manual time | Reason required; `entry_type=manual`; approval per `manual_time_requires_approval` (default on) |
| Offline (employee) | Timer state buffered client-side (`client_uuid`), synced on reconnect, server wins on conflict; unsaved edits warn before navigation |
| Lost session | Standard re-auth; comment drafts preserved client-side where feasible |
| Meeting cancelled | Status Cancelled; participants notified; action-item tasks kept; Google event cancelled via API (API driver) or noted for manual cancel |
| Project price change | Old/new value in audit log; past finance records untouched |
| Accountant edits finance | Allowed pre-lock, audit-logged; blocked in locked period |
| Payroll locked | Only ADMIN reverses, with reason, logged |
| Salary/price access attempt by wrong role | Blocked at query/serializer; field absent; attempt logged |
| Project archived | Read-only; out of active views; unarchivable |
| Recurring duplicate | Existing-open-instance check; warning logged |
| Onboarding | Employee created with role, schedule, tracking_mode; default permissions; welcome notification; optional orientation checklist task |

---

# PART E — VERTICAL SLICE PHASES

Every phase below is one complete feature. Each lists: **Goal · Screens · Backend · DB · Security/Privacy checks · Tests · Done when · Gate**. Build exactly in this order. Do not start a phase before the previous one is green and recorded in `PROGRESS.md`.

## Phase overview

| Phase | Vertical slice | Spec sections | Gate |
| --- | --- | --- | --- |
| 0 | Foundation: auth + 2FA, roles, three shells, audit/activity logs, CI, VPS deploy kit, backups | 5, 6, 27, 33–37, 48 | **GATE A** |
| 1 | Clients & Projects with the privacy model | 7, 8, 25, 26, 32 | **GATE B** |
| 2 | Tasks: List (grouped) · Board · Calendar · tags · files · task discussion · in-app notifications | 9, 11, 16, 28, 38 | — |
| 3 | Recurring task engine + fixed automation rules | 10, 31, 41 | — |
| 4 | Remote timer + Timesheet + office attendance + schedules + workload | 12, 13, 30 | **GATE C** |
| 5 | Leave management + holidays | 14 | — |
| 6 | Team communication (team/project chat, DMs, announcements, voice, Team directory) + realtime | 15, 35 | **GATE D** |
| 7 | Meetings + Google Meet (one-way) + action items → tasks | 17 | — |
| 8 | Finance module + Accountant surface | 18, 19 | — |
| 9 | Payroll state machine + payslips | 20 | **GATE E** |
| 10 | Reports + global search + Gantt view + dashboards finalized | 21, 22, 23, 29 | — |
| 11 | Activity-tracking browser extension (blocked until the client amends spec §46) | D §7a | **GATE F** |
| 12 | Admin tools (Users & Roles, audit viewer, settings, notification defaults), backup health, UX polish pass, migration import, hardening, cutover | 36, 37, 49 Phase 5–6, 50 | **Final review** |

This table is the single source for phase names — `PROGRESS.md` copies it verbatim. Estimated: ~18 weeks for 1–2 developers (spec §49) plus ~2 weeks for Phase 11. Phases 0–3 deliver the ClickUp/Asana replacement first, by design. Phase 11 is built only after the client's written amendment to spec §46 (Part D §7a); if that never comes, skip it and renumber nothing — Phase 12 still runs.

---

## Phase 0 — Foundation: auth + 2FA, roles, three shells, audit/activity logs, CI, VPS deploy kit, backups

**Goal:** the five real users can log in and land in the correct shell (Admin / Employee / Accountant) with placeholder pages; the permission engine, audit log and deploy kit exist and are tested.

**Screens:** Login (email + password, "remember me", rate-limited, friendly errors) → **2FA challenge** (TOTP) for users with 2FA enabled → **forced 2FA enrolment** (QR + recovery codes) at first login for ADMIN and ACCOUNTANT; optional enrolment from Profile for others. Three layouts: `AdminLayout` (sidebar per Part D §2 with all items rendered, non-built items disabled with "arrives in Phase N"), `EmployeeLayout`, `AccountantLayout` (incl. My Leave / My Payslip placeholders). Placeholder dashboards per shell. Profile page (name, email, timezone, change password, 2FA enrol/disable with password confirm, active sessions list with revoke, login history).

**Backend:** Laravel scaffold (current supported major — Part B §1) with Inertia + Vue 3 + Vite + Tailwind + Pest. `roles`, `permissions`, `role_permissions`, `user_project_permissions` seeded from the Part C §1 matrix with the dotted keys listed there. `EnsureSurface` middleware + route files per surface. `TwoFactor` middleware (TOTP via a maintained package, e.g. `pragmarx/google2fa-laravel`; recovery codes hashed). Policy base class with `can($permissionKey)` helper. `AuditLogger` service + `audit_logs` migration that creates the table (run as `hq_migrator` via `pgsql_migrator`) and immediately **`REVOKE UPDATE, DELETE, TRUNCATE ON audit_logs FROM hq_app`** (Part B §3 rule 3); `config/database.php` gains the `pgsql_migrator` and `pgsql_ro` connections; `phpunit.xml` uses `pgsql_migrator` as default. `ActivityLogger` + `activity_logs`. `settings` table seeded with **every key in Part D §20** and their defaults (Part H §2). Password breach check. Session revocation + `login_history` (with an audit row on every login). `resources/css/app.css`: Tailwind v4 + the shadcn variable set with the spec palette (Part I) as `--primary`/`--accent`/`--muted`/`--sidebar-*` in oklch, light + dark — derive the values with the `shadcn-tokens` MCP (`get_theme asCss:true` as the template) and shadcn-vue installed via its official MCP. Pest set up; `tests/Permissions/MatrixTest.php` with the **route-coverage guard** (fails when a registered route is missing from the matrix table). GitHub Actions `ci.yml`.

**DB:** users, roles, permissions, role_permissions, user_project_permissions, employees (with tracking_mode), schedules, audit_logs, activity_logs, settings, login_history, sessions.

**Seed:** `TeamSeeder` — Shahadat, Faruk (ADMIN, office_attendance), Tapu (REMOTE_EMPLOYEE, remote_timer, 5 h/day), Yaseen (EMPLOYEE, office_attendance), Accountant (ACCOUNTANT, none); MANAGER role exists, unassigned. Passwords from `.env` seed values (never hard-coded plain text in the repo). Seeded Admins/Accountant get a **pre-confirmed 2FA secret from `.env`** in local/dev only so tests and local logins are not blocked; production seeds force enrolment.

**Deploy kit:** `deploy/nginx.conf`, `deploy/supervisor/hq-queue.conf`, `deploy/supervisor/hq-reverb.conf`, `deploy/install.sh`, `deploy/deploy.sh`, `deploy/sql/roles.sql` (`hq_migrator`, `hq_app`, `hq_ro` with default privileges), `.env.production.example`, `docs/runbooks/install.md` (the same steps as `install.sh`, annotated), `spatie/laravel-backup` configured (daily, encrypted archive, retention 14/8/12, destination in a different provider account/region, file bucket versioning documented), `hq:verify-backup` command (weekly restore into `goodtechies_verify` scratch DB + row-count checks → writes `settings.backup_last_verified_at`). Backup health placeholder card in Admin → Settings.

**Security/Privacy checks:** Accountant cannot open any `/admin/*` or `/employee/*` route; Employee cannot open `/admin/*` or `/accountant/*`; Admin cannot change own role or deactivate self; an ADMIN/ACCOUNTANT without confirmed 2FA is redirected to enrolment from every route; audit_logs UPDATE/DELETE/TRUNCATE as `hq_app` fails at DB level (raw statements inside a savepoint, expecting `insufficient_privilege`).

**Tests:** login success/failure/rate limit; 2FA challenge success/failure/recovery code; forced enrolment for ADMIN/ACCOUNTANT; each role lands in the right shell; surface isolation matrix + route-coverage guard; self-role-change guard; audit rows for login, role change, settings change; audit log append-only at DB level; activity log write; session revoke; breach-check hook (mocked); backup verify command (with a small fixture dump); every settings key seeded with its default.

**Done when:** all five seeded users log in (Admins/Accountant through 2FA) and see their shell; tests green in CI; `deploy/install.sh` followed by `deploy/deploy.sh` brings the app up in a **fresh Ubuntu 24.04 container/VM** (transcript saved to `docs/runbooks/install-log-<date>.md`). **GATE A** — user checks login + shells + confirms the Part H §2 decisions (incl. Laravel major and Google driver).

---

## Phase 1 — Clients & Projects with the privacy model

**Goal:** Admin manages clients and projects; an assigned employee sees only the operational view. The field-level serializer is proven with negative tests.

**Screens (Admin):** Clients list/detail (contacts, commercial notes, projects tab, files tab placeholder, meeting history placeholder). Projects list (filter by client/type/status/PM), project create/edit (type, billing type, dates, priority, PM, members, `internal_notes`, `employee_notes`, **finance section (price, recurring amount, billing frequency, contract value) — ADMIN only**), project detail with tabs (Overview, Tasks placeholder, Files placeholder, Discussion placeholder, Activity). Archive / unarchive / cancel actions.

**Screens (Employee):** Projects list — only assigned projects, each showing domain (not client name), type, status, deadline, PM, `employee_notes`. Project detail same fields only.

**Backend:** `ClientResource`, `ProjectResource` (the ONE serializer: strips client name/contacts/finance/internal_notes unless requester has the permission or a project-level grant). `ProjectPolicy`, `ClientPolicy`. `project_finance` written only through `ProjectFinanceService` (audit-logs project created and price changes with old/new). Project status transitions (Active/On Hold/Completed/Cancelled/Archived) with the Part D §4 / §21 side effects (cancel → prompt for open tasks — until Phase 2 exists, record the intent in the service and test it in Phase 2). Activity log entries for create/update/member changes.

**DB:** clients, projects, project_finance, project_members.

**Seed (`DemoSeeder`):** Buffalo Modular (Website Development + SEO + Maintenance), Heat Gap Heating & Plumbing (SEO Retainer), APH St Albans (Website Maintenance), abc.com (Monthly Maintenance), Internal project. Tapu member of SEO projects, Yaseen of maintenance projects.

**Security/Privacy checks:** Employee/Remote fetching a project (page, Inertia props, and any JSON) receive **no** `client_name`, `contacts`, `price`, `recurring_amount`, `billing_frequency`, `contract_value`, `contract_terms`, `profitability`, `internal_notes` keys at all; unassigned project → 404; **Accountant → 403 on every project route** (the finance-only endpoint arrives in Phase 8 and is tested there); Admin sees all; a `user_project_permissions` grant of `projects.view_finance` exposes finance for that one project only; project created + price change audit-logged; PII encrypted at rest (assert ciphertext in DB).

**Tests:** CRUD per role; serializer negative tests for every restricted field on every project endpoint; project-level grant; archive/unarchive/cancel; activity log entries; audit rows.

**Done when:** Admin creates Buffalo Modular with three projects, Tapu sees `buffalomodular.com — SEO` and nothing commercial; tests green. **GATE B** — user reviews the privacy behaviour side by side (Admin vs Tapu login).

---

## Phase 2 — Tasks: List (grouped) · Board · Calendar · tags · files · task discussion · in-app notifications

**Goal:** the ClickUp/Asana replacement core: tasks are created, assigned, worked, submitted with a work summary, reviewed and completed, with files, comments and notifications.

**Screens:** Tasks **List** (grouped by status with collapsible groups and counts — the ClickUp pattern in `docs/design-refs/10-clickup-current-workspace.png`; columns Name · Assignee · Status · Due date · Time tracked · Tags · Subtasks; group-by toggle status/assignee/project/priority), **Board** (Kanban, drag & drop within the Part D §5 drag rules, Moru card pattern `07-employee-task-kanban-moru.png`), **Calendar** (month/week by due date; date drag Admin/Manager only). Quick-add task modal (`08-new-task-modal.png`: Task/Details tabs, name, project, description; responsive to phone width). Tags: Admin/Manager create (global or per project), colour; employees assign existing tags; filter by tag. Task detail (page + drawer): fields incl. start date, multi-assignee with primary, checklist, dependencies, tags, attachments (upload, inline preview image/PDF, download, delete, version history), links (`task_links`), **Discussion** = the task's conversation (text + file attachments; mentions and voice arrive in Phase 6), Activity timeline, submit-for-review with required work summary, Approve / Request changes (Admin), reopen with reason, **Delete** (Admin/Manager, confirm, soft-delete, audit-logged) and **Archive / Unarchive**. **Files tabs** on project and client detail (spec §7/§28 — same `FileService`). **My Tasks** page for every role incl. Admins (My Tasks / Due Today / Overdue / In Progress / Waiting / In Review / Completed with counts). Employee Dashboard main cards (the spec's five: My Tasks, Due Today, Overdue, In Progress, Completed — real counts). Admin Company dashboard: task cards (Tasks due today, Overdue, Awaiting review, Completed today, Active projects) — other cards stay "arrives in Phase N" placeholders. **Notification bell + Notification Center** (All / Tasks / … tabs, unread badge, mark read/all, deep links) — in-app only; realtime arrives in Phase 6 (bell polls every 15 s until then).

**Part 0.5 refresh rule:** the **task List and Board** (somebody else moves a card or closes a task while you are looking at the column) and the **Notification Center page** (the bell polls; the page behind it did not). The bell itself is this rule's reference implementation — decision 2-39.

**Backend:** `TaskService` (status machine with allowed transitions per role, primary-assignee rule, work-summary requirement, reopen logging, delete/archive, reorder by `position`), `TaskPolicy` (assigned only for employees), `FileService` (S3 upload via signed URLs, validation, metadata, version_of), `ConversationService` (creates the `task` conversation on task create; post message; file attachment), `NotificationService` + `NotificationDispatcher` listener + events `TaskAssigned`, `TaskReassigned`, `TaskStatusChanged`, `TaskCommented`, `TaskSubmittedForReview` (→ reviewer = PM, else Admins), `TaskCompleted` (→ `created_by` + reviewer), `TaskDeleted` with **dedup/grouping** (`group_key` + `count` within `notification_group_window_minutes`). `hq:flag-overdue` (daily 08:00: one notification per newly-overdue task to assignee + manager/Admin; the Overdue buckets themselves are query-time). Cancel-project side effect from Phase 1 now prompts bulk-close/reassign.

**DB:** tasks, task_assignees, task_checklists, task_dependencies, task_links, task_attachments, tags, task_tags, files, conversations, conversation_members, messages, message_attachments (type `task` only), notifications.

**Seed:** ~25 tasks across the demo projects in mixed statuses, some overdue, some in review, with start dates and tags (Branding, Development, SEO, Maintenance) and a few discussion messages — enough for Calendar (Phase 2) and Gantt (Phase 10) to look real.

**Security/Privacy checks:** Employee sees only assigned tasks (list, kanban, detail, files, discussion); Accountant has no task routes; file URLs are signed and expire; deleting a file allowed only for uploader/Admin; task detail never leaks project finance (serializer reused); an employee cannot drag to COMPLETED or change dates by drag; task delete is Admin/Manager only and audit-logged.

**Tests:** every status transition (allowed + forbidden, per role, incl. drag endpoints), work summary required, primary-assignee completion rule, reopen preserves original completion, overdue bucket query + one-time notifications, notification grouping (12 messages → 1 row, count 12), mark read, file upload validation/version history/signed URL expiry, kanban reorder (`position`), list group-by queries, calendar range query + Admin-only reschedule, tag CRUD/assign/filter scoped to project access, task conversation created on task create + membership follows task access, delete/archive + audit rows (assigned, reassigned, deleted, status changed), permission matrix extended.

**Done when:** Tapu opens "Optimize Home Model pages", works it, submits with a summary, Shahadat requests changes then approves; notifications appear for each step; tests green.

---

## Phase 3 — Recurring task engine + fixed automation rules

**Goal:** monthly retainer work generates itself with zero manual re-creation, for at least two simulated cycles.

**Screens (Admin):** Recurring task templates under a project (list, create/edit: title template, checklist template, recurrence monthly/weekly/custom (cron-like day-of-month/weekday), default assignee, active toggle, next run preview, "Generate now" button, generation log with duplicate warnings). Project detail → Recurring tab. Task detail shows "Generated from: <template> · period <Month YYYY>".

**Backend:** `RecurringTaskEngine` (period key per rule → `tasks.recurring_period`, existing-open-instance check backed by the unique index, previous-open-instance flag, stop on project cancelled/archived/ended, `recurring_generation_log` row per run), `hq:generate-recurring-tasks` scheduled daily 00:05, queue job per template, notification to assignee. The one automation rule from Part D §19 not yet covered: `TaskDueTomorrow` (daily 08:00, notify assignee). (Overdue, In-Review → reviewer and Completed → assigner notifications were built in Phase 2 — do not rebuild them.)

**DB:** recurring_tasks, recurring_generation_log, `tasks.recurring_period` + unique index (migration in this phase).

**Seed:** abc.com Monthly Maintenance (8-item checklist, Yaseen), Heat Gap Monthly SEO Tasks (Tapu), Buffalo Modular Monthly SEO (Tapu).

**Tests:** generation for a period; duplicate prevention (run twice → one task + warning log); previous-open flag; cancelled project stops generation; weekly rule; custom rule; time-travel two consecutive months (Carbon test now) → two instances; due-tomorrow notification; schedule registration asserted.

**Done when:** running the scheduler across two simulated months produces exactly the expected tasks with notifications; tests green.

---

## Phase 4 — Remote timer + Timesheet + office attendance + schedules + workload

**Goal:** Tapu's day is timer-tracked per task with the 5 h target visible to him and Admin; Yaseen and both Admins clock in/out; workload view exists.

**Screens:** **Timer widget** (REMOTE_EMPLOYEE only): on task detail and as a persistent bar — Start / Pause / Resume / Stop, live counter, today's total vs target, offline indicator, "Add manual entry" (start, end, task, reason). **Time page** (remote): entries by day, edit with reason, flagged entries with the flag reason. **Timesheet** (Part D §7): weekly grid tasks × days with totals and target line, "Add time" per cell; Admin → Workforce → Time → Timesheet per employee. **Attendance widget** (office roles incl. Admins): Clock In / Clock Out on dashboard, today's status. **Attendance page** (self): month calendar/list. **Admin → Workforce → Attendance:** today's roster ("Yaseen: Present, 8:58 AM"; Tapu shows "Remote — 2h 10m tracked"), month grid per employee, edit with reason (audit-logged), statuses per Part D §8. **Admin → Workforce → Work Schedule:** per-employee schedule editor. **Admin → Workforce → Time:** hours today/week by employee/project/task, flagged/manual entries approval queue. **Admin → Workforce → Workload:** task count per employee, overdue per employee, estimated vs tracked, projects with most pending work — counts only. Dashboard cards: "Time Today — 4h 18m / 5h Target" (Tapu), "Attendance Today — Present, 8:58 AM" (office), Admin Company cards Present today / Absent / **Remote time today per remote employee ("Tapu 4h 18m / 5h" — AC2)**; On leave stays a placeholder until Phase 5.

**Backend:** `TimerService` (one running timer per employee, pause accounting, stop → time_entry, heartbeat endpoint updating `last_heartbeat_at`, **rule 1** `hq:timer-watchdog` every minute: no heartbeat for `heartbeat_timeout_minutes` → stop at last heartbeat + flag; **rule 2** running longer than `timer_max_session_hours` → pause + flag; `client_uuid` idempotency and replay; manual entries with reason + approval per `manual_time_requires_approval`), `AttendanceService` (clock in/out, Late via schedule + `late_grace_minutes`, Half Day, `hq:mark-absent` at 23:55 for scheduled working days without a record — it skips approved leave/holidays once Phase 5 adds them, Off Day from schedule, derived Remote), `daily_work_summary` SQL view, workload queries.

**DB:** time_entries (all non-Phase-11 columns), attendance_records, (schedules from Phase 0), the view.

**Client behaviour:** timer state (start, pauses, `client_uuid`, pending heartbeats) persisted in `localStorage` and replayed on reconnect; heartbeat every 60 s while the tab is open; conflict rule = server wins.

**Screens in this phase that Part 0.5's refresh rule covers** — Admin → Workforce → **Attendance** (roster + status counts), the **Admin Company dashboard** workforce cards (Present today / Absent / Remote time today), **Attendance page (self)** (today's row, so a second tab agrees with the one that clocked in), and Admin → Workforce → **Time** (the flagged/manual approval queue, which an employee fills while the Admin is looking at it). Each polls per that rule until Phase 6's channel replaces it.

The timer's own 60 s heartbeat is a different thing and stays as it is: it *writes*, this *reads*.

**Security/Privacy checks:** only REMOTE_EMPLOYEE (own) can use the timer; office roles get no timer UI or endpoints (403); employees see only own attendance/time; Admin edits are audit-logged; no productivity score anywhere (grep test on the UI strings "score"/"productivity").

**Tests:** start/pause/resume/stop math incl. pause duration; one running timer rule; heartbeat-timeout stop at last heartbeat + flag; max-session pause + flag; manual entry needs reason; approval setting on/off affects totals; offline replay endpoint (idempotent on `client_uuid`, server wins); timesheet grid totals per row/day/week; clock in/out; Late vs Present via schedule + grace; Half Day; Absent job (with the Phase 5 skip rule added later); derived Remote status; roster query; Admin "Remote time today" card; workload numbers; view returns both sources correctly; audit rows for Admin attendance edits; the roster poll asks only for its own props, runs one interval however many components mount it, issues nothing while the tab is hidden and never stacks two requests; permission matrix.

**Done when:** Tapu tracks a real session end to end (incl. simulated offline), Yaseen clocks in/out, Admin sees both; tests green. **GATE C** — user tests timer + attendance on desktop and phone.

---

## Phase 5 — Leave management + holidays

**Goal:** apply → approve → calendar/attendance/payroll-impact flow with balances; company holidays.

**Screens:** Employee (and Accountant, in its own shell): Apply Leave (type, dates, reason), My Leave (balances per type, history, status). Admin: Leave requests queue (Approve / Reject / Request Correction with note), balances per employee (edit with audit), leave calendar, **Holidays** (Admin → Workforce → Leave → Holidays: date + name, seeded with the BD public-holiday list for the current year as a starting point — Admin edits), "assignee on leave" flags on task list/dashboard for tasks due in an approved window. Dashboard cards: On leave (Admin), Upcoming holidays (Admin + Employee), My Leave (My Work).

**Part 0.5 refresh rule:** **Admin → Leave** — every tab and its count (Waiting / Pending / Approved / Rejected / Correction requested). An employee filing a request is the exact case the rule exists for: the bell announces it while the queue behind still reads "Nothing waiting". Also the employee's **My Leave**, so a decision appears without a reload.

**Backend:** `LeaveService` (balance check for balance-capped types, overlapping request check, on approval: `attendance_records` auto-marked Leave for each working date, calendar availability, `leave_requests.unpaid_days` computed for Unpaid type (Phase 9 reads it), balance decrement, history), `hq:mark-absent` now skips approved leave and holidays, Holiday attendance status written for holidays, events `LeaveRequested`, `LeaveApproved/Rejected`, notifications.

**DB:** leave_types (seeded: Annual, Sick, Emergency, Personal with balances; Unpaid, Other without), leave_balances, leave_requests, holidays.

**Tests:** apply/approve/reject/correction; balance decrement and insufficient balance; Unpaid/Other uncapped; overlap refused; attendance auto-marked Leave; holidays → Holiday status and Absent job skips them; tasks flagged not reassigned; `unpaid_days` stored; audit rows (approved/rejected, balance edit); permission matrix (Accountant can apply for own leave only, in its own shell).

**Done when:** Tapu applies 2 days, Admin approves, calendar/attendance/task flag update with no manual step; tests green.

---

## Phase 6 — Team communication (team/project chat, DMs, announcements, voice, Team directory) + realtime

**Goal:** Telegram replacement: team chat, project chat, task discussion, DMs, announcements, voice messages, @mentions, realtime delivery.

**Screens:** Messages page (left: Team, Projects, Direct; right: thread with composer — text, attach, mic hold-to-record with waveform/timer/preview, @mention picker, links shown as plain links). Task detail Discussion tab (already the task conversation since Phase 2) gains mentions + voice. Project detail Discussion tab. Announcements (Admin compose; banner + notification; optional read receipts). **Team** page (WORK → Team; Employee sidebar via Messages → Team): directory with name, role, today's availability, DM button. Notification Center now realtime. **Every screen carrying a Part 0.5 poll drops it for its channel** — attendance (roster, Admin workforce cards, own Attendance page), leave (Admin queue and My Leave), tasks (List and Board), and the Notification Center page. Each poll is *removed*, not left running beside the subscription. (No browser push, no email — spec post-MVP Phase 2.)

**Backend:** the Phase 2 communication tables extended to all types; `message_mentions`; `MessageService`, `ConversationPolicy` (members only; task/project conversations follow task/project access; a `project` conversation is created when a project is created — backfill for existing projects). Laravel Reverb + Echo; channels `conversation.{id}`, `notifications.{user_id}`, `task.{id}`, `attendance.{date}` (the day’s roster — a clock-in, clock-out or Admin edit broadcasts the one changed row and the recomputed status counts; authorised by the same policy that serves Admin → Workforce → Attendance, so an employee can subscribe to nothing but their own row) and `leave` (a request filed or decided broadcasts the row and the recomputed tab counts; an employee receives only their own requests); auth callbacks enforce policies. **Every Part 0.5 poll standing at this point is deleted as its channel lands** — grep `router.reload` and account for each survivor; two mechanisms doing one job is the failure this phase exists to end, not a belt-and-braces. Polling fallback via `.env` `BROADCAST_CONNECTION=log` + `VITE_REALTIME=polling` (build-time). Mention → notification; message burst grouping. Voice: `message_attachments.kind=voice`, `duration_seconds`, same files pipeline, playback 1×/1.5×/2×.

**Security/Privacy checks:** Accountant has no messaging routes (the spec gives ACCOUNTANT no messaging access by default); non-member cannot subscribe to a channel (**403** from Laravel's broadcast auth); DMs are 1:1 between any two non-Accountant active users; project/task conversations are readable only by users who can access that project/task; attachments served via signed URLs.

**Tests:** send/receive per conversation type; membership policy; broadcast auth per channel (403 for non-members); mention notification; grouping; voice attachment metadata; project conversation created + backfilled; Team directory exposes no salary/tracking fields; polling fallback returns the same payload; a clock-in reaches an open Admin roster and a filed leave request reaches an open Admin queue, both with no reload, and no `router.reload` interval survives on either page; an employee is refused `attendance.{date}` and `leave` (403) and receives only their own rows; Reverb config in Supervisor + Nginx documented and smoke-tested on the VPS layout.

**Done when:** two browsers exchange messages live in team chat, a task discussion, and a DM; a voice note plays inline on phone width; tests green. **GATE D** — user checks chat + voice on phone and desktop.

---

## Phase 7 — Meetings + Google Meet (one-way) + action items → tasks

**Goal:** meetings are scheduled with a Meet link, participants notified and reminded, notes captured, action items become tasks.

**Screens:** Meetings list/calendar (month/week), create/edit (title, time, participants, linked project/task, agenda, Meet link field with **"Create Meet Link"** button), meeting detail (join link, participants + RSVP, notes, decisions, action items with "Convert to Task"), Cancel. Dashboard cards: Upcoming meetings.

**Backend:** `MeetingService` (permissions per Part D §12), events `MeetingCreated/Updated/Cancelled`, reminder job 15 min prior, `GoogleCalendarService` behind an interface with two implementations: `ManualLink` (button opens `https://meet.google.com/new`, organizer pastes back) and `CalendarApiOneWay` (per-organizer OAuth **or** service account with domain-wide delegation — Part D §12; creates event + conferenceData, stores `google_event_id`, `meet_link`; **deletes the Google event on cancel**; no sync-back). Driver chosen by `.env` `GOOGLE_CALENDAR_DRIVER`. Action item → task with `source_meeting_id`, pre-linked project.

**DB:** meetings, meeting_participants, meeting_notes, `google_oauth_tokens` (user_id, encrypted tokens — only with the OAuth variant).

**Tests:** CRUD + policy (creator/organizer/Admin/participants; Accountant 403), reminder scheduling, cancel keeps tasks and deletes the Google event (fake client), convert-to-task links, both calendar drivers, calendar page queries.

**Done when:** a client review meeting for Buffalo Modular is scheduled, gets a Meet link, reminder fires, 3 action items become tasks; tests green.

---

## Phase 8 — Finance module + Accountant surface

**Goal:** income/expense tracking with categories and monthly rollups; the Accountant works in a fully separate shell.

**Screens (Accountant shell + Admin → Finance):** Finance dashboard (this month income / expense / net, by category), Income list + form (category, amount, date, notes, optional project link — the picker uses the finance-only endpoint: project name, domain, finance fields; never client name, contacts, tasks or notes), Expenses list + form, Categories (seeded, Admin-editable), Monthly financial report (by category, by project, trend). Admin Company dashboard Row 3 (income, expense, payroll placeholder, operating result).

**Backend:** `FinanceService`, `IncomePolicy`/`ExpensePolicy` (ADMIN + ACCOUNTANT), audit log on create/edit/delete with old/new. **`/accountant/projects` finance-only endpoint** + `AccountantProjectResource` (Part D §13). The locked-period block is **Phase 9** (it needs `payroll_periods`); Phase 8 ships without it.

**DB:** income, expenses, finance_categories.

**Security/Privacy checks:** Accountant sees no client contacts, tasks, messages, project descriptions/notes; Accountant still gets 403 on ordinary project routes (Phase 1 test unchanged) and only the finance-only endpoint answers, with no client fields; Employees/Remote get 403 on every finance route; Accountant cannot send announcements.

**Tests:** CRUD per role; monthly totals (September example → $2,910); audit rows (expense created/edited, finance record deleted); finance-only project endpoint exposes exactly its fields; permission matrix.

**Done when:** the Accountant records September income/expenses and Admin sees the rollup; tests green.

---

## Phase 9 — Payroll state machine + payslips

**Goal:** a full month of payroll runs Draft → Calculated → Reviewed → Approved → Locked → Paid with own-payslip-only visibility.

**Screens:** Accountant → Payroll: period list, period detail with per-employee rows (base, allowance, bonus, deduction, advance, leave impact (auto from Phase 5), net), Calculate, submit for review. Admin → Payroll: Review, Approve, Lock, Reverse lock (reason modal), Mark paid; salary settings per employee (base salary, allowances — audit-logged). Employee/Remote/Admin (self) and the Accountant in its own shell: **My Payslip** (own periods only, PDF/print view). Admin dashboard Row 3 payroll card real.

**Backend:** `PayrollService` state machine with guards (Accountant: draft/calculate only; Admin: review/approve/lock/reverse/paid), auto draft on 1st (`hq:create-payroll-draft`) from `employee_salaries`, leave-impact rule (`leave_requests.unpaid_days` × daily rate), `PayrollItemResource` (self-scope by default; strips `admin_notes` for the Accountant), audit events `salary_changed`, `payroll_approved`, `payroll_lock_reversed`, **finance edits blocked in a locked period** (Part D §13 definition — the check lives in `FinanceService`, added now), payslip = browser print view + PDF via dompdf (decision).

**DB:** employee_salaries, payroll_periods, payroll_items.

**Security/Privacy checks:** Employee requesting any other employee's payroll item — list endpoints omit it, a direct id returns 404 (Part B §3 rule 1), and the attempt is audit-logged; Accountant sees amounts but never `admin_notes`; Accountant cannot approve/lock; only Admin reverses a lock and must give a reason.

**Tests:** every transition allowed/forbidden per role; auto draft creates one item per active employee from current salary; calculation with unpaid leave; lock blocks income/expense edits dated in that month; reversal logged with reason; self-scope on every payroll endpoint (list omission + direct 404 + audit row); Accountant's My Payslip shows only the Accountant's own item; `admin_notes` absent for Accountant; salary change audit row; permission matrix.

**Done when:** September payroll runs through all states, each user sees only their own payslip; tests green. **GATE E** — user reviews finance + payroll with the Accountant login.

---

## Phase 10 — Reports + global search + Gantt view + dashboards finalized

**Goal:** every report in Part D §15 exists, global search is permission-safe, the Gantt view completes ClickUp parity, and all dashboard cards are real.

**Gantt (Part D §5):** Tasks → Gantt tab — rows grouped by project, bar from start date to due date (tasks without a start date render as a milestone diamond on the due date), dependency arrows from `task_dependencies`, drag to move / resize (updates start/due with the same validation as the form), zoom day/week/month, today line, same filter bar. Employees see only their accessible tasks. Verify at desktop; on phone width it degrades to the List view with a notice (Gantt is desktop-only by design).

**Screens:** Admin → Reports: the 8 core reports first (Task, Employee Work, Project, Overdue, Attendance, Time, Finance, Payroll), then Completion, Leave, Maintenance, SEO, Website Project, Meeting, **Performance** (project-level, Part D §2 table) and **In-app coordination** (Part D §15) — each with the standard filter bar (date range, employee, project, client where allowed). No export (spec post-MVP). Employee → My Reports: My Tasks, Completed, Pending, Overdue, Time (timer roles), Weekly/Monthly summary. Global search box in every shell (results grouped by type, deep links). All dashboard cards/charts from Part D §3 populated (≤ 3 charts per screen).

**Backend:** `ReportService` (queries over live tables, no reporting DB), `SearchService` (accessible-ID scoping first, then tsvector ranking; per shell: Accountant searches only finance records), Postgres `tsvector` columns + GIN indexes on projects, tasks, messages, meetings, employees, clients, files.

**Security/Privacy checks:** search for "Buffalo" as Tapu returns the project (operational view) and never the client record or price; snippets never contain restricted fields; Accountant search never returns tasks/messages; reports pass through the same serializers.

**Tests:** each report returns correct numbers against seeded data; search scoping negative tests; dashboard endpoint per role returns only its cards; charts ≤ 3 asserted; Gantt range query, drag-move/resize validation, dependency arrows, employee scoping.

**Done when:** every report opens with real data; search is provably scoped; Gantt renders the seeded projects; tests green.

---

## Phase 11 — Activity-tracking browser extension (blocked until the client amends spec §46)

**Precondition:** the client's written amendment to spec §46 is recorded in `PROGRESS.md` → Decisions. Without it, mark this phase "blocked — awaiting spec amendment" and continue to Phase 12.

**Goal:** REMOTE_EMPLOYEE tracks time through a Chrome/Edge extension that reports active / idle / video state every minute; idle auto-pauses the timer with a keep/discard prompt; video time never counts as idle; Admin and the employee see the same per-entry timeline. Full behaviour in Part D §7a.

**Deliverables:**

- `apps/timer-extension/` (Manifest V3, plain JS or Vue, built with Vite; loaded unpacked in MVP, Web Store listing optional later): manifest with `permissions: ["idle", "alarms", "storage"]`, `host_permissions` for the app origin, content script on `<all_urls>` + `all_frames`; popup (pairing with the one-time code → device token; task picker; Start/Pause/Resume/Stop; today's total vs target; connection status), background service worker (`chrome.alarms` every minute → `chrome.idle.queryState(60)` + content-script media flag → heartbeat; `chrome.idle.onStateChanged`; heartbeat queue in `chrome.storage.local`, replay on reconnect; re-pair when the token is revoked/expired), content script (reports a single boolean "a `<video>` is playing"; no URL, no title).
- Backend: `php artisan install:api` (routes/api.php), `devices` + `activity_samples` migrations, `time_entries` activity columns, `POST /api/extension/exchange` (10-min single-use code from Profile → "Connect timer extension", rate-limited) → Sanctum token with abilities `timer:read-tasks`, `timer:track`, `timer:heartbeat`; revoke from Profile, Admin → Employees and on deactivation; `POST /api/timer/heartbeat` (batch, idempotent on `client_uuid + minute`), API timer routes (above) wrapping the Phase 4 `TimerService` with `activity_source = extension`, `IdleRule` service (`settings.idle_pause_minutes`, default 5 → auto-pause + pending "keep/discard" decision; discard subtracts and logs), minute-column rollup on pause/stop + nightly reconciliation (`hq:reconcile-activity`).
- Web UI: Profile → Connect timer extension (code + instructions + "what this extension never collects" text); the **activity graph** from Part D §7a — day timeline bar (green active / blue video / grey idle, pauses blank, hover per minute) + week stacked bars with target line on the employee's Time page and on Admin → Workforce → Time → employee; Admin → Workforce → Time overview table (tracked / active / video / idle / idle % per remote employee per week, entries above `idle_flag_percent` marked); "no activity data" badge on web-only entries. No score, no ranking, no per-site data anywhere. Charts follow `dataviz` conventions already used by the dashboards (same palette tokens, accessible in light and dark).

**DB:** personal_access_tokens (Sanctum), devices, activity_samples, `time_entries` Phase-11 columns.

**Seed:** one full day of `activity_samples` for Tapu (a morning active block, a 20-minute video stretch, a 12-minute idle stretch that triggered a pause) so the day timeline and week view render immediately.

**Extension API (`routes/api.php`, Sanctum token, ability per route):** `POST /api/extension/exchange` (no token; one-time code) · `GET /api/timer/tasks` (`timer:read-tasks` — id, title, project domain of the user's assigned open tasks, nothing else) · `POST /api/timer/start|pause|resume|stop` (`timer:track` — thin API controllers calling the Phase 4 `TimerService`) · `POST /api/timer/idle-decision` (`timer:track`) · `POST /api/timer/heartbeat` (`timer:heartbeat`, batch). No other route accepts the token.

**Security/Privacy checks:** device token abilities limited to the routes above (a request to any web route or any other API route with the token → 403); heartbeat rejected for another user's entry; the content script's message schema is `{videoPlaying: boolean}` and nothing else (schema-validated on the worker side; test); automated grep test that no field named `url`, `title`, `hostname` exists in the heartbeat payload, `activity_samples` or `devices`; revoked/expired token → 401 immediately; Admin views respect the existing employee-scoping (Accountant sees nothing); the install-time permission warning is documented in the Profile text.

**Tests:** heartbeat batching + idempotency; state transitions active/idle/video into minute columns; idle rule triggers pause after N consecutive idle minutes and not after video minutes; keep/discard adjusts duration and logs; offline replay ordering; token issue/revoke; timeline queries per role; extension unit tests for the media-playing detector (playing video, paused video, audio-only → not video, video inside an iframe reported by that frame and OR-ed by the worker) and for the idle state machine (mocked `chrome.idle` + `chrome.alarms`).

**Done when:** Tapu installs the extension, tracks a session with a real idle stretch and a real video stretch, sees the pause prompt only for the idle one, and Admin sees the matching bar; tests green. **GATE F** — user tests the extension on their own machine with the client's rule (video = fine, idle = flagged).

---

## Phase 12 — Admin tools (Users & Roles, audit viewer, settings, notification defaults), backup health, UX polish pass, migration import, hardening, cutover

**Goal:** everything required by the acceptance criteria and the "definition of done"; the client can cut over from ClickUp/Asana.

**Screens:** Admin → Workforce → Employees / Users & Roles (create employee per `04-add-employee-form.png` pattern with role/schedule/tracking_mode/phone, deactivate (never delete), reassign open tasks prompt, project-level permission grants UI (minimal), self-guard), Admin → Audit Log viewer (filters, old/new diff, read-only), Admin → Settings (**every key from Part D §20** grouped: General — timezone, currency; Attendance — late grace, half-day auto; Timer — max session, heartbeat timeout, manual-time approval, idle pause, idle flag %; Notifications — group window; Integrations — Google driver and realtime driver **read-only from `.env`**; Backup health card with `backup_last_verified_at`, read-only), Admin → Notifications defaults (`notification_preferences`: per event type, in-app on/off; other channels listed but disabled until spec post-MVP Phase 2). Onboarding: welcome notification + optional orientation checklist task. **UX polish pass** (spec §49 Phase 5): every shell's home screen checked against its one question (Part D §3), plain-English states, one filter/sort pattern, empty/loading/error states, 375/768/1280 px on every page.

**Backend:** import command `hq:import --from=clickup|asana --file=...csv` (ClickUp task → Client + Project, subtask → Task, statuses mapped, "Time tracked" → manual `time_entries` with reason "imported from ClickUp"; dry-run mode; import report); `docs/runbooks/cutover.md` (parallel-run window, decommission date); security review pass (headers, CSP, rate limits, signed URL TTLs, upload scanning); performance pass (N+1 audit, indexes); backup restore drill executed and recorded; `docs/runbooks/*` complete.

**Tests:** full suite; import dry-run vs real against a real ClickUp export; deactivation flow (revokes sessions; and extension device tokens when Phase 11 was built); audit viewer read-only; settings changes audit-logged and every key except `backup_last_verified_at` editable; notification defaults persist and the engine honours them; the complete `tests/Permissions` matrix (every route × every role); the acceptance test list in Part F.

**Done when:** all 12 acceptance criteria pass (Part F §3) and the client's real ClickUp/Asana export imports cleanly. **Final review** with the user.

---

# PART F — TESTING & DEFINITION OF DONE

## 1. Test layers (every phase)

- **Unit (Pest):** services (state machines, engine, timer math, payroll calc, notification grouping).
- **Feature (Pest + Inertia assertions):** every route per role; Inertia props inspected for **absence** of restricted keys.
- **Permissions matrix (`tests/Permissions`):** a table of `[route, method, role → expected status]` covering every route registered so far; fails if a new route is not in the table (route-coverage guard).
- **Audit append-only:** raw `UPDATE`, `DELETE` and `TRUNCATE` on `audit_logs` as `hq_app` must throw `insufficient_privilege` (run inside a savepoint so the test transaction survives). The test DB must therefore also use the two-role setup (`deploy/sql/roles.sql` is applied in CI).
- **Browser smoke (optional per phase, Playwright/Dusk):** login → key flow of the phase at desktop and 390 px width.
- **Scheduler:** assert every `hq:*` command is registered with its frequency.

Commands: `php artisan test` (all), `php artisan test --group=permissions`, `php artisan test --group=phaseN`.

## 2. Negative-test rule

For every restricted field the spec names (client name/contacts for employees, price/recurring/contract/profit, internal_notes, salary of others, audit log), at least one test per **endpoint that could carry it** asserts the key is absent from the response — not null, not masked, absent — and that the attempt is audit-logged where the spec requires it.

## 3. Acceptance criteria (all 12 must pass before "done")

1. All active client work is tracked as Clients → Projects → Tasks inside HQ; zero tasks remain in ClickUp/Asana after cutover.
2. Tapu's daily work is timer-tracked per task; the 5 h/day target is visible on his and Admin's dashboards.
3. Yaseen's attendance is clock-in/out tracked with no timer requirement; his tasks are visible without a timer.
4. No employee-role account can retrieve, via UI or direct request, another employee's salary or a project's price/profit — verified by the permission suite.
5. The Accountant performs finance/payroll without any access to client, task or messaging data — verified by a dedicated boundary test pass.
6. ≥ 80 % of task-specific coordination happens inside task/project chat within the first month of adoption. The app cannot see Telegram, so this is a **client sign-off** supported by the Phase 10 "In-app coordination" report (message counts per week per project), not an automated test.
7. A recurring monthly project auto-generates its next batch with zero manual recreation for two consecutive cycles — the Phase 3 time-travel test proves the mechanism; the two real cycles after launch are a **client sign-off**.
8. Leave approval updates calendar/attendance/payroll impact with no manual reconciliation.
9. Payroll runs Draft → Paid for a full month with each employee seeing only their own payslip.
10. A daily backup exists, is automatically verified weekly, and its status is visible to Admin.
11. The audit log captures every event in Part C §4 and is not editable through the application by any role, including ADMIN.
12. Admin, Employee and Accountant dashboards load only their role-scoped view with no cross-role data bleed.
13. *(v1.1, only if Phase 11 is built)* A remote timer session with a 10-minute idle stretch is auto-paused and prompted; the same session with a 10-minute video stretch is not; the stored payload contains no URL, title or content.

## 4. Definition of done for the whole build

- Phases 0–12 complete (Phase 11 only with the client's §46 amendment), `PROGRESS.md` shows each with tests green in CI.
- Deployed on the client's VPS via `deploy/install.sh` + `deploy/deploy.sh`; Supervisor, cron, Reverb, backups running; restore drill recorded.
- Runbooks complete (install, deploy, restore, rotate secrets, add employee, cutover).
- Permission suite covers 100 % of registered routes.
- Import of the real ClickUp/Asana export verified; parallel-run window and decommission date recorded in `docs/runbooks/cutover.md`.

---

# PART G — PROGRESS.MD PROTOCOL

Create `PROGRESS.md` at the repo root in Phase 0 using this template and keep every section up to date. New sessions read it first (Part 0).

```markdown
# GoodTechies HQ — Build Progress

> Source of truth: `docs/master-prompt-v1.md` (v1.2), condensed from the client spec v1.0 (Sept 2026) + the client's design references.
> Updated at the end of every phase and after every gate. A new session must be able to continue from this file alone.

**Last updated:** <date> · **Current phase:** <N — name> · **Status:** <not started / in progress / waiting for GATE X / done>
**Test suite:** `php artisan test` → <passed>/<total> · **Deployed on VPS:** <yes/no, date, commit> · **Execution method:** <plain sessions | dispatch vX.Y>

## Overall progress
<copy the Part E overview table verbatim — Phase, Vertical slice, Gate — plus a Status column>

## How to run locally
- Requirements, `.env` keys, `composer install`, `npm run dev`, `php artisan migrate:fresh --seed`, seeded logins (emails only — passwords come from `.env` seed keys), `php artisan test`.

## Decisions confirmed with the client (GATE A)
| Topic | Value | Confirmed on |
<one row per Part H §2 topic>

## Phase N — <name> <✅/🔄>
### What you can click now
| Screen / action | Where | Notes |
### Built (files)
### Tests — `php artisan test` → X / X passed (all earlier phases still green)
### Decisions made in Phase N (spec was silent → chosen option + reason)
### Known issues / notes
### Questions for the client (ask at the next gate)

## Deployment log
| Date | Commit | Server | Result |

## Next step
```

Rules: never delete earlier phase sections (append "Follow-up" blocks instead); record test counts as numbers; every decision gets a one-line reason; questions for the client are collected here and raised only at gates; the overall-progress table is copied from Part E, never re-worded.

---

# PART H — DO NOT BUILD (spec §46) & OPEN DECISIONS

## 1. Explicitly out of scope (do not add, even partially)

- CRM sales pipelines, lead scoring, sales automation
- Recruitment / applicant tracking
- Employee productivity scoring or gamification
- **Keylogging, hidden monitoring, or unrequested screenshot capture** — still out. The v1.1 activity extension (Phase 11) is transparent, content-free and score-free, and is built only after the client amends this line in writing; screenshots stay out regardless.
- From the design references (Part I), the hardware-attendance parts of FlowZa: Devices, Sync jobs, Reconciliation, Shifts, Branches, Early-departure tracking, "Budget and Expenses" charts on the employee dashboard, Assets library
- Double-entry accounting / general ledger (finance = income/expense/payroll tracking only)
- Inventory management
- A full client portal (Phase 3 post-MVP at earliest — keep the communication model portal-ready, build nothing)
- A generic no-code workflow/automation builder (the fixed rule set in Part D §19 is the automation)
- Third-party integrations beyond Google Calendar/Meet (no Slack, Zoom, Salesforce…)
- Native mobile apps; dedicated search engine; two-way Google Calendar sync; invoicing/recurring billing; **browser push notifications and email digests; PDF/CSV report exports; PWA; workload timeline visual; 2FA mandatory for all roles; MANAGER UI** (all spec post-MVP Phase 2/3 per §44–45 — the data model leaves room for them, the build does not include them)

## 2. Decisions the spec leaves open — defaults to use unless the client says otherwise at GATE A

| Topic | Default | Why |
| --- | --- | --- |
| Laravel major | Current supported major (12 or 13) instead of the spec's 11 | Laravel 11 is out of security support since March 2026 |
| App timezone (`timezone`) | `Asia/Dhaka` app-wide, per-user `users.timezone` for display | Team is Dhaka-based; remote/US clients need correct display |
| Currency (`currency`) | Single value; spec examples use `$` | Spec shows USD examples; payroll currency to be confirmed |
| Late grace (`late_grace_minutes`) | 15 | Needs a value for the Late rule |
| Half-day auto (`half_day_auto`) | off | Admin marks half days by hand unless turned on |
| Heartbeat timeout (`heartbeat_timeout_minutes`) | 5 → stop at last heartbeat + flag | A closed laptop must not log hours |
| Timer max session (`timer_max_session_hours`) | 10 → pause + flag | Needs a value for the safeguard |
| Manual time approval (`manual_time_requires_approval`) | on | Spec says "optional admin approval" — default to the stricter reading |
| Notification group window (`notification_group_window_minutes`) | 2 | Spec example "5 comments in 2 minutes" |
| Payslip format | Browser print view + PDF via dompdf | Spec says "payslip generated" without format |
| Realtime driver (`.env`) | `reverb`; `polling` fallback available | Spec recommendation + fallback |
| Google Calendar driver (`.env`) | `manual` until the client provides a Workspace account (OAuth per organizer, or service account with domain-wide delegation) | A plain service account cannot create Meet links |
| Object storage | S3-compatible bucket (R2/S3); MinIO on the VPS only with versioning + off-box replication | Spec pins "S3-compatible" and off-provider backups |
| Backup destination | A bucket in a different provider account or region from the VPS | Spec §37 |
| Idle pause threshold (`idle_pause_minutes`, Phase 11) | 5 consecutive idle minutes | Needs a value; client can change in Settings |
| Idle flag threshold (`idle_flag_percent`, Phase 11) | 25 % of an entry | Marks entries for Admin review |
| Video-only rule (Phase 11) | `<video>` playing excuses idleness; audio does not; content script on `<all_urls>` with the install warning documented | Client's rule is "watching a video"; confirm at GATE F |
| Spec §46 amendment (Phase 11) | **No default** — Phase 11 is blocked until the client confirms in writing that transparent activity-state tracking (no content, no screenshots, no score) is wanted | The spec currently forbids monitoring |

Record the confirmed values in `PROGRESS.md` → "Decisions confirmed with the client (GATE A)" and seed them into `settings`.

---

# PART I — DESIGN REFERENCES (client's "Application Visuals" doc + current ClickUp)

The client supplied nine screens (a hardware-attendance HRMS called *FlowZa Time*, a project tool called *Moru*, a ClickUp-style *Agency Management* board, a task-modal design) and a screenshot of their current ClickUp workspace. They live in `docs/design-refs/`. Spec §1 says these were studied for **layout and interaction patterns, not copied** — apply that literally: borrow the pattern named in the "Take" column, ignore everything in "Leave".

| File | Screen | Take (into which phase) | Leave |
| --- | --- | --- | --- |
| `01-admin-dashboard-flowza.png` | Admin dashboard | Greeting + date picker row; stat-card style with a "vs last week" sub-line (applied to the spec's Row-1 cards, Part D §3); "Pending approvals" card (leave + manual time + review queue); "Upcoming holidays" card → **Phase 4/5/10** Company dashboard | "Today's attendance" donut (would be a 4th chart — attendance shows as the Present/Absent/On-leave cards), "Recent activity" feed (spec §22 excludes activity feeds on the Admin dashboard), attendance-by-branch, "Explore reports" promo tile |
| `02-admin-sidebar-flowza.png` | Admin sidebar | Dark sidebar (ours is the spec's navy `#1B4B66`, not FlowZa's green), grouped section labels in small caps (WORKFORCE / TIME / ADMINISTRATION), icon + label rows, active row highlighted → **Phase 0** `AdminLayout` using Part D §2's groups (MY WORK / COMPANY / WORK / WORKFORCE / REPORTS / FINANCE / ADMIN) | Devices, Sync jobs, Reconciliation, Shifts, Organisation (branches) |
| `03-admin-project-task-kanban.png` | Project board | Breadcrumb *Space › Project › Phase*; view tabs List / Board / Timeline / Calendar; Kanban card with date range chip, tag chip, assignee avatar; inline "+ Add task" at column bottom → **Phase 2** Board, **Phase 10** Gantt | Spaces hierarchy (ours is Client › Project), Docs |
| `04-add-employee-form.png` | Add employee | Two-card form (Basic information / Employment), required-field asterisks, helper text under fields, weekly off-day toggle row, "Cancel / Create employee" footer → **Phase 12** Users & Roles, with Part D §20's employee fields (employee_number, names, email, phone, role, manager, employment_type, joining_date, tracking_mode, schedule working days) | Branch, Department, Designation, Nationality, Arabic display name, Middle name |
| `05-employee-dashboard-flowza.png` | Employee dashboard | Stat-card style with delta chips (applied to the spec's **five** cards — My Tasks / Due Today / Overdue / In Progress / Completed — not FlowZa's four); "Recent activity" list (spec §23 lists it for the employee surface); "My meetings" with Google Meet rows and join button; "Task distribution" bar → **Phase 2/7** Employee dashboard (≤ 3 charts) | "Activity timeline" contribution heatmap and "Task performance" chart (they read as productivity scoring — spec forbids); Help & Support |
| `06-employee-dashboard-moru.png` | Employee dashboard alt. | Completion ring (completed / total); "Schedule" mini-calendar with Events / Meetings / Holidays tabs; "Latest tasks" table with subtask progress, status pill, priority dot, date range, assignee avatars → **Phase 2/5/7** | "Budget and Expenses" chart, Cancelled-tasks stat card as a headline number, Assets |
| `07-employee-task-kanban-moru.png` | Tasks Kanban | Column header with colour dot + count + "+ ⋯"; card: project chip (domain for employees), title, one-line description, assignee avatars, due date, priority chip, footer counts (checklist n/m · comments · attachments); top bar Kanban/List toggle + Search + Filters + Sort + "New Task" → **Phase 2** Board | — |
| `08-new-task-modal.png` | New task modal | Modal with Task / Details tabs; required fields on tab 1 (name, project, description), optional on tab 2 (assignees, dates, priority, tags, checklist); identical layout at phone width as a full-screen sheet → **Phase 2** quick-add | "Select space" (ours is project only) |
| `09-notifications-panel-moru.png` | Notifications | Right-side panel; search; tabs All / Tasks / Comments / Links / Assets / System (ours: All / Tasks / Messages / Meetings / Leave / Payroll / System per spec §16); Today / Yesterday / Earlier groups; unread dot; grouped item "You have 12 new comments in …"; inline file attachment row; "Mark all as read" → **Phase 2** Notification Center, **Phase 6** realtime | Assets tab |
| `10-clickup-current-workspace.png` | Current ClickUp | List view grouped by status with collapsible groups and counts; columns Assignee / Status / Due date / Time tracked; subtask counter on the row; "+ Add Task" per group; Filter / Closed / Assignee / Search / settings bar; view tabs List · Board · Gantt · Calendar; sidebar with Home / Inbox / My Tasks / Meetings, Channels (Welcome, General), Direct Messages (Tapu, Faruk, Shahadat), Timesheet, Dashboard → **Phase 2** List, **Phase 4** Timesheet, **Phase 6** channels/DMs, **Phase 10** Gantt | Spaces, Docs, AI chats, Super Agents, Clips, Gantt for employees on phone |

**Design-token source of truth: the `shadcn-tokens` MCP server** (`tools/shadcn-tokens-mcp/`, registered in Claude Desktop and Claude Code; project `.mcp.json` passes `--theme-css resources/css/app.css --framework vue`). Every session and every sub-agent that writes a class calls `get_theme` (colours/radius), `get_spacing` or `px_to_tailwind` (padding/margin/gap/heights), `get_typography` (sizes/weights/recipes) and `get_component_styles <name>` (the exact shadcn-vue class strings for button/card/input/table/sidebar/dialog …) **before** writing markup, and uses only what they return — no hard-coded hex, no off-scale px. Phase 0 writes the spec palette below into `resources/css/app.css` as shadcn variables (`--primary` = navy, `--accent` = teal, `--muted` = grey-blue …) so the MCP's `--theme-css` serves the real theme from then on.

**Visual language to derive (Phase 0 sets it up as the Tailwind theme):** dark sidebar (the spec's palette: Deep Navy `#1B4B66` sidebar, Teal `#2E8B8B` active/secondary, Slate `#8A9BA3` metadata, Soft Grey-Blue `#EEF3F5` card/table banding, Body `#2B2B2B`), light content area, white cards with 12 px radius and a hairline border, stat card = label + big number + one sub-line, status pills with a colour dot, small-caps section labels in the sidebar, Inter font. Max 3 charts per screen. Every list has the same filter/sort bar. All of this is checked at 375 / 768 / 1280 px.

**How the client currently works (from the ClickUp screenshot):** one Space "Marketing", one List "PROJECTS", each *task* is a client (Buffalo Modular, APH, Abbey Heating, Woodfordoil) with the real work as *subtasks*, assignee "T" (Tapu), statuses In Progress / Draft / Completed, "Time tracked" column, chat channels and DMs beside the work. The migration import in Phase 12 must map this: ClickUp task → **Client + Project**, ClickUp subtask → **Task**, ClickUp status → our status, "Time tracked" → `time_entries` (manual, reason "imported from ClickUp").

---

# PART J — WORKING WITH THE `dispatch` SKILL (optional execution method)

The repo may be driven with the `dispatch` skill (`Arafat-plugins/dispatch`, v1.4.0: the main session holds a repo map, writes an explicit brief per job, sub-agents edit, the main session reads the diff and accepts, a security critic verifies). **v1.5.0 "Capabilities" is in progress in a separate session** (`/dispatch setup` provisions the browser for the frontend agent, a `dispatch-measure.mjs` script, a generated `DESIGN.md`, a `/dispatch deps` mode, read-only DB user checks, a Surfaces table in `AGENTS.md`). When the installed skill reports 1.5.0 or later: run `/dispatch setup` after bootstrap and treat J.1 steps 5–6 as done by it; let it generate `DESIGN.md` from Part I and the spec palette (Part I "Visual language") instead of writing one by hand; add dependencies only through `/dispatch deps`. It is compatible with this master prompt: dispatch decides **how work is delegated**, this prompt decides **what is built and in which order**. Its mandatory footer — phases as vertical slices — is the same rule as Part 0. Follow these adaptations; the skill's own references apply for everything else.

## J.1 Phase 0 with dispatch (do this in order)

1. **Do not run `/dispatch new` intake.** Every intake answer already exists here. Write `PROJECT_BRIEF.md` at the repo root from this file: Purpose/users (Part A), Platform (web app, VPS), Stack (Part B §1), MVP scope (Part E), Data/auth (Part D §20, Part C), Look and feel (Part I), Integrations (Google Calendar one-way, S3-compatible storage), Hosting (Part B §4), Constraints (Bangla/English UI not required; Asia/Dhaka; desktop-first admin, phone-usable employee), Definition of done (Part F), Out of scope (Part H §1). Show it to the user once for a yes.
2. **Scaffold** = one `opus` dispatch to `dispatch-implementer`: Laravel (current supported major, Part B §1) + Inertia + Vue 3 + Vite + Tailwind + Pest + the folder layout of Part B §2, nothing else. Done means: `php artisan test` runs green with the default example test, `npm run build` succeeds.
3. **`/dispatch bootstrap`** → `AGENTS.md`. Because the repo is nearly empty, "What this project is" comes from `PROJECT_BRIEF.md`. Then commit `AGENTS.md`, `.claude/agents/`, `.claude/.dispatch-state.json`, `PROJECT_BRIEF.md`.
4. **Re-run bootstrap at the end of every phase** that adds a top-level folder, a command, a queue worker or a convention (most phases do). The map must name the owning file for each surface so later briefs can skip the grep.
5. **Install browser tools and the token MCP on the frontend agent.** The `dispatch-frontend` template ships without a browser; append the session's Chrome/Playwright MCP tool names **and** `mcp__shadcn-tokens__get_theme, mcp__shadcn-tokens__get_spacing, mcp__shadcn-tokens__px_to_tailwind, mcp__shadcn-tokens__get_typography, mcp__shadcn-tokens__get_layout_tokens, mcp__shadcn-tokens__get_component_styles` to its `tools:` line so responsive "verified" means rendered at 375 / 768 / 1280 and every class comes from a token call. Record which in `AGENTS.md`. `DESIGN.md` (generated by `/dispatch setup` on v1.5+) says in its first line: *"Tokens come from the shadcn-tokens MCP; this file only records project-specific decisions on top of them."*
6. **Read-only DB user for the db-tester:** `hq_ro` from `deploy/sql/roles.sql` (SELECT only, default privileges for future tables); its connection goes in `.env` as `DB_RO_*`; the db-tester briefs use it. Never the app user.
7. **Bangla/English:** briefs are written in English. The user gates are in whatever language the user speaks.

## J.2 A phase is many briefs, never one

A master-prompt phase is too large for one dispatch (the skill treats a >300-line diff as a finding). Split every phase into briefs that each land one **checkable, testable slice**, in this order, one brief per line unless two touch fully disjoint files:

| Brief | Agent / model | What it lands |
| --- | --- | --- |
| a. Migrations + models + factories + seeder for this phase | implementer / `sonnet` | `migrate:fresh --seed` green, factories used by tests |
| b. Policies + enums/state machine + service class + unit tests | implementer / `opus` | The business rules, tested without HTTP |
| c. Serializer (Resource) + Form Requests + controllers + routes + feature tests incl. the **negative privacy tests** | implementer / `opus` | The endpoints, tested per role |
| d. Inertia pages + components for the admin surface | frontend / `opus` | Rendered and measured at 3 widths |
| e. Inertia pages for the employee (and accountant) surface | frontend / `opus` (or `sonnet` when it reuses d's components) | Same |
| f. Jobs / scheduler / events / listeners / notifications for the phase | implementer / `sonnet` unless new logic | `schedule:list` shows them; tests |
| g. Deploy kit + runbook + `.env.example` updates for anything new (worker, channel, env key) | implementer / `sonnet` | `deploy/` still applies cleanly |
| h. Permission-matrix table update for every new route | implementer / `sonnet` | `tests/Permissions` covers 100 % of routes |
| i. `/dispatch verify` on the whole phase diff | security critic / `sonnet` | Findings reported; fixes go through the cycle |

Typical counts: Phase 0 ≈ 6 briefs, Phase 2 ≈ 10, Phase 6 ≈ 8, Phase 11 ≈ 7 (the extension is its own `apps/timer-extension/` brief set: manifest + popup, service worker + queue, content script + detector tests). Run at most 2 in parallel and only when their file sets are truly disjoint — in practice **a + g** (migrations vs deploy kit). d and e are sequential: e reuses d's components.

## J.3 What every brief in this repo must carry

Beyond the skill's template: the phase number and Part E section it implements; the exact Part C rule(s) it must not break ("Rule 1: ProjectResource is the only serializer for projects"); "do NOT add a dependency" (Laravel/Vue packages are added only by a brief whose task is exactly that); the seeded logins to use in feature tests; the "Done means" lines copied from the phase's *Tests* paragraph; and the 40-line report cap. UI briefs also carry the design-ref file name from Part I, the three widths, the page URL, and the line *"call shadcn-tokens get_component_styles for <component> and get_spacing before writing classes; use only token classes"*.

## J.4 The security critic's spec for this codebase

Ask the critic, on every phase diff, specifically about: a project or payroll payload reaching the client with a restricted key present; a policy missing on a new route; a query on `payroll_items`, `time_entries`, `attendance_records`, `leave_requests` not scoped to the requester; an unsigned or non-expiring file URL; an `audit_logs` write outside `AuditLogger`; a broadcast channel without an auth callback; mass assignment on `project_finance` or `employee_salaries`; and, for Phase 11, any field in the heartbeat payload that could carry a URL or title. Say the rest is out of scope for that diff.

## J.5 What stays with the main session (never dispatched)

Reading `PROGRESS.md` and this file; choosing the next brief; writing briefs; acceptance from the diff; running `php artisan test` after each accepted brief; measuring responsive widths when the frontend agent could not; updating `PROGRESS.md`; committing at phase end; stopping at gates. The 3-failure escalation rule applies per brief — escalate to the user with the skill's summary format, do not skip the brief.

---

# PART K — FINAL INSTRUCTION TO THE IMPLEMENTING AGENT

1. Read Part 0, Part C and `PROGRESS.md`. Run the tests.
2. Build the current phase from Part E **vertically** — migration, model, policy, serializer, controller/Form Request, Inertia page, seed, tests — until it is clickable and green. With the `dispatch` skill, split it per Part J.2.
3. Never build ahead of the current phase; never build a horizontal layer for future phases.
4. Never substitute the stack (Laravel current major / PostgreSQL 16 / Vue 3 + Inertia / Reverb / Redis / S3-compatible / Pest). Never add anything from Part H §1.
5. Enforce privacy at the backend; prove it with negative tests in the same phase.
6. Look at the design reference for the screen (Part I) before building it; borrow the pattern, not the product.
7. Update `PROGRESS.md` and commit at the end of the phase. Stop at gates.
8. Keep the VPS deploy kit working from Phase 0 onward; every phase that adds a worker, a schedule, a channel or an env key updates `deploy/` and the runbooks in the same phase.

---

## Changes in v1.2 (17 Sep 2026 — review pass against the spec)

An independent review compared v1.1 line by line with the client spec. Everything below was changed in place; the list is grouped the way the review reported it.

**Spec items that were missing or contradicted**
1. 2FA for ADMIN/ACCOUNTANT moved from Phase 12 to **Phase 0** (spec §36 "from day one"); finance and payroll never exist without it.
2. Browser push, email digests and PDF/CSV exports removed from the build — they are spec post-MVP Phase 2 (§44); Part H §1 now lists every §44/§45 item.
3. Task **delete** (audit-logged, soft) and **archive**, `task_links`, `created_by`, reviewer definition, and the audit-row assertions per phase (§27) added.
4. AC2: the Admin Company dashboard gets a "Remote time today — Tapu 4h 18m / 5h" card in Phase 4.
5. Backups: encrypted archive, different provider account/region, file-bucket versioning/replication (§36–37); `deploy/install.sh` + CI added (§49 Phase 0 "CI/deploy pipeline").
6. Meeting cancel deletes the Google event with the API driver (§47); meeting permissions and the Workspace requirement for Meet links stated.
7. AC6 gets an "In-app coordination" report and is marked a client sign-off; AC7's post-launch cycles likewise.
8. Sidebar items with no phase (Team, Employees, Performance, Time/Workload placement, Accountant's My Leave / My Payslip) mapped; "Performance" defined as project-level so it cannot become a score.
9. UX polish pass (§49 Phase 5) added to Phase 12; the 8 MVP core reports are built before the other six.
10. Deviations recorded: `project_finance` split, no `automation_rules` table, one discussion store (task conversations instead of `task_comments`), Laravel major.

**Internal inconsistencies**
11. One authoritative data model (Part D §20) with every table and column the phases use — `login_history`, `holidays`, `recurring_generation_log`, `finance_categories`, `employee_salaries`, `message_mentions`, `task_links`, `personal_access_tokens`, `google_oauth_tokens`, `notification_preferences`; `position`, `client_uuid`, `recurring_period` + unique index, `unpaid_days`, `admin_notes`, `users.name/timezone/two_factor_recovery_codes`, `employees.phone`, `pending_idle_decision`; Laravel-safe column names (`password`, `two_factor_secret`); Phase-3/7/11 columns and FKs marked; pairing codes in cache.
12. One canonical `settings` key list, seeded in Phase 0, editable in Phase 12 (except the backup timestamp), defaults in Part H §2; the realtime and Google drivers are `.env` keys shown read-only because they cannot switch at runtime.
13. Permission keys in one dotted spelling; the "field absent vs record 404" rule stated once (Part B §3 rule 1).
14. Phase dependencies fixed: locked-period check moved to Phase 9; search test removed from Phase 1; Phase-2 notifications no longer re-listed in Phase 3; Absent job skips leave/holidays from Phase 5; Phase-12 token-revoke test conditional on Phase 11.
15. Part I aligned with spec §22/§23 (no attendance donut or activity feed on the Admin dashboard; five employee cards; navy sidebar); Part G template synced with `PROGRESS.md`; overview table declared the single source of phase names.

**Unactionable rules made concrete**
16. Timer safeguards split into heartbeat timeout (5 min → stop at last heartbeat) and max session (10 h → pause); missing heartbeats count as idle; rollup on pause/stop.
17. Drag permissions on Board/Calendar/Gantt; tags ownership; manual-time approval setting; meeting permissions; Remote attendance derivation; Half Day; locked-period definition; leave status enum and uncapped types; extension pairing endpoint, code TTL and token abilities; Phase 11 DB + seed paragraphs; Phase 0 "Done when" made checkable (install in a fresh container, transcript saved).

**Technical errors**
18. `audit_logs` protection now uses two DB roles (`hq_migrator` owner, `hq_app` SELECT/INSERT only, TRUNCATE revoked, test in a savepoint); `hq_ro` gets default privileges for future tables; "row-level grants" corrected to table-level.
19. Laravel 11 (out of security support since March 2026) replaced by the current supported major, to be confirmed at GATE A.
20. Extension: `chrome.alarms` instead of `setInterval` (MV3 workers die after ~30 s); content script needs `<all_urls>` (stated honestly, `activeTab` removed); `tab.audible` fallback; only `<video>` excuses idleness; `install:api` + `host_permissions`; Sanctum tokens are long-lived/revocable (no refresh flow).
21. Broadcast auth failure is 403, not 401; Google Meet needs a Workspace calendar (OAuth or domain-wide delegation).

**Second review pass (same day)**
22. Migrations run on a dedicated `pgsql_migrator` connection (`DB_MIGRATOR_*`); the `audit_logs` migration explicitly revokes DML from `hq_app` because default privileges would otherwise grant it; tests build the schema as the migrator and probe append-only as `hq_app`.
23. The Accountant's finance-only project endpoint carries no client name (matrix ❌ clients, AC5).
24. 403 vs 404 stated once: record-level denials 404, route/surface denials 403; phase headings copied from the overview table; Time/Workload added to the WORKFORCE sidebar line; Accountant's own payslip built in Phase 9.
25. Extension: explicit `api.php` route list with one Sanctum ability each; per-frame media reporting OR-ed in the worker; Pending approvals and Upcoming holidays cards recorded in Part D §3.

## Changes in v1.1 (17 Sep 2026)

1. **Design references** — client's "Application Visuals" doc (9 screens) + current ClickUp screenshot added to `docs/design-refs/`; Part I maps each screen to take/leave and phases; Part H §1 lists the FlowZa/Moru features that are out.
2. **ClickUp parity** — List grouped by status, Board, Calendar (Phase 2), Timesheet (Phase 4), Gantt (Phase 10), tags and start date on tasks (Part D §5, §20).
3. **Activity-tracking browser extension** — Part D §7a and Phase 11 (GATE F), blocked until the client amends spec §46; screenshots remain out; acceptance criterion 13.
4. **Phase renumbering** — hardening/import/cutover is now Phase 12; Part G table updated.
5. **`dispatch` skill** — Part J: Phase 0 sequence, phase-to-brief split, brief contents, critic focus, main-session duties.

## Changes in v1.4 (24 Sep 2026)

1. **v1.3 wrote a list of screens where it should have written a rule, and the list was already short by four.** Within a day of v1.3 an employee filed leave and the Admin queue behind the notification still read "Nothing waiting" — a screen v1.3 did not name, in a phase v1.3 did not touch. The refresh requirement is now **Part 0.5**, a rule that applies to every phase, with one test for whether it applies: *can somebody who is not looking at this screen change what it says?*
2. **The inventory, now stated per phase** rather than in one phase: Phase 2 — task List and Board, Notification Center page; Phase 4 — attendance roster, Admin workforce cards, own Attendance page, **and the Time approval queue** (missed by v1.3); Phase 5 — **Admin → Leave and My Leave** (missed by v1.3, and the case that found this).
3. **Phase 6 gains the `leave` channel** beside `attendance.{date}`, and an explicit instruction to grep `router.reload` and account for every survivor — a poll left running beside its own channel is two mechanisms doing one job, which is how this ends up wrong again in six months.

Scope unchanged: no new screen, table or endpoint. Four screens that already exist learn to refresh, and one channel is added to a phase already installing Reverb.

## Changes in v1.3 (24 Sep 2026)

1. **Screens that show other people's actions now refresh themselves.** Found while testing Phase 4: a clock-in by one employee stayed invisible on the Admin roster until a full browser reload, because every screen is one Inertia response and nothing re-asks. Phase 4 now requires a 20 s `router.reload({ only: [...] })` on the Admin roster, the Admin Company workforce cards and the employee's own Attendance page, following the four rules the notification bell already implements (`Components/Notifications/notifications.ts`) rather than a second polling pattern. Test added.
2. **Phase 6 upgrades that poll to a channel** rather than leaving it beside one. `attendance.{date}` added to the Reverb channel list with its authorisation rule; the Phase 4 interval is explicitly removed at that point, and a test asserts both the live update and that the interval is gone. Without this line Reverb would have shipped for chat while the roster kept polling — or kept reloading — forever.
3. The notification bell is named as the reference implementation for polling, so "how often and under what rules" is answered in one place.

Scope unchanged: no new screen, table or endpoint — two existing screens learn to refresh, and one channel is added to a phase that was already installing Reverb.

## Changes in v1.2.1 (17 Sep 2026)

- Part 0.4 kick-off prompts (dispatch Phase 0, resume, plain, UI line); rules renumbered to 0.5.
- `shadcn-tokens` MCP declared the design-token source of truth (Part I), attached to the frontend agent (Part J.1 step 5), required in UI briefs (J.3), and used by Phase 0 to write `resources/css/app.css`.

*End of master prompt v1.2.1.*
