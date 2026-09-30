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
| `database/migrations/` | Added phase by phase, never ahead (Phase 0 identity/audit; Phase 1 clients, projects, project_finance, project_members; Phase 2 tasks, task_assignees, task_checklists, task_links, task_dependencies, tags, task_tags, files, conversations, conversation_members, messages, message_attachments, notifications) | schema |
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
Regenerated **2026-09-23** at the Phase 2 close-out from `php artisan route:list --except-vendor`
(107 routes, plus the framework's `GET /up`). It covers everything up to Phase 2's tasks, board,
calendar, my-tasks, tags, files, discussion and notification routes, and **has not been
regenerated since** — Phases 3-10 added recurring tasks, the timer and timesheets, leave and
holidays, messaging and voice, meetings, finance, payroll, global search, the Gantt view and
reports without touching it. So a route missing from this table means the table is old, never
that the route does not exist. **`php artisan route:list --except-vendor` is the source of
truth**; this table is a map of the oldest third of it. Regenerating it is Phase 12's close-out.
Every page opens with `PageShell`; admin lists use `DataTable` + the chip `FilterBar`; an
unbuilt panel is `Card` + `EmptyState`. See DESIGN.md §4.

| Surface (route) | Entry | View |
| --- | --- | --- |
| `GET /` | `HomeController` (redirects to login or the user's shell) | none |
| `GET/POST /login`, `POST /logout` | `Auth/LoginController` | `Pages/Auth/Login.vue` in `AuthLayout` |
| `/two-factor/challenge` (GET/POST) | `Auth/TwoFactorChallengeController` | `Pages/Auth/TwoFactorChallenge.vue` |
| `/two-factor/enrol` (GET/POST), `GET /two-factor/recovery-codes` | `Auth/TwoFactorEnrolmentController` | `Pages/Auth/TwoFactorEnrol.vue`, `RecoveryCodes.vue` |
| `GET /admin/dashboard` | `Admin/DashboardController` | `Pages/Admin/Dashboard.vue` in `AdminLayout` |
| `GET /admin/settings` | `Admin/SettingsController` | `Pages/Admin/Settings.vue` |
| `GET /employee/dashboard` | `Employee/DashboardController` | `Pages/Employee/Dashboard.vue` in `EmployeeLayout` |
| `GET /accountant/dashboard` | `Accountant/DashboardController` | `Pages/Accountant/Dashboard.vue` in `AccountantLayout` |
| `/admin/clients` (index/create/store/show/edit/update) + `POST …/deactivate` | `Admin/ClientController` | `Pages/Admin/Clients/{Index,Create,Edit,Show}.vue`, `Components/Clients/*` |
| `/admin/projects` (index/create/store/show/edit/update) | `Admin/ProjectController` | `Pages/Admin/Projects/{Index,Create,Edit,Show}.vue`, `Components/Projects/*` |
| `PUT /admin/projects/{project}/finance` · `/members` · `POST …/status` · `/archive` · `/unarchive` | `Admin/Project{Finance,Member,Status}Controller` | rendered inside `Pages/Admin/Projects/Show.vue` |
| `/employee/projects`, `/employee/projects/{project}` | `Employee/ProjectController` | `Pages/Employee/Projects/{Index,Show}.vue`, `Components/Employee/*` |
| `/profile` (GET/PUT), `PUT /profile/password`, `DELETE /profile/two-factor`, `POST /profile/two-factor/recovery-codes`, `DELETE /profile/sessions/{session}` | `Shared/Profile*Controller` | `Pages/Shared/Profile.vue` (layout chosen from `auth.user.surface`), `Components/Profile/*` |

**Phase 2 — tasks.** Three views of one query per surface; the filters travel as query
parameters, which is why every controller echoes `filters` back.

| Surface (route) | Entry | View |
| --- | --- | --- |
| `GET /admin/tasks` · `/board` · `/calendar` | `Admin/TaskController@{index,board,calendar}` | `Pages/Admin/Tasks/{Index,Board,Calendar}.vue` → `Components/Tasks/{TaskList,TaskBoard,TaskCalendar}.vue` |
| `GET /employee/tasks` · `/board` · `/calendar` | `Employee/TaskController@{index,board,calendar}` | `Pages/Employee/Tasks/{Index,Board,Calendar}.vue`, same three components |
| `GET /admin/tasks/{task}`, `GET /employee/tasks/{task}` | `{Admin,Employee}/TaskController@show` | `Pages/{Admin,Employee}/Tasks/Show.vue` → `Components/Tasks/TaskDetailBody.vue`. The **same body** mounts in `TaskDetailDrawer.vue` from a row click, which fetches the detail route over `X-Inertia` |
| `POST /admin/tasks` (store) · `PUT …/{task}` · `DELETE …/{task}` | `Admin/TaskController` | `QuickAddTaskModal.vue`, `TaskFieldsPanel.vue` |
| `POST …/tasks/{task}/status` · `/reorder` · `/handoff` · `/archive` · `/unarchive` (admin only) | `{Admin,Employee}/TaskController` | `TaskStatusActions.vue` (the one status control; the Board reuses it `headless`), `TaskPeoplePanel.vue` |
| `PUT /admin/tasks/{task}/assignees` — **Admin only** | `Admin/TaskController@assignees` | `TaskPeoplePanel.vue` |
| `…/tasks/{task}/checklist` (POST/PUT/DELETE), `…/links` (POST/DELETE) — both surfaces | `{Admin,Employee}/TaskController` | `TaskChecklistPanel.vue`, `TaskLinksPanel.vue` |
| `…/tasks/{task}/dependencies` (POST/DELETE) — **Admin only** | `Admin/TaskController` | `TaskDependenciesPanel.vue` (read-only on the employee surface) |
| `GET /admin/my-tasks`, `GET /employee/my-tasks` | `{Admin,Employee}/MyTaskController@index` | `Pages/{Admin,Employee}/MyTasks.vue` → `Components/Tasks/MyTasks.vue` (one component, both surfaces) |

**Phase 2 — tags, files, discussion, notifications.**

| Surface (route) | Entry | View |
| --- | --- | --- |
| `/admin/tags`, `/employee/tags` (index/store/update/destroy) | `{Admin,Employee}/TagController` | `Components/Tags/TagManagerDialog.vue`, opened from `TaskFilterBar.vue` |
| `GET/POST /admin/projects/{project}/files` | `Admin/ProjectFileController` | Files tab in `Pages/Admin/Projects/Show.vue` → `Components/Files/FilePanel.vue` |
| `GET/POST /admin/clients/{client}/files` | `Admin/ClientFileController` | Files tab in `Pages/Admin/Clients/Show.vue` → `FilePanel.vue` |
| `GET/POST /admin/tasks/{task}/files`, `…/employee/…` | `{Admin,Employee}/TaskFileController` | Attachments panel in `TaskDetailBody.vue` → `FilePanel.vue` |
| `GET/POST /{admin,employee}/files/{file}/versions`, `DELETE /{admin,employee}/files/{file}` | `{Admin,Employee}/FileController` | the per-row controls inside `FilePanel.vue` |
| `GET /files/{file}` | `Shared/FileDownloadController` | none — a `temporarySignedRoute` re-checked by `FilePolicy` on every fetch |
| `GET/POST /{admin,employee}/tasks/{task}/discussion` | `{Admin,Employee}/TaskDiscussionController` | `Components/Tasks/TaskDiscussionPanel.vue` |
| `GET /notifications`, `GET /notifications/recent`, `POST /notifications/{n}/read`, `POST /notifications/read-all` | `Shared/NotificationController` | `Pages/Shared/Notifications.vue` (the Center) and `Components/Shell/NotificationBell.vue`, both through `Components/Notifications/notifications.ts`. **`/notifications` is one route serving two readers** — the Inertia page and the bell's JSON — so the popover and the page cannot disagree |

There is **no** project- or client-files route on the employee surface: `fileRoutes()` is
overloaded so asking for one is a compile error rather than a 404 found in staging.

- **Shell building blocks:** `Components/Shell/*` (skip link, sidebar + rail toggle, Coming-soon disclosure, top bar, ⌘K palette, quick create, bell, user menu, theme toggle, mobile sheet) and `navigation/{admin,employee,accountant}.ts`. To enable a nav item, give it an `href` and remove its `phase`. `SkipToContent.vue` must stay the first child of each layout, and each layout's `<main>` keeps `id="main-content" tabindex="-1"`.
- **Shared blocks:** `Components/{PageShell,StatusBadge,EmptyState,Toaster,DetailDrawer,FilterBar,FilterChip,StatCard,AppWordmark,FlashMessage,Pagination,StatusPill}.vue`, plus `DataTable/*`, `Skeletons/*`, `Charts/*` and `Dashboard/*`. `StatusPill.vue` also exports `toneForProjectStatus()`; `Pagination.vue` exports the `Paginated<T>` type; `Charts/chartTokens.ts` is how a chart reads CSS variables.
- **Phase 2 blocks:** `Components/Tasks/*` (the three views, the detail body and its panels, the status control, `MyTasks.vue`, `taskDetail.ts`, `taskBoard.ts`), `Components/Files/{FilePanel.vue,files.ts}`, `Components/Tags/{TagManagerDialog,TagColourSelect}.vue` and `Components/Notifications/{NotificationRow.vue,notifications.ts}`. **Every signature is in DESIGN.md §4.5, §4.7 and §4.8** — read it rather than the component.
- **`PageHeader.vue` and `PlaceholderPanel.vue` no longer exist** — Phase 0.5 deleted them. `PageShell` replaces the first; `Card` + `EmptyState` replaces the second. Do not reintroduce either.
- **Read `DESIGN.md` before writing any Tailwind class.** It carries every token with its light and dark value, the real signature of every shared component, and the 20 things that are never allowed. It is generated from `app.css`, which wins if the two ever disagree.
- **Route file ownership:** `routes/auth.php`, `admin.php`, `employee.php`, `accountant.php`, `shared.php`. The schedule lives in `routes/console.php`.
- **Services:** `AuditLogger`, `ActivityLogger`, `SettingsService`, `EmployeeAdministrationService`, `TwoFactorService`, `SessionService`, `ClientService`, `ProjectService`, `ProjectFinanceService`, and from Phase 2 `TaskService`, `TaskReviewers`, `TagService`, `FileService`, `ConversationService`, `NotificationService`.
- **Serializers:** `ProjectResource`, `ClientResource`, and from Phase 2 `TaskResource`, `TagResource`, `FileResource`, `MessageResource`, `NotificationResource` — the only way each of those leaves the server. A project's **status changes only** through `POST …/status`, `…/archive`, `…/unarchive`; `PUT /admin/projects/{id}` ignores a `status` key by design. `TaskResource` puts the checklist, links and dependencies behind `whenLoaded` and `available_transitions` behind a `task_detail` attribute, so a list payload is not a detail payload.
- **Policies:** `EmployeePolicy`, `ProjectPolicy` (view, create, update, archive, unarchive, cancel, manageMembers, viewCommercial, viewFinance, updateFinance), `ClientPolicy`, and from Phase 2 `TaskPolicy`, `TagPolicy`, `FilePolicy`, `ConversationPolicy`, `MessagePolicy`, `NotificationPolicy` (all extending `Policy`). `Project::visibleTo($user)` and `Task::visibleTo($user)` scope every list.
- **Events / listeners (Phase 2):** `TaskAssigned`, `TaskReassigned`, `TaskStatusChanged`, `TaskCommented`, `TaskSubmittedForReview`, `TaskCompleted`, `TaskDeleted`, `TaskBecameOverdue`, `ProjectCancelled` → the one `NotificationDispatcher` listener. **A move to In review fires `TaskSubmittedForReview` and a move to Completed fires `TaskCompleted` *instead of* `TaskStatusChanged`, never as well** — `TaskService::transition()` picks one.
- **Middleware aliases:** `surface:<admin|employee|accountant>` and `two-factor`.
- **Gates:** one per permission value (`can:settings.manage`).
- **Enums** (`app/Support`):
  - `Permission`: PascalCase cases, dotted values.
  - `RoleName`: cases `ADMIN` …
  - `TrackingMode`, `UserStatus`, `AuditEvent`, `Surface`.
- **Commands:** `hq:verify-backup` (`VerifyBackup.php`), `hq:two-factor-code <email>` (`TwoFactorCode.php` — prints the dev TOTP), and from Phase 2 `hq:flag-overdue` (`FlagOverdueTasks.php`, daily 08:00; it only *sends* — the overdue buckets stay query-time).

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
| Test | **`php vendor/bin/pest`** (all) · `php vendor/bin/pest --group=permissions`. **Not `php artisan test`** — it runs in parallel here and deadlocks on migration DDL; see *Known-failing baseline* below, which also says why two agents must not run the suite at once |
| Build | `npm run build` |
| Test (JS helpers) | `npm run test:js` → `node --experimental-strip-types --test "tests/js/**/*.test.ts"` (Node 22's own runner, no dependency; pure-TS helpers such as `lib/dueCountdown.ts`, cases shared with Pest via `tests/fixtures/`). A bare directory (`--test tests/js/`) is **not** accepted by Node 22 — pass the glob |
| Migrate (dev) | `php artisan migrate:fresh --seed --database=pgsql_migrator` |
| Dev server | `npm run build && php artisan serve --host=127.0.0.1 --port=8000` (run it in the background and kill it when done) |
| Deploy kit test | `deploy/test/run-install-test.sh` (fresh Ubuntu 24.04 container; needs Docker) |
| Verify backup | `php artisan hq:verify-backup [--disk=…]` |
| Measure widths | `node .claude/dispatch/dispatch-measure.mjs http://127.0.0.1:8000/<path> 375 768 1280` |
| Render a signed-in page | a brief that needs one is given the helper's path; it logs in as a seeded user (computing the TOTP where the role has 2FA) and reports overflow per width |

The local cloud workspace has PostgreSQL 16 on `127.0.0.1:5432`, superuser `postgres`, trust auth (dev only), and Redis on `127.0.0.1:6379`.

## Known-failing baseline
Measured 2026-09-26 after Phase 12's **UX polish pass** (live sync, the focus-ring sweep, the
§C.3 correctness follow-ups) with `php vendor/bin/pest`: none failing (**2776 passed, ~17799
assertions**). The suite outgrows a 10-minute tool timeout repeatedly: two parts from Phase 6,
three from Phase 8, four from Phase 10's search slice, six from Phase 10's reports slice, eight
from Phase 12's admin-tools and cutover slices, and **NINE as of the polish pass**.
`vendor/bin/pint --test`: passed. `npx vue-tsc --noEmit`: passed. `npm run build`: passed. If
your number is not 2776, that is a finding, not drift.

**How the previous baseline was checked rather than replaced.** The polish agent measured 602 on
part 6 against the 565 written here and reported the 37 as a possible finding rather than
absorbing it — which is what this paragraph asks for, and it was right to. The 37 resolved
exactly: `Surfaces/ShellPropTest.php` (11 `it()` blocks, **21** tests), `Realtime/TaskBroadcastTest.php`
(9 blocks, **11**) and `Surfaces/NavigationTest.php` (3) are all new files in part 6's folders,
and the two modified `Services` files gained one test each — 35 + 2 = 37. The old eight numbers
also still summed to their own stated 2697, so the block was internally consistent and simply
predated three files. **Count with Pest, not with `grep -c "^it("`**: a `->with([...])` dataset is
one block and many tests, and that is where the first attempt at this arithmetic went wrong.

**Two concurrent agents must not share a dev-server port.** `php artisan serve` defaults to
the same port for both; the loser silently reads the winner's database, and three measurement
runs went unnoticed that way. Pick a distinct port, and check what answered before trusting it.

**The suite no longer finishes inside a 10-minute tool timeout.** Run it in these **nine** parts
and add the numbers — each one measured under 10 minutes on 2026-09-26, and they cover `tests/`
exactly once between them.

The split has grown three times in Phase 12 and every time for the same reason — a slice put
enough tests into one folder that its line stopped fitting. `tests/Feature/Admin` took a line of its own
when the audit viewer and the settings screens added 62; `Console` and `Reports` were cut out of
line 2 when the ClickUp importer added 33 and the old line ran past ten minutes.
`tests/Feature/Performance` sits on line 1 with the other structural suites: it measures query
counts per surface through real HTTP and fails when one grows past its ceiling.
```
php vendor/bin/pest tests/Unit tests/Permissions tests/Feature/{Database,Performance}         # 408
php vendor/bin/pest tests/Feature/{Leave,Workforce,Attendance,Resources,Policies,Privacy}     # 439
php vendor/bin/pest tests/Feature/{Console,Reports}                                          # 165
php vendor/bin/pest tests/Feature/Admin                                                      # 338
php vendor/bin/pest tests/Feature/{Employee,Auth,Middleware,Profile,Backup} tests/Feature/ScheduleTest.php tests/Feature/ExampleTest.php   # 233
php vendor/bin/pest tests/Feature/{Services,Surfaces}                                        # 461
php vendor/bin/pest tests/Feature/{Search,Team,Realtime}                                     # 141
php vendor/bin/pest tests/Feature/{Shared,Finance,Payroll}                                   # 391
php vendor/bin/pest tests/Feature/{Meetings,Messages,Tasks}                                  # 200
```
The part list is not decorative: three earlier groupings in this file silently stopped covering
folders that were added after them (`Finance`, `Search`, `Reports`, `Tasks`, `Team`, `Realtime`,
`Meetings`, `Messages`, `Payroll` were all outside the two halves that used to be written here,
so "the suite passed" meant nine folders nobody ran). **When you add a `tests/Feature/` folder,
add it to one of these lines in the same commit.**

**A focus ring fades in.** Every shadcn control carries `transition-all`, so a computed style
read in the same tick as a `Tab` samples the transition's *start* and reports a painted ring as
missing. Wait ~260-300ms. And a Tailwind ring is a *list* of shadows whose first layer is a
transparent placeholder, so `box-shadow !== 'none'` is a false pass — parse per layer and require
a non-transparent colour with non-zero geometry. Both directions of this have produced wrong
measurements in this repo. Safest: press real `Tab` keys and diff focused/blurred screenshots.

**Faker's `catchPhrase()` contains the words this repo forbids.** `ProjectFactory` names a
project with it, and en_US catch-phrases are built from a word list that literally includes
*productivity*, *efficiency* and *synergy* — so any test that greps a rendered payload for
score-words and builds its data with `Project::factory()` fails **when the dice come up that
way**, roughly one run in a few dozen, on a clean tree, with nothing in the diff. It fired on
26 Sep 2026 and passed on the immediate re-run, which is the worst shape a failure can have. A
payload-scanning test pins its own names (`tests/Feature/Employee/TimeScreenTest.php` shows how);
it does not weaken the scan, because a score-word hiding inside a label is exactly what the scan
is for. The other four no-score tests read seeded data and are deterministic.

**`activity_logs` is polymorphic, so an id is only unique WITHIN a type.** A `->where('object_id',
$x)->sole()` with no `object_type` found two rows and failed on a clean tree, because an employee
id and a meeting id happened to be the same number. Scope on both, always.

**A test that pins an absolute date will collide with the seed eventually.** `TaskSeeder` dates
its work RELATIVELY (`'due' => 25`), so which calendar day a seeded task lands on depends on the
day you run the suite. `NotifyTasksDueTomorrowTest` pinned `2026-10-20` and counted reminders;
on 26 Sep 2026 — twenty-five days earlier — a seeded task landed on its "tomorrow" and six of
its tests failed having passed every previous morning, with nothing in the diff to explain it.
A test that counts things must **clear the window it measures** rather than hope the seed missed
it. 23 test files pin an absolute date; that one is the only known collision so far.

**A test file's constants and functions are GLOBAL in Pest.** Two files defining `ENDPOINT_MONDAY`
silently gave one of them the other's date: each file passed alone and the suite failed. Prefix
them with the folder (`LEAVE_`, `SHEET_`, `SWEEP_`). A duplicate is a PHP *warning*, not an error,
so nothing stops you.

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
## Flows
### F1 task-live-update
Trigger: any task mutation in TaskService / task controllers (assign, unassign, create, delete, archive, unarchive, status, priority, due date, title, tags, checklist) and a task comment/message
Steps: mutation commits → `App\Events\TaskChanged` (ShouldBroadcast, after commit, toOthers) → private user channel(s) `tasks.{user}` of everyone who can see the task (before or after the change) → Tasks screen listener (`useLiveTaskProps`) → coalesced silent partial reload
Payload: { task_id, kind } — ids and the kind of change only, never task text
Invariants: one broadcast per change; never before commit; never to a user who cannot see the task; never echoed to the acting tab; zero periodic requests while the socket is connected
Test: php vendor/bin/pest tests/Feature/Realtime/TaskLiveUpdateFlowTest.php
### F2 task-subtasks
Trigger: `POST /admin/tasks/{task}/subtasks` (`Admin/TaskController@storeSubtask`, `StoreSubtaskRequest`); then the ordinary `/{task}` routes on the subtask (status, archive, delete)
Steps: `TaskService::createSubtask()` → `create()` with `parent_id` + the parent's `project_id` → `TaskAssigned` → `NotificationDispatcher` → `announceChange()` rings the child AND the parent (`updated`, to the parent's own viewers; flow F1) → the card's `subtask_count`/`subtask_done_count` re-read
Invariants: depth ≤ 1 (`TaskPolicy::createSubtask` 403; no re-parenting); subtask project = parent project; archive/unarchive/delete of a parent carries its subtasks; a subtask's visibility is `TaskPolicy::view` on itself; `parent` is sent only if the reader may view the parent (absent otherwise); counter = not deleted, not archived, not cancelled, done = `completed`
Lists: general List/Board/Calendar/Gantt are `topLevel()` unless `subtasks=1` or a personal scope (`mine`); Reports/Workload include subtasks (decision 12-71)
Test: php vendor/bin/pest tests/Feature/Tasks/SubtaskFlowTest.php
### F3 task-timer
Trigger: `POST /tasks/{task}/timer` (`Shared/TaskTimerController@start`, `StartTaskTimerRequest`, `clock_in` optional), `POST /task-timer/{pause,resume,stop}`, `POST /task-timer/heartbeat` (JSON); plus clock-out and `hq:timer-watchdog`
Steps: `TimeEntryPolicy::trackTasks` (403) → `Task::visibleTo` (404) → `trackTask` = assigned, or Admin/Manager on any task they can view (403) → office/Admin not clocked in: 422 `clock_in`, or `AttendanceService::clockIn` → `TaskTimerService::start` → `TimerService::switchTo` (stops any other open entry) → `time_entries` row (`counts_toward_hours` false for office/Admin) → `TaskChanged` kind `timer` after commit (flow F1; start/pause/resume → watchers + owner, stop → every viewer) → board patches (`my_timer`, `running_timers`, `tracked_seconds`)
Panel: the Admin Time page / dashboard 'Working now' panel (`TaskTimerService::workingNow`, `working_now` / `workingNow`, one query, absent without `watchLive`) re-reads on `task.changed` kind `timer`; so do the Admin Projects pages — list marker `ProjectWorkingNow` from `workingNowByProject` (`workingNowByProject()`, same one query grouped by project id) and the project page's `WorkingNowPanel` from `workingNow` (`workingNow($viewer, $projectId)`); test `tests/Feature/Admin/ProjectWorkingNowTest.php`
Invariants: ≤ 1 open entry per employee (index); office/Admin entries never count (`scopeCounted`/`counts()`, timesheet, reports, `daily_work_summary`) but do reach `tasks.tracked_seconds` (`scopeTracked`); no office/Admin entry without an open clock-in; clock-out stops it; `running_timers` only for `watchLive` (absent otherwise); remote `/employee/time/*` unchanged; decision 12-73
Test: php vendor/bin/pest tests/Feature/Tasks/TaskTimerFlowTest.php
<!-- /dispatch:map -->
