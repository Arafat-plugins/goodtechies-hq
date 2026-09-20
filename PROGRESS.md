# GoodTechies HQ — Build Progress

> Source of truth: `docs/master-prompt-v1.md` (v1.2), condensed from the client spec *GoodTechies HQ — Agency Operating System v1.0 (Sept 2026)* + the client's "Application Visuals" design doc + current ClickUp workspace (`docs/design-refs/`).
> Updated at the end of every phase and after every gate. A new session must be able to continue from this file alone.

**Last updated:** 17 Sep 2026 · **Current phase:** 0 — Foundation · **Status:** waiting for **GATE A**
**Test suite:** `php artisan test` → 167 / 167 passed · **Deployed on VPS:** no. The deploy kit passed in a fresh Ubuntu 24.04 container; see the deployment log. · **Execution method:** dispatch v1.5.1

---

## Overall progress

**Phase 0 of 12 built — waiting for GATE A (≈ 8 %)**

`[███                             ]`

| Phase | Vertical slice | Gate | Status |
| --- | --- | --- | --- |
| 0 | Foundation: auth + 2FA, roles, three shells, audit/activity logs, CI, VPS deploy kit, backups | **GATE A** | 🔄 built — waiting for GATE A |
| 1 | Clients & Projects with the privacy model | **GATE B** | ⬜ |
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
- **Seeded logins:** `shahadat@goodtechies.test`, `faruk@goodtechies.test` (Admin), `tapu@goodtechies.test` (Remote), `yaseen@goodtechies.test` (Employee), `accountant@goodtechies.test` (Accountant).
  - Password: `SEED_PASSWORD`.
  - Admins and the Accountant also need a TOTP code from `SEED_TWO_FACTOR_SECRET` (local/testing only; production forces enrolment at first login).
- **Tests:**
  - `php artisan test` runs everything (167).
  - `php artisan test --group=phase0` and `php artisan test --group=permissions` run subsets.
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

## Deployment log

| Date | Commit | Server | Result |
| --- | --- | --- | --- |
| 2026-09-17 | `1ac6aa6` (kit tested at `7245aeb` + kit) | Fresh Ubuntu 24.04 **container** (not the VPS): `deploy/test/run-install-test.sh` | PASS 7/7: /login 200, / 302, 5 users, audit_logs `ar`, hq-queue RUNNING, production env, shellcheck. See `docs/runbooks/install-log-2026-09-17.md` |

---

## Next step

**Stop: waiting for GATE A.** The user should:

1. Check login, the forced 2FA set-up and the three shells in the browser.
2. Answer the questions above.
3. Decide on the medium security finding (recommended: fix it before Phase 1).

After GATE A:

1. Run the security fix brief, if approved.
2. Record the confirmed decisions in the table above.
3. Re-run `/dispatch bootstrap` if the map drifted.
4. Start **Phase 1 — Clients & Projects**: migrations → ProjectResource and policies → controllers with negative privacy tests → Admin UI → Employee UI → matrix rows → verify.
