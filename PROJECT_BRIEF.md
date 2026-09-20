# GoodTechies HQ — Project Brief

> Derived from `docs/master-prompt-v1.md` (v1.2.1) per Part J.1 step 1. The `/dispatch new`
> intake was **not** run: every answer below already exists in the master prompt, which wins
> on any disagreement. Approved by the user (Mamun) on 17 Sep 2026 as "pre-approved, run to GATE A".

## 1. Purpose and users

One internal operating system for **GoodTechies**, a small digital agency (WordPress,
WooCommerce, SEO, maintenance, marketing). It replaces ClickUp, Asana, Telegram, spreadsheets
and separate attendance/finance tools. One chain links everything:
Client → Project → Task → Discussion → Files → Time/Attendance → Status → Completion → Reporting,
with Finance/Payroll running in parallel.

Users (seeded):

| Person | Role | Tracking mode |
| --- | --- | --- |
| Shahadat Hossain | ADMIN | office_attendance |
| Faruk Ahmed | ADMIN | office_attendance |
| Tapu | REMOTE_EMPLOYEE | remote_timer (5 h/day target) |
| Yaseen | EMPLOYEE | office_attendance |
| Accountant | ACCOUNTANT | none |

MANAGER exists as a role but is assigned to nobody. Two Admins are equal; an Admin cannot change
their own role or deactivate themself.

Three surfaces, one data model: **Admin** (full visibility, plus a personal My Work view),
**Employee** (narrow, "what should I do now?"), **Accountant** (separate shell, finance and
payroll only, plus My Leave / My Payslip).

## 2. Platform

A standalone web application, served from one VPS. Admin screens are desktop-first. Employee
screens must work on a phone. No native mobile app and no PWA in the MVP.

## 3. Tech stack (locked — no substitutions)

- Laravel 13 (the current supported major; the spec says 11, which is out of security support), PHP 8.3+
- PostgreSQL 16 with two runtime roles plus a read-only role: `hq_migrator` (owns the schema, runs migrations), `hq_app` (the app at runtime), `hq_ro` (SELECT only)
- Vue 3 (Composition API) + Inertia.js + Vite + Tailwind v4 + shadcn-vue components
- Laravel Reverb + Echo for realtime (from Phase 6), with a polling fallback
- Redis 7 + Laravel Queues
- S3-compatible object storage, served through signed URLs
- Session auth plus TOTP 2FA; Sanctum device tokens from Phase 11
- PostgreSQL full-text search
- Google Calendar, one-way create only (from Phase 7)
- Pest for all tests, including a role × route permission matrix with a route-coverage guard

## 4. MVP scope

The 13 vertical phases in master prompt Part E, built strictly in order, with gates:

0. Foundation — auth + 2FA, roles, three shells, audit/activity logs, CI, VPS deploy kit, backups — **GATE A**
1. Clients & Projects with the privacy model — **GATE B**
2. Tasks (List / Board / Calendar, tags, files, task discussion, in-app notifications)
3. Recurring task engine + fixed automation rules
4. Remote timer + Timesheet + office attendance + schedules + workload — **GATE C**
5. Leave + holidays
6. Team communication + realtime — **GATE D**
7. Meetings + Google Meet (one-way)
8. Finance + Accountant surface
9. Payroll + payslips — **GATE E**
10. Reports + global search + Gantt + final dashboards
11. Activity-tracking browser extension — **blocked** until the client amends spec §46 — **GATE F**
12. Admin tools, backup health, UX polish, ClickUp import, hardening, cutover — **Final review**

## 5. Data and auth

- The authoritative table list is master prompt Part D §20. Each table is created in the phase that first needs it.
- Privacy by role is enforced on the backend (Part C §1–§2):
  - Restricted **fields** are absent from the payload.
  - Restricted **records** return 404.
  - Restricted **surfaces or routes** return 403.
- `ProjectResource` and `PayrollItemResource` are the only serializers for projects and payroll items.
- `audit_logs` is append-only, enforced by PostgreSQL grants: `hq_app` has no UPDATE, DELETE or TRUNCATE on it.
- Permission keys are dotted (`clients.view_full`, `projects.view_finance`, …).
- 2FA is mandatory for ADMIN and ACCOUNTANT from Phase 0.
- Seed passwords and dev 2FA secrets come from `.env`, never from the repo.

## 6. Look and feel

- **Visual patterns:** from `docs/design-refs/` (Part I). Take the listed patterns only, never the products.
- **Palette** (the spec's):
  - Deep Navy `#1B4B66`: sidebar and primary
  - Teal `#2E8B8B`: active/accent
  - Slate `#8A9BA3`: metadata
  - Soft Grey-Blue `#EEF3F5`: banding
  - Body `#2B2B2B`
- **Surfaces and type:** light content area, white cards with a 12 px radius and hairline border, Inter font.
- **Sidebar:** grouped small-caps labels.
- **Layout rules:** at most 3 charts per screen; the same filter/sort bar on every list.
- **Tokens:** the palette is written into `resources/css/app.css` as shadcn variables in oklch, light and dark. Every class uses those token classes only.
  - The shadcn-tokens MCP was not reachable in the Phase 0 session, so the palette values were derived by hand from the shadcn defaults (recorded decision).
- **Responsive:** every page is checked at 375 / 768 / 1280 px.
  - Admin is desktop-first; below 1024 px the sidebar collapses into a drawer.
  - Employee and Accountant shells are fully usable at 375 px.

## 7. Integrations

- S3-compatible storage (R2/S3).
- Google Calendar/Meet, one-way. The `manual` driver is the default until a Workspace account exists.
- No Slack, Zoom or other integrations.

## 8. Hosting

One client-owned Ubuntu 22.04/24.04 VPS (Part B §4):

- Nginx → PHP-FPM, with Certbot for HTTPS
- PostgreSQL 16 and Redis 7 on localhost
- Supervisor for `hq-queue` and `hq-reverb`
- cron for `schedule:run`
- ufw: only ports 22, 80 and 443 open
- Encrypted daily backups (`spatie/laravel-backup`) to a different provider or region, verified weekly by `hq:verify-backup`
- `deploy/install.sh` for the first install, `deploy/deploy.sh` for every release

## 9. Constraints

- App timezone: Asia/Dhaka.
- UI and briefs in English; no Bangla UI.
- Currency defaults to USD.
- No productivity scores, anywhere.
- Build in vertical slices only.
- Stop at every gate.
- Collect open questions in `PROGRESS.md` and ask them only at gates.

## 10. Definition of done

Master prompt Part F:

- All 12 acceptance criteria pass (13 if Phase 11 is built).
- The permission suite covers 100 % of routes.
- Deployed on the VPS through the deploy kit, with a restore drill recorded.
- Runbooks are complete.
- The client's ClickUp export imports cleanly.

Each phase is done when its feature is clickable end to end on seeded data and `php artisan test` is green for it and for every earlier phase.

## Out of scope for v1

(Master prompt Part H §1.)

- **Sales, HR and business tools:** CRM pipelines, recruitment, inventory, double-entry accounting, invoicing and recurring billing.
- **Monitoring and scoring:** productivity scoring or gamification, keylogging, hidden monitoring, screenshots.
- **Hardware attendance:** the FlowZa features (devices, sync jobs, reconciliation, shifts, branches).
- **Client-facing and automation:** a client portal, a no-code workflow builder.
- **Other integrations:** anything beyond Google Calendar/Meet.
- **Apps and search:** native mobile apps, a dedicated search engine.
- **Deferred to spec post-MVP Phase 2:**
  - two-way calendar sync, browser push, email digests
  - PDF/CSV report exports, PWA, the workload timeline visual
  - 2FA for all roles, a MANAGER UI
