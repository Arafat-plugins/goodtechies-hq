<?php

use App\Http\Controllers\Admin\ActivityController;
use App\Http\Controllers\Admin\AttendanceController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\ClientController;
use App\Http\Controllers\Admin\ClientFileController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\EmployeeController;
use App\Http\Controllers\Admin\EmployeeExtensionDeviceController;
use App\Http\Controllers\Admin\FileController;
use App\Http\Controllers\Admin\HolidayController;
use App\Http\Controllers\Admin\LeaveBalanceController;
use App\Http\Controllers\Admin\LeaveController;
use App\Http\Controllers\Admin\MyTaskController;
use App\Http\Controllers\Admin\NotificationDefaultsController;
use App\Http\Controllers\Admin\ProjectController;
use App\Http\Controllers\Admin\ProjectFileController;
use App\Http\Controllers\Admin\ProjectFinanceController;
use App\Http\Controllers\Admin\ProjectMemberController;
use App\Http\Controllers\Admin\ProjectPermissionController;
use App\Http\Controllers\Admin\ProjectStatusController;
use App\Http\Controllers\Admin\RecurringTaskController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\ScheduleController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\TagController;
use App\Http\Controllers\Admin\TaskController;
use App\Http\Controllers\Admin\TaskDiscussionController;
use App\Http\Controllers\Admin\TaskFileController;
use App\Http\Controllers\Admin\TimeController;
use App\Http\Controllers\Admin\TimesheetController;
use App\Http\Controllers\Admin\WorkloadController;
use App\Support\Permission;
use Illuminate\Support\Facades\Route;

Route::prefix('admin')
    ->name('admin.')
    // `throttle:authenticated` is Phase 12's security pass: a ceiling on how fast a
    // signed-in account can ask for anything at all. See AppServiceProvider's
    // defineAuthenticatedRateLimiters() for the number and what it was measured against.
    ->middleware(['auth', 'active', 'two-factor', 'surface:admin', 'throttle:authenticated'])
    ->group(function () {
        Route::get('/dashboard', DashboardController::class)->name('dashboard');
        // Admin → Settings (Part D §20's closed key list, Part E's Phase 12 grouping).
        //
        // GET renders and PUT saves, and the PUT takes whichever of the editable keys the
        // section that was submitted sent — one endpoint for all of them, because they are one
        // table with one writer. `SettingsService::set()` is that writer: it refuses the
        // read-only key, asks `settings.manage` of the actor again, and audits each change in
        // the same transaction as the write. Neither line here restates any of that.
        //
        // A body-less PUT stops at the Form Request ("send at least one setting"), which is
        // proof it got past both gates.
        Route::get('/settings', [SettingsController::class, 'index'])
            ->middleware('can:settings.manage')
            ->name('settings');
        Route::put('/settings', [SettingsController::class, 'update'])
            ->middleware('can:settings.manage')
            ->name('settings.update');

        // Admin → Notifications: the agency-wide notification defaults
        // (`notification_preferences`, Part D §20 — *"global defaults set by Admin, Phase 12;
        // the engine reads them"*).
        //
        // Gated on `can:settings.manage` and not on a key of its own: Part C §1's permission
        // list is closed and *Manage system settings* is the cell this screen belongs to — it is
        // a configuration that decides who the agency tells about what, in the same sense the
        // holiday calendar decides what a day is called. A new key would need a recorded
        // decision and would have nothing to say that this one does not.
        //
        // It is `/admin/notifications` and not `/notifications`: the latter is the shared
        // Notification Center in `routes/shared.php`, which is one person's own mail. These are
        // the defaults for everybody's, so they live on the Admin surface.
        Route::get('/notifications', [NotificationDefaultsController::class, 'index'])
            ->middleware('can:settings.manage')
            ->name('notifications');
        Route::put('/notifications', [NotificationDefaultsController::class, 'update'])
            ->middleware('can:settings.manage')
            ->name('notifications.update');

        // Admin → Audit Log (Part E, Phase 12: *"Admin → Audit Log viewer (filters, old/new
        // diff, read-only)"*). Phase 12.
        //
        // **This is the whole surface of the feature: one line, one verb, one action.** There is
        // no POST, no PUT, no DELETE and no soft one, because there is nothing an Admin may do to
        // an audit row. `audit_logs` is append-only *at the database* — its migration runs
        // `REVOKE UPDATE, DELETE, TRUNCATE ON audit_logs FROM hq_app` right after `CREATE TABLE`
        // and `hq_app` is not the owner, so it cannot grant them back (Part B §3 rule 3);
        // `tests/Feature/Database/AuditLogAppendOnlyTest.php` proves all three raise
        // `42501 insufficient_privilege`. A write route here would be a route whose only possible
        // outcome is a 500. Acceptance criterion 11 is kept by the absence, not by care.
        //
        // `can:audit.view` is Part C §1's *View audit log* row, which is ADMIN and nobody else.
        // The ACCOUNTANT is worth naming: they hold `payroll.view_others` and may read every
        // payslip in the agency, and they are **403** here — who changed what is a different
        // question from what it says now. `surface:admin` refuses them first; the permission is
        // what would still refuse them if a second shell ever got this screen.
        //
        // No `{parameter}`, so there is no record-level refusal to get right. One entry is read
        // in the drawer from the row the list already sent, and a pasted `?detail=` is resolved
        // by the controller with no scope — every row is in scope for anybody holding the key,
        // which `AuditLogPolicy` argues at length. A 404 here would be describing a scope that
        // does not exist.
        Route::get('/audit-log', [AuditLogController::class, 'index'])
            ->middleware('can:'.Permission::AuditView->value)
            ->name('audit-log');

        // An Admin's own plate. One route, seven buckets on `?bucket=` — Due today and Overdue
        // are questions about the same plate, not separate screens, so they are deep links into
        // this one rather than two more routes with two more queries to keep in step.
        //
        // It sits OUTSIDE the `tasks` prefix deliberately: `/admin/tasks/my` would bind `my` as
        // a task id at the first careless reorder, and the nav's `activePrefix` for the Tasks
        // row would light on a page that is not a Tasks view.
        Route::get('/my-tasks', [MyTaskController::class, 'index'])->name('my-tasks');

        Route::prefix('clients')->name('clients.')->group(function () {
            Route::get('/', [ClientController::class, 'index'])->name('index');
            Route::get('/create', [ClientController::class, 'create'])->name('create');
            Route::post('/', [ClientController::class, 'store'])->name('store');
            Route::get('/{client}', [ClientController::class, 'show'])->name('show');
            Route::get('/{client}/edit', [ClientController::class, 'edit'])->name('edit');
            Route::put('/{client}', [ClientController::class, 'update'])->name('update');
            // Clients are never deleted; a finished one is deactivated.
            Route::post('/{client}/deactivate', [ClientController::class, 'deactivate'])->name('deactivate');

            // The client detail page's Files tab (spec §28). The same FileService as the task
            // panel and the project tab; listing takes clients.view_full, attaching clients.edit.
            Route::get('/{client}/files', [ClientFileController::class, 'index'])->name('files.index');
            Route::post('/{client}/files', [ClientFileController::class, 'store'])->name('files.store');
        });

        Route::prefix('projects')->name('projects.')->group(function () {
            Route::get('/', [ProjectController::class, 'index'])->name('index');
            Route::get('/create', [ProjectController::class, 'create'])->name('create');
            Route::post('/', [ProjectController::class, 'store'])->name('store');
            Route::get('/{project}', [ProjectController::class, 'show'])->name('show');
            Route::get('/{project}/edit', [ProjectController::class, 'edit'])->name('edit');
            Route::put('/{project}', [ProjectController::class, 'update'])->name('update');
            // Money and membership are separate abilities, so they are separate endpoints.
            Route::put('/{project}/finance', [ProjectFinanceController::class, 'update'])->name('finance.update');
            Route::put('/{project}/members', [ProjectMemberController::class, 'update'])->name('members.update');
            Route::post('/{project}/status', [ProjectStatusController::class, 'update'])->name('status');
            Route::post('/{project}/archive', [ProjectStatusController::class, 'archive'])->name('archive');
            Route::post('/{project}/unarchive', [ProjectStatusController::class, 'unarchive'])->name('unarchive');
            // Only an archived project, only an Admin, only with its name typed back.
            Route::delete('/{project}', [ProjectStatusController::class, 'destroy'])->name('destroy');

            // The project detail page's Files tab (spec §7).
            Route::get('/{project}/files', [ProjectFileController::class, 'index'])->name('files.index');
            Route::post('/{project}/files', [ProjectFileController::class, 'store'])->name('files.store');
            // Attachments on the internal notes: viewCommercial only, never in the Files tab.
            Route::get('/{project}/internal-files', [ProjectFileController::class, 'internalIndex'])->name('internal-files.index');
            Route::post('/{project}/internal-files', [ProjectFileController::class, 'internalStore'])->name('internal-files.store');

            // The project detail page's Recurring tab (Phase 3). The tab is a panel inside
            // Pages/Admin/Projects/Show.vue, so the list is JSON it fetches rather than a page
            // of its own; the write is back() with a flash, like every other panel here.
            //
            // `preview` is a GET: it stores nothing and changes nothing — it hands an UNSAVED
            // rule to RecurrenceRule and answers with the date it next fires and the period
            // that run belongs to. A read stays a read, which also keeps the editor's live
            // preview a plain fetch with no token to carry.
            Route::get('/{project}/recurring', [RecurringTaskController::class, 'index'])->name('recurring.index');
            Route::post('/{project}/recurring', [RecurringTaskController::class, 'store'])->name('recurring.store');
            Route::get('/{project}/recurring/preview', [RecurringTaskController::class, 'preview'])->name('recurring.preview');
        });

        // One template, whatever project it belongs to — the same shape as `admin/files/{file}`
        // above, and for the same reason: editing a template, running it and reading its log are
        // acts on the template, which already knows which project it is on. Nesting them would
        // put a project id in the URL that nothing reads and that a caller could get wrong.
        //
        // A template on a project the requester cannot see is 404 here, not 403: the controller
        // re-resolves it through RecurringTask::visibleTo() before the policy is asked.
        Route::prefix('recurring-tasks')->name('recurring.')->group(function () {
            Route::put('/{recurringTask}', [RecurringTaskController::class, 'update'])->name('update');
            // "Generate now" — RecurringTaskEngine::generate(force: true). Every rule but due()
            // still applies, which is why pressing it twice produces a duplicate WARNING and
            // never a second task.
            Route::post('/{recurringTask}/generate', [RecurringTaskController::class, 'generate'])->name('generate');
            // The generation log, JSON, fetched when the panel is opened.
            Route::get('/{recurringTask}/log', [RecurringTaskController::class, 'log'])->name('log');
        });

        // Tasks. A status moves through ONE endpoint — `…/status` — and `PUT /{task}` does not
        // accept the field at all: the board drag, the calendar drag and the detail form are
        // three callers of that one route, so they cannot be given different rules. This is the
        // shape Phase 1's project fix established, applied before the hole can open.
        Route::prefix('tasks')->name('tasks.')->group(function () {
            Route::get('/', [TaskController::class, 'index'])->name('index');

            // The Board and the Calendar are routes of their own, not `?view=` on the index:
            // each is deep-linkable and bookmarkable, each gets its own row in the permission
            // matrix instead of hiding a surface behind another route's row, and the Inertia
            // page name follows the route. The filters travel as query parameters, so the chip
            // bar survives a switch between views.
            //
            // Declared BEFORE `/{task}`, or `board` binds as a task id and the page 404s.
            Route::get('/board', [TaskController::class, 'board'])->name('board');
            Route::get('/calendar', [TaskController::class, 'calendar'])->name('calendar');
            // Phase 10. The fourth view, registered exactly as the Calendar is and declared
            // before `/{task}` for the same reason. It writes dates through `PUT /{task}`
            // below — there is no Gantt endpoint, because a second way to move a date would
            // be a second set of date rules.
            Route::get('/gantt', [TaskController::class, 'gantt'])->name('gantt');

            Route::post('/', [TaskController::class, 'store'])->name('store');
            Route::get('/{task}', [TaskController::class, 'show'])->name('show');
            Route::put('/{task}', [TaskController::class, 'update'])->name('update');
            Route::delete('/{task}', [TaskController::class, 'destroy'])->name('destroy');

            Route::post('/{task}/status', [TaskController::class, 'status'])->name('status');
            Route::post('/{task}/reorder', [TaskController::class, 'reorder'])->name('reorder');
            Route::post('/{task}/archive', [TaskController::class, 'archive'])->name('archive');
            Route::post('/{task}/unarchive', [TaskController::class, 'unarchive'])->name('unarchive');

            // Who the task belongs to is its own ability and its own audit event.
            Route::put('/{task}/assignees', [TaskController::class, 'assignees'])->name('assignees');
            Route::post('/{task}/handoff', [TaskController::class, 'handOff'])->name('handoff');

            Route::post('/{task}/checklist', [TaskController::class, 'storeChecklistItem'])->name('checklist.store');
            Route::put('/{task}/checklist/{item}', [TaskController::class, 'updateChecklistItem'])->name('checklist.update');
            Route::delete('/{task}/checklist/{item}', [TaskController::class, 'destroyChecklistItem'])->name('checklist.destroy');

            // Flow F2 (decision 12-71): a subtask is a task, so there is nothing else to route —
            // it is shown, moved, archived and deleted through the `/{task}` routes above.
            Route::post('/{task}/subtasks', [TaskController::class, 'storeSubtask'])->name('subtasks.store');

            Route::post('/{task}/links', [TaskController::class, 'storeLink'])->name('links.store');
            Route::delete('/{task}/links/{link}', [TaskController::class, 'destroyLink'])->name('links.destroy');

            Route::post('/{task}/dependencies', [TaskController::class, 'storeDependency'])->name('dependencies.store');
            Route::delete('/{task}/dependencies/{dependency}', [TaskController::class, 'destroyDependency'])->name('dependencies.destroy');

            // Attachments. The list and the upload hang off the task; replacing and deleting
            // hang off the FILE, below, because those two are the same act whichever kind of
            // record owns it.
            Route::get('/{task}/files', [TaskFileController::class, 'index'])->name('files.index');
            Route::post('/{task}/files', [TaskFileController::class, 'store'])->name('files.store');

            // The discussion — the plan's "comments", stored as the messages of the task's own
            // `task` conversation (one store, no `task_comments` table). There is no route for
            // editing or deleting a message, and that is the feature: a message is what
            // somebody said at a time.
            Route::get('/{task}/discussion', [TaskDiscussionController::class, 'index'])->name('discussion.index');
            Route::post('/{task}/discussion', [TaskDiscussionController::class, 'store'])
                ->middleware('throttle:posting')
                ->name('discussion.store');
        });

        // Tag management (spec: "Admin/Manager create, global or per project"). ASSIGNING a
        // tag is not here and is not an endpoint at all — it is `tag_ids` on the task update,
        // which is why an employee can label a task without being able to invent a label.
        //
        // The same four routes exist on the Employee surface, because that is where a Manager
        // lives; TagPolicy is what keeps an employee out of them, not their absence.
        Route::prefix('tags')->name('tags.')->group(function () {
            Route::get('/', [TagController::class, 'index'])->name('index');
            Route::post('/', [TagController::class, 'store'])->name('store');
            Route::put('/{tag}', [TagController::class, 'update'])->name('update');
            Route::delete('/{tag}', [TagController::class, 'destroy'])->name('destroy');
        });

        // Admin → Workforce → Attendance (master prompt Part D §8, Phase 4). Two routes, and
        // the month grid of one employee is deliberately not among them: it is the shared
        // `GET /attendance/{employee}`, because an Admin reading somebody's month and that
        // person reading their own are the same screen and the same query, differing only in
        // whether the edit control is drawn. The roster links each row to it.
        //
        // The correction is an UPSERT keyed by (employee, date) — the row's own identity,
        // which is `unique(employee_id, date)`. Marking a Half Day on a day nobody clocked
        // into and correcting one the 23:55 sweep wrote are the same act to the Admin making
        // it, and two endpoints would have been two places for the audit row to be forgotten.
        // The reason is required by the Form Request; the audit row carries old and new.
        Route::prefix('attendance')->name('attendance.')->group(function () {
            Route::get('/', [AttendanceController::class, 'index'])->name('index');
            Route::put('/{employee}/{date}', [AttendanceController::class, 'update'])
                ->where('date', '\d{4}-\d{2}-\d{2}')
                ->name('update');
        });

        // Admin → Workforce → Leave → Holidays (Part D §9, Phase 5): the company calendar the
        // client types their own holidays into. `HolidaySeeder` supplies the Bangladesh list
        // for the current year as a starting point and most of those dates are lunar estimates,
        // so this screen is not an optional extra — it is where the seed is made true.
        //
        // It sits at `/admin/holidays` rather than under a `leave` prefix although the menu
        // path is Workforce → Leave → Holidays. A holiday is not a leave request: it has no
        // employee on it, no approval, no balance, and it is gated on a different key. The
        // breadcrumb carries the menu path; the URL carries what the record is.
        //
        // `can:settings.manage` is the gate and the whole of it. The calendar is an agency-wide
        // configuration that decides what a day is called for everybody, so the area keys —
        // `leave.approve`, `attendance.manage_others` — are wrong twice: both are *🟡 own team*
        // for a MANAGER, and a company-wide calendar has no team. See HolidayPolicy.
        //
        // Every refusal here is **403** and none is 404: no holiday is visible to one signed-in
        // reader and absent for another, so Part C's absence rule has nothing to be about. An
        // id that is not in the table is route-model binding's 404 and is asserted directly.
        //
        // `index` is a GET with no Form Request, so it renders; `store` and `update` have one,
        // so a body-less call stops at validation, which is proof it passed every gate.
        Route::prefix('holidays')->name('holidays.')->group(function () {
            Route::get('/', [HolidayController::class, 'index'])->name('index');
            Route::post('/', [HolidayController::class, 'store'])->name('store');
            Route::put('/{holiday}', [HolidayController::class, 'update'])->name('update');
            Route::delete('/{holiday}', [HolidayController::class, 'destroy'])->name('destroy');
        })->middleware('can:settings.manage');

        // Admin → Workforce → Leave (master prompt Part D §9, Phase 5): the requests queue, the
        // leave calendar, and the balances grid.
        //
        // The three verbs are three routes and not one endpoint taking a `decision` field:
        // approving, refusing and sending a request back are three acts with three different
        // rules about the note, and which one was called is then a fact about the URL rather
        // than a value a client chooses (see `DecideLeaveRequest`). All three go through
        // `LeaveService`, which is the only thing that moves a request's status — the model
        // throws if anything else writes one.
        //
        // `calendar` and `balances` are declared BEFORE any `{leaveRequest}` route could bind
        // them. There is no `GET /admin/leave/{leaveRequest}` — there is no page for one
        // request — so nothing can collide, and the order is kept anyway so that adding one
        // later cannot quietly turn `calendar` into an id.
        //
        // Applying for leave is NOT here: it is `GET|POST /leave` in routes/shared.php, because
        // both Admins apply for their own leave too and Part C §1 gives that cell to every role.
        // The same split the clock keeps (decision 4-15).
        //
        // Gated by `LeaveRequestPolicy` rather than by middleware, because `::review`,
        // `::decide` and `::manageBalances` are three different questions — the page, the
        // verdict, and somebody else's days — and `surface:admin` has already refused every
        // other shell before any of them is asked. A request outside the requester's scope is
        // re-resolved through `LeaveRequest::visibleTo()` in the controller first, so it is
        // **404** rather than a refusal that would confirm the record exists.
        Route::prefix('leave')->name('leave.')->group(function () {
            Route::get('/', [LeaveController::class, 'index'])->name('index');
            Route::get('/calendar', [LeaveController::class, 'calendar'])->name('calendar');

            Route::get('/balances', [LeaveBalanceController::class, 'index'])->name('balances.index');
            // The upsert, keyed by (employee, type) — the row's own identity, which is
            // `unique(employee_id, leave_type_id)`. Setting a first balance and correcting one
            // are the same act to the Admin making it, and two endpoints would have been two
            // places for the audit row to be forgotten.
            Route::put('/balances/{employee}/{leaveType}', [LeaveBalanceController::class, 'update'])
                ->whereNumber('employee')
                ->whereNumber('leaveType')
                ->name('balances.update');

            Route::post('/{leaveRequest}/approve', [LeaveController::class, 'approve'])->name('approve');
            Route::post('/{leaveRequest}/reject', [LeaveController::class, 'reject'])->name('reject');
            Route::post('/{leaveRequest}/correction', [LeaveController::class, 'correction'])->name('correction');
        });

        // Admin → Workforce → Employees, which is also Admin → Users & Roles (Part D §2: "Users
        // & Roles is the same screen family (Employees list → employee detail →
        // role/schedule/tracking_mode)"). Phase 12.
        //
        // **There is no DELETE here, for an employee or for a user, and there never will be.**
        // Part B §3 rule 11: a departure is `status = inactive`. `deactivate` and `reactivate` are
        // the two status moves, they are POSTs because each is one act on one person, and neither
        // carries a body — the confirmation dialog is the whole of the input.
        //
        // The schedule is NOT written here: `PUT /admin/schedules/{employee}` above is the
        // editor, and `ScheduleService` is the only writer of the table. The create form collects
        // a working week because Part D §21's onboarding row says a hire has one, and it goes
        // through that same service.
        //
        // `can:roles.manage` on the group is Part C §1's *Manage roles/permissions* row, and it
        // sits behind `surface:admin` so that widening the surface one day would not quietly hand
        // somebody the power to create accounts. `EmployeePolicy` is asked again behind both, per
        // ability, because "read the list", "hire", "switch a login off" and "grant a project
        // permission" are four different questions — and the last three add the self-guard that
        // the middleware cannot express.
        //
        // Every `{employee}` is re-resolved through `EmployeeAdministrationService::findFor()`
        // before its policy is asked, so an employee outside the requester's scope is **404** and
        // never a refusal that would confirm the record exists (Part C) — the same ordering every
        // parameterised admin route here keeps.
        //
        // The grants are `…/{employee}/permissions`: a grant is written about a PERSON, and the
        // row it writes already knows which project it is on. Revoking names the grant's own id,
        // so two Admins clearing the same list cannot take each other's row.
        Route::prefix('employees')->name('employees.')->group(function () {
            Route::get('/', [EmployeeController::class, 'index'])->name('index');
            Route::post('/', [EmployeeController::class, 'store'])->name('store');
            Route::get('/{employee}', [EmployeeController::class, 'show'])
                ->whereNumber('employee')
                ->name('show');

            // Part D §2's *"role/schedule/tracking_mode"*, two thirds of it. PUT and not POST
            // because each replaces one field with the value it is given and says nothing about
            // what it was before — sending the same role twice is the same record both times,
            // and the service returns early rather than writing a second audit row. The schedule
            // is the third and is `PUT /admin/schedules/{employee}` above, because
            // `ScheduleService` is the only writer of that table.
            //
            // Two endpoints rather than one `update`, for the reason `POST …/projects/{id}/status`
            // is not a key in `PUT …/projects/{id}`: a role change is one of the events Part C §4
            // requires in `audit_logs` and the one act Part C §1 forbids on your own account, and
            // a tracking-mode change decides which table somebody's working day is recorded in
            // from that moment on. Neither should be reachable as a field somebody tabbed past.
            //
            // MANAGER is refused by `UpdateEmployeeRoleRequest` against the same
            // `ASSIGNABLE_ROLES` list `StoreEmployeeRequest` uses (Part C §1 assigns it to
            // nobody), so it cannot be reached by promotion either.
            Route::put('/{employee}/role', [EmployeeController::class, 'changeRole'])
                ->whereNumber('employee')
                ->name('role');
            Route::put('/{employee}/tracking-mode', [EmployeeController::class, 'changeTrackingMode'])
                ->whereNumber('employee')
                ->name('tracking-mode');

            Route::post('/{employee}/deactivate', [EmployeeController::class, 'deactivate'])
                ->whereNumber('employee')
                ->name('deactivate');
            Route::post('/{employee}/reactivate', [EmployeeController::class, 'reactivate'])
                ->whereNumber('employee')
                ->name('reactivate');

            // Re-issuing a sign-in password. It exists because without it an account could
            // become permanently unusable by nobody's mistake: no email in the MVP (Part H §1),
            // no reset route in `routes/auth.php`, and Profile → Password needs the CURRENT
            // password — so a forgotten one left a person whose record is kept forever
            // (Part B §3 rule 11) and whose login nobody in the agency could repair.
            //
            // POST and not PUT: it is an act with a consequence beyond the field it writes —
            // it ends that person's sessions — and repeating it does not produce the same
            // record twice, it produces a different password.
            Route::post('/{employee}/reset-password', [EmployeeController::class, 'resetPassword'])
                ->whereNumber('employee')
                ->name('reset-password');

            Route::post('/{employee}/permissions', [ProjectPermissionController::class, 'store'])
                ->whereNumber('employee')
                ->name('permissions.store');
            Route::delete('/{employee}/permissions/{grant}', [ProjectPermissionController::class, 'destroy'])
                ->whereNumber('employee')
                ->whereNumber('grant')
                ->name('permissions.destroy');

            // Phase 11: disconnect one of this person's timer extensions.
            Route::delete('/{employee}/extension-devices/{device}', [EmployeeExtensionDeviceController::class, 'destroy'])
                ->whereNumber('employee')
                ->whereNumber('device')
                ->name('extension-devices.destroy');
        })->middleware('can:'.Permission::RolesManage->value);

        // Admin → Workforce → Work Schedule. The working week is per employee and editable,
        // which is what stops it being a constant anywhere else: every rule that reads it —
        // Off Day, Late, and which days `hq:mark-absent` marks — is stated once in
        // AttendanceService and follows whatever is saved here. Audit-logged, because moving a
        // start time rewrites what a fortnight of past arrivals will be called.
        Route::prefix('schedules')->name('schedules.')->group(function () {
            Route::get('/', [ScheduleController::class, 'index'])->name('index');
            Route::put('/{employee}', [ScheduleController::class, 'update'])->name('update');
        });

        // Admin → Workforce → Time (Part D §7): hours today and this week by employee, project
        // and task, and the approval queue that decision 4-16 says would otherwise bite at
        // GATE C — `manual_time_requires_approval` is seeded ON, so until this existed a manual
        // entry was written unapproved and counted toward nothing, with nowhere to sign it off.
        //
        // The two decisions hang off the ENTRY, not off an employee or a day: approving is an
        // act on one row, and the row already knows whose it is. `{timeEntry}` is re-resolved
        // through `TimeEntry::visibleTo()` in the controller before `TimeEntryPolicy::approve`
        // is asked, so an entry outside the requester's scope is ABSENT (404) rather than
        // refused — the same ordering every parameterised admin route here keeps.
        //
        // Approve carries no body; Reject requires a reason, because a refusal takes hours off
        // somebody's record and they will read the sentence. Both write `audit_logs` with old
        // and new values (`TimerService`), and neither deletes anything.
        //
        // Gated by `TimeEntryPolicy` rather than by middleware, because `::review` and
        // `::approve` are two different questions — the page and the verb — and `surface:admin`
        // has already refused every other shell before either is asked.
        Route::prefix('time')->name('time.')->group(function () {
            Route::get('/', [TimeController::class, 'index'])->name('index');
            Route::get('/activity/{employee?}', [ActivityController::class, 'show'])->whereNumber('employee')->name('activity');
            Route::post('/entries/{timeEntry}/approve', [TimeController::class, 'approve'])->name('approve');
            Route::post('/entries/{timeEntry}/reject', [TimeController::class, 'reject'])->name('reject');
        });

        // Admin → Workforce → Timesheet (Part D §7): one employee's week, tasks × days.
        //
        // `{employee?}` rather than two routes, and the SAME optional-parameter shape the
        // shared attendance page uses — because it is the same question in the same words:
        // no parameter means "open on somebody sensible", a parameter is re-resolved through
        // `Employee::attendanceVisibleTo()`, and an id outside that scope is ABSENT (404) and
        // never a refusal that would confirm the record exists.
        //
        // `can:attendance.manage_others` is the gate, not a role: reading somebody else's
        // hours is the same privilege as reading their attendance, which is what
        // `TimeEntry::visibleTo()` already says in SQL. Everybody else is stopped by
        // `surface:admin` before it is asked.
        //
        // It is a READ, and the only one: adding time by hand belongs to the person whose week
        // it is and lives on the Employee surface. There is no write here to forget to audit.
        Route::get('/timesheet/{employee?}', [TimesheetController::class, 'index'])
            ->whereNumber('employee')
            ->middleware('can:attendance.manage_others')
            ->name('timesheet');

        // Admin → Workforce → Workload (Part D §7): counts only — task count per employee,
        // overdue per employee, estimated vs tracked, projects with most pending work.
        //
        // No parameter, because there is no record to name: it is the agency's own numbers,
        // scoped by `Task::visibleTo()` and `Employee::attendanceVisibleTo()`. Somebody the
        // viewer may not see is missing from the list rather than refused, which is the
        // absence rule expressed as a scope and needs no 404.
        Route::get('/workload', [WorkloadController::class, 'index'])->name('workload');

        // Admin → Reports (Part D §15, Phase 10; the shape is frozen in docs/report-contract.md).
        //
        // Two routes for all sixteen reports, because a report here is DATA in one shape and
        // not a screen: the index is the catalogue and `{report}` is one `ReportResult`
        // rendered by one page component. Adding a report in the next slice adds a `ReportKey`
        // case and a builder, and touches neither of these lines.
        //
        // **There is no `can:` on either of them, and that is deliberate.** A report requires
        // the permission of the DATA IT READS — `tasks.view`, `attendance.manage_others`,
        // `finance.view`, `payroll.view_others` — so which key applies depends on which report
        // was asked for. A single middleware key could only be the wrong one for fifteen of
        // them; `ReportKey::permission()` is asked in the controller instead, and the index
        // lists exactly the cards whose key the viewer holds. No role is named anywhere in it.
        //
        // `surface:admin` on the group above is still what refuses the Accountant here, before
        // any of that is asked — their finance reporting is Phase 8's `/finance/report`.
        //
        // `{report}` binds to the `ReportKey` enum, so a value that is not a case — including
        // one of the eight the next slice adds — is a **404** from the router rather than a 500
        // from a missing builder. A `?employee=`, `?project=` or `?client=` the viewer may not
        // see is neither: the scope returns nothing for it, because a 404 there would confirm
        // that the row exists (Part C §1).
        Route::prefix('reports')->name('reports.')->group(function () {
            Route::get('/', [ReportController::class, 'index'])->name('index');
            // `throttle:reports`: one builder scans a whole table per section — 37 queries
            // measured on `completion`. 60 a minute is faster than the screen can be read.
            Route::get('/{report}', [ReportController::class, 'show'])
                ->middleware('throttle:reports')
                ->name('show');
        });

        // One file, whatever owns it. Downloading is not here: it is `GET /files/{file}` in
        // routes/shared.php, signed, because the answer does not depend on the surface.
        Route::prefix('files')->name('files.')->group(function () {
            // The chain, oldest first and including the current version — JSON, like the two
            // Files tabs' index, because it is what the panel's history disclosure fetches when
            // somebody opens it. A file this requester may not see answers 404 here exactly as
            // it does everywhere else, versions and their count included.
            Route::get('/{file}/versions', [FileController::class, 'versions'])->name('versions.index');
            // A new version. Never an overwrite — the row being replaced keeps its bytes.
            Route::post('/{file}/versions', [FileController::class, 'storeVersion'])->name('versions.store');
            Route::delete('/{file}', [FileController::class, 'destroy'])->name('destroy');
        });
    });
