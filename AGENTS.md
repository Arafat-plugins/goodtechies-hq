<!-- dispatch:map v1 -->
# AGENTS.md — repo map for sub-agents

Read this before touching anything. It replaces surveying the codebase.

## What this project is
GoodTechies HQ is an internal agency operating system for one client: clients, projects,
tasks, time and attendance, leave, chat, meetings, finance and payroll. It has three separate
UI surfaces (Admin, Employee, Accountant) and **privacy by role is enforced on the backend**.

- **Stack:** Laravel 13 (PHP ^8.3, lockfile pinned to platform 8.3.0), Inertia 3 + Vue 3.5
  (`<script setup lang="ts">`), Tailwind v4 + shadcn-vue (reka-ui), icons from `@lucide/vue`,
  PostgreSQL 16, Redis, Pest 4, and Node 22 for builds.
- **Deploy target:** one Ubuntu VPS.
- **Source of truth:** `docs/master-prompt-v1.md` (the plan) and `PROJECT_BRIEF.md` (a summary).
  `PROGRESS.md` holds build state. Briefs quote the parts you need, so do **not** read the
  140 KB master prompt unless a brief tells you to.

## Layout — what lives where
| Path | Holds | Edit when |
| --- | --- | --- |
| `app/Http/Controllers/{Admin,Employee,Accountant,Shared}/` | Controllers per surface | the brief names the controller |
| `app/Http/Middleware/` | `HandleInertiaRequests`, `EnsureSurface`, `TwoFactor` | surface or 2FA rules |
| `app/Http/Requests/` | Form Requests: **all** validation lives here | new or changed input |
| `app/Http/Resources/` | Field-level serializers (`ProjectResource`, `PayrollItemResource`, …) | response shape |
| `app/Policies/` | One policy per model, deny by default | authorization |
| `app/Services/` | Business logic (`AuditLogger`, `ActivityLogger`, `SettingsService`, …) | rules and state machines |
| `app/Support/` | Enums and keys (`Permission`, `Role`, `TrackingMode`, statuses) | new keys |
| `app/Console/Commands/` | `hq:*` artisan commands | scheduled or ops jobs |
| `app/Events/`, `app/Listeners/`, `app/Jobs/` | Events; the one `NotificationDispatcher` listener; queue jobs | from Phase 2 |
| `database/migrations/` | Added phase by phase, never ahead (Phase 0 identity/audit; Phase 1 clients, projects, project_finance, project_members) | schema |
| `database/seeders/` | `RolePermissionSeeder`, `SettingsSeeder`, `TeamSeeder`, `DemoSeeder` (Phase 1+) | seed data |
| `routes/` | `web.php` includes the surface files `auth.php`, `admin.php`, `employee.php`, `accountant.php`, `shared.php`; `console.php` holds the schedule | routes |
| `resources/js/Layouts/` | `AdminLayout.vue`, `EmployeeLayout.vue`, `AccountantLayout.vue`, `AuthLayout.vue`, each a **separate** shell | shell chrome |
| `resources/js/Pages/{Admin,Employee,Accountant,Auth,Shared}/` | Inertia pages | screens |
| `resources/js/Components/` | Shared components; `Components/ui/` holds the shadcn-vue primitives | reusable UI |
| `resources/css/app.css` | Tailwind v4 plus the shadcn token variables (the only colour source) | tokens only via a DESIGN.md brief |
| `tests/Feature/`, `tests/Unit/`, `tests/Permissions/` | Pest tests; `Permissions/` holds the role × route matrix with its route-coverage guard | every brief |
| `deploy/` | nginx, supervisor, `install.sh`, `deploy.sh`, `sql/roles.sql`, `.env.production.example` | new worker, env key or channel |
| `docs/` | Master prompt, `decisions.md`, `runbooks/`, `design-refs/` (client images) | docs briefs |
| `vendor/`, `node_modules/`, `public/build/`, `storage/`, `bootstrap/cache/` | **Generated or vendored** | never |

## Where things are
- **Entry points:** `bootstrap/app.php` (routing, middleware aliases, exceptions), `public/index.php`
- **Routes:** `routes/web.php` plus the surface files listed above. The source of truth is `php artisan route:list --except-vendor`.
- **Frontend:** `resources/js/app.ts` (the Inertia resolver for `./Pages/**/*.vue`), `resources/views/app.blade.php` (root view), `vite.config.ts` (`@` alias → `resources/js`)
- **Config:** `config/*.php`, `.env` (git-ignored), `.env.example`, and `phpunit.xml` for the test env
- **Database:** PostgreSQL 16, configured in `config/database.php`. There are three connections, one per role:
  - `pgsql` (default) is the runtime role `hq_app`.
  - `pgsql_migrator` is the schema owner `hq_migrator`; migrations always run on it.
  - `pgsql_ro` is the SELECT-only role `hq_ro`.
  - Tests build the schema through `pgsql_migrator`, database `goodtechies_hq_test`.
- **Tests:** `tests/Pest.php` binds `TestCase` + `RefreshDatabase` for the `Feature` and `Permissions` folders.

### Surfaces
Generated 2026-09-17 from `php artisan route:list --except-vendor` (plus framework `GET /up`).
**Stale since Phase 2** — it does not list the tasks, files, tags or discussion routes that
slices 1-4 added. Run the command for the truth; this table is still right about Phase 0 and 1.
The **Styles** column is historical.
Every page now opens with `PageShell`; admin lists use `DataTable` + the chip `FilterBar`; an
unbuilt panel is `Card` + `EmptyState`. See DESIGN.md §4.
| Surface (route) | Entry | View | Styles |
| --- | --- | --- | --- |
| `GET /` | `HomeController` (redirects to login or the user's shell) | none | none |
| `GET/POST /login`, `POST /logout` | `Auth/LoginController` | `Pages/Auth/Login.vue` in `AuthLayout` | utility classes |
| `/two-factor/challenge` (GET/POST) | `Auth/TwoFactorChallengeController` | `Pages/Auth/TwoFactorChallenge.vue` | utility classes |
| `/two-factor/enrol` (GET/POST), `GET /two-factor/recovery-codes` | `Auth/TwoFactorEnrolmentController` | `Pages/Auth/TwoFactorEnrol.vue`, `RecoveryCodes.vue` | utility classes |
| `GET /admin/dashboard` | `Admin/DashboardController` | `Pages/Admin/Dashboard.vue` in `AdminLayout` | utility classes |
| `GET /admin/settings` | `Admin/SettingsController` | `Pages/Admin/Settings.vue` | utility classes |
| `GET /employee/dashboard` | `Employee/DashboardController` | `Pages/Employee/Dashboard.vue` in `EmployeeLayout` | utility classes |
| `GET /accountant/dashboard` | `Accountant/DashboardController` | `Pages/Accountant/Dashboard.vue` in `AccountantLayout` | utility classes |
| `/admin/clients` (index/create/store/show/edit/update) + `POST /admin/clients/{client}/deactivate` | `Admin/ClientController` | `Pages/Admin/Clients/{Index,Create,Edit,Show}.vue`, `Components/Clients/*` | utility classes |
| `/admin/projects` (index/create/store/show/edit/update) | `Admin/ProjectController` | `Pages/Admin/Projects/{Index,Create,Edit,Show}.vue`, `Components/Projects/*` | utility classes |
| `PUT /admin/projects/{project}/finance` · `/members` · `POST …/status` · `/archive` · `/unarchive` | `Admin/Project{Finance,Member,Status}Controller` | rendered inside `Pages/Admin/Projects/Show.vue` | utility classes |
| `/employee/projects`, `/employee/projects/{project}` | `Employee/ProjectController` | `Pages/Employee/Projects/{Index,Show}.vue`, `Components/Employee/*` | utility classes |
| `/profile` (GET/PUT), `PUT /profile/password`, `DELETE /profile/two-factor`, `POST /profile/two-factor/recovery-codes`, `DELETE /profile/sessions/{session}` | `Shared/Profile*Controller` | `Pages/Shared/Profile.vue` (layout chosen from `auth.user.surface`), `Components/Profile/*` | utility classes |

- **Shell building blocks:** `Components/Shell/*` (skip link, sidebar + rail toggle, Coming-soon disclosure, top bar, ⌘K palette, quick create, bell, user menu, theme toggle, mobile sheet) and `navigation/{admin,employee,accountant}.ts`. To enable a nav item, give it an `href` and remove its `phase`. `SkipToContent.vue` must stay the first child of each layout, and each layout's `<main>` keeps `id="main-content" tabindex="-1"`.
- **Shared blocks:** `Components/{PageShell,StatusBadge,EmptyState,Toaster,DetailDrawer,FilterBar,FilterChip,StatCard,AppWordmark,FlashMessage,Pagination,StatusPill}.vue`, plus `DataTable/*`, `Skeletons/*`, `Charts/*` and `Dashboard/*`. `StatusPill.vue` also exports `toneForProjectStatus()`; `Pagination.vue` exports the `Paginated<T>` type; `Charts/chartTokens.ts` is how a chart reads CSS variables.
- **`PageHeader.vue` and `PlaceholderPanel.vue` no longer exist** — Phase 0.5 deleted them. `PageShell` replaces the first; `Card` + `EmptyState` replaces the second. Do not reintroduce either.
- **Read `DESIGN.md` before writing any Tailwind class.** It carries every token with its light and dark value, the real signature of every shared component, and the 20 things that are never allowed. It is generated from `app.css`, which wins if the two ever disagree.
- **Route file ownership:** `routes/auth.php`, `admin.php`, `employee.php`, `accountant.php`, `shared.php`. The schedule lives in `routes/console.php`.
- **Services:** `AuditLogger`, `ActivityLogger`, `SettingsService`, `EmployeeAdministrationService`, `TwoFactorService`, `SessionService`, `ClientService`, `ProjectService`, `ProjectFinanceService`.
- **Serializers:** `ProjectResource` and `ClientResource` — the only way a project or client leaves the server. A project's **status changes only** through `POST …/status`, `…/archive`, `…/unarchive`; `PUT /admin/projects/{id}` ignores a `status` key by design.
- **Policies:** `EmployeePolicy`, `ProjectPolicy` (view, create, update, archive, unarchive, cancel, manageMembers, viewCommercial, viewFinance, updateFinance), `ClientPolicy`. `Project::visibleTo($user)` scopes every list.
- **Middleware aliases:** `surface:<admin|employee|accountant>` and `two-factor`.
- **Gates:** one per permission value (`can:settings.manage`).
- **Enums** (`app/Support`):
  - `Permission`: PascalCase cases, dotted values.
  - `RoleName`: cases `ADMIN` …
  - `TrackingMode`, `UserStatus`, `AuditEvent`, `Surface`.
- **Commands:** `hq:verify-backup` (`app/Console/Commands/VerifyBackup.php`).

## Conventions that will get a change rejected
- **Privacy is enforced on the backend, never only in the UI:**
  - A field the requester may not see is **absent** from the payload (not null, not masked).
  - A record they may not see is omitted from lists and returns **404** by id.
  - A route or surface their role may not use returns **403** (`EnsureSurface` / policy).
- **Serializers:** projects and payroll items leave the server only through `ProjectResource` / `PayrollItemResource`. Never return raw model JSON for them.
- **Permission keys** are dotted (`clients.view_full`, `projects.view_finance`, …) and defined once in `app/Support/Permission.php`. Scoped (🟡) rules are the key **plus** a scope check in the Policy, never a new key.
- **Audit and activity logs:**
  - `audit_logs` is written only through `App\Services\AuditLogger`. It is append-only: `hq_app` has no UPDATE, DELETE or TRUNCATE on it.
  - `activity_logs` is a separate table, written through `ActivityLogger`.
- **Validation:** only in Form Requests. **Authorization:** only in Policies and middleware, never in Vue.
- **Settings** keys are only the list in `SettingsSeeder`. Adding one requires a recorded decision.
- **Scope:** no productivity score, anywhere. Nothing from PROJECT_BRIEF.md "Out of scope".
- **Code style:**
  - PHP: PSR-12, 4 spaces, `declare` not required; run `vendor/bin/pint` on the PHP files you touched.
  - Vue: SFCs with `<script setup lang="ts">`, 4 spaces, typed props.
  - Icons come from `@lucide/vue`; `lucide-vue-next` is deprecated and not installed.
  - New shadcn-vue components are added only with `env -u https_proxy NODE_USE_ENV_PROXY=1 npx shadcn-vue@latest add <name>`, and generated `Components/ui/*` files are not hand-edited.
- **UI:**
  - Classes are token classes only (`bg-primary`, `text-muted-foreground`, `bg-sidebar`, …). No hex values or arbitrary colours, and no off-scale spacing (see DESIGN.md).
  - Every page must work at 375 / 768 / 1280 px.
- **Tests:** every new route gets a row in `tests/Permissions/MatrixTest.php`, or the coverage guard fails. Every privacy rule ships its negative test in the same brief.
- **Seed data:** seeded passwords and dev 2FA secrets come from `SEED_*` env keys, never literals in the repo.

## Do NOT
- do NOT edit `vendor/`, `node_modules/`, `public/build/` (built by `npm run build`), `bootstrap/cache/`, `composer.lock` or `package-lock.json`, except in a dependency brief
- do NOT add a dependency unless the brief is a dependency brief
- do NOT run migrations on the `pgsql` (hq_app) connection. Migrations always use `--database=pgsql_migrator`.
- do NOT make the Accountant shell import anything from `Layouts/AdminLayout.vue` or `Pages/Admin/`
- do NOT build ahead of the current phase, and do NOT add a placeholder route. A disabled nav item labelled "arrives in Phase N" is the only allowed placeholder.
- do NOT commit, push or change git state
- do NOT edit `docs/master-prompt-v1.md`, `docs/design-refs/*`, `PROJECT_BRIEF.md` or `PROGRESS.md` (the main session owns them)

## Commands
| Purpose | Command |
| --- | --- |
| Lint (PHP) | `vendor/bin/pint --test` |
| Type-check (Vue/TS) | `npx vue-tsc --noEmit` |
| Test | `php artisan test` (all) · `php artisan test --group=permissions` |
| Build | `npm run build` |
| Migrate (dev) | `php artisan migrate:fresh --seed --database=pgsql_migrator` |
| Dev server | `npm run build && php artisan serve --host=127.0.0.1 --port=8000` (run it in the background and kill it when done) |
| Deploy kit test | `deploy/test/run-install-test.sh` (fresh Ubuntu 24.04 container; needs Docker) |
| Verify backup | `php artisan hq:verify-backup [--disk=…]` |
| Measure widths | `node .claude/dispatch/dispatch-measure.mjs http://127.0.0.1:8000/<path> 375 768 1280` |
| Render a signed-in page | a brief that needs one is given the helper's path; it logs in as a seeded user (computing the TOTP where the role has 2FA) and reports overflow per width |

The local cloud workspace has PostgreSQL 16 on `127.0.0.1:5432`, superuser `postgres`, trust auth (dev only), and Redis on `127.0.0.1:6379`.

## Known-failing baseline
Measured 2026-09-22 at `b6d6146` (Phase 2, slice 5 complete — notifications, My Tasks, dashboard
cards) with `php vendor/bin/pest`: none failing (**1011 passed, 5546 assertions**).
`vendor/bin/pint --test`: passed. `npx vue-tsc --noEmit`: passed. `npm run build`: passed. If
your number is not 1011, that is a finding, not drift.

**Two suites cannot share this checkout.** `php artisan test` runs in parallel here and
deadlocks on migration DDL before any test body runs — use `php vendor/bin/pest`. And if a
second agent is testing at the same time, a private database is *not* enough isolation:
`Storage::fake()` targets the shared `storage/framework/testing/disks/local`, so one suite's
`beforeEach` deletes the other's bytes mid-run and the file tests fail on missing bytes. Two
concurrent sub-agents must not both run the suite.

**Files are policy-checked on every fetch, not bearer-signed.** `FileService::url()` mints a
`temporarySignedRoute`, and the download route runs `FilePolicy::view` — a forwarded link is 404
for someone who may not see the owning record. Never mint a `Storage::url()` or
`temporaryUrl()`; `local.serve` is off so there is no bearer route to reach.

**A conversation's membership is computed, never stored.** `ConversationPolicy` asks the linked
task's `TaskPolicy::view`. `conversation_members` is read state (`last_read_at`) and grants
nothing — do not add a membership check, and do not sync a list.

**A task's status is guarded at the model.** `Task` throws
`TaskStateException::statusWrittenOutsideTheMachine()` if `status` is dirty on an existing row
and the write did not come through `Task::applyTransition()`. Every status move — form, drag,
job, command — goes through `TaskService::transition()`. Do not add a second path, and do not
reach for `withoutStatusGuard()` outside seeding.

## Verification capabilities

Measured 2026-09-17 by `/dispatch setup`:

- Dev server: `npm run build && php artisan serve --host=127.0.0.1 --port=8000` → http://127.0.0.1:8000. There is no Vite dev server; pages are measured against the built assets.
- Rendering: local Playwright (`playwright@1.56.1`, dev dependency, Chromium from `PLAYWRIGHT_BROWSERS_PATH=/opt/pw-browsers`); run `.claude/dispatch/dispatch-measure.mjs`. Frontend agent tools: `Bash, Read, Edit, Write, Grep, Glob`. The browser is reached through the script, not an MCP.
- Design source: **DESIGN.md**, regenerated at the end of Phase 0.5 from `app.css` with 52 measured contrast pairs. The shadcn-tokens MCP is **not** reachable in this environment, so DESIGN.md and `resources/css/app.css` are the source. `CLAUDE.md` still tells you to call that MCP first; it is stale on this point and DESIGN.md wins.
- Accessibility floor, established by Phase 0.5 and not to be regressed: no surface overflows horizontally at 360/375/768/1280; every tab stop paints a visible focus ring; an overlay returns focus to whatever opened it; the skip link is the first tab stop on every shell page; and no status or state is carried by colour alone.
- Database: postgres, read-only user `hq_ro` (`DB_RO_*` in `.env`, created by `deploy/sql/roles.sql`)
- Lint / test / build: see Commands

## Agents
| Role | Agent |
| --- | --- |
| Server logic, migrations, services, tests, deploy kit | `dispatch-implementer` |
| Vue pages, layouts, tokens, responsive | `dispatch-frontend` |
| Read-only DB checks | `dispatch-db-tester` (uses `pgsql_ro` only) |
| Security review of a diff | `dispatch-security-critic` |

## What not to bother reading
- `docs/master-prompt-v1.md` (140 KB): the brief quotes what you need
- `docs/design-refs/*.png`: open only the one file a UI brief names
- `resources/js/Components/ui/`: generated shadcn-vue primitives; use them, don't read them
- `vendor/`, `node_modules/`: third-party code
- `composer.lock`, `package-lock.json`: generated
- `config/*.php` other than the file a brief names: Laravel skeleton defaults
- `storage/`, `bootstrap/cache/`: runtime output
<!-- /dispatch:map -->
