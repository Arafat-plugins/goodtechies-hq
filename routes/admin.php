<?php

use App\Http\Controllers\Admin\AttendanceController;
use App\Http\Controllers\Admin\ClientController;
use App\Http\Controllers\Admin\ClientFileController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\FileController;
use App\Http\Controllers\Admin\MyTaskController;
use App\Http\Controllers\Admin\ProjectController;
use App\Http\Controllers\Admin\ProjectFileController;
use App\Http\Controllers\Admin\ProjectFinanceController;
use App\Http\Controllers\Admin\ProjectMemberController;
use App\Http\Controllers\Admin\ProjectStatusController;
use App\Http\Controllers\Admin\RecurringTaskController;
use App\Http\Controllers\Admin\ScheduleController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\TagController;
use App\Http\Controllers\Admin\TaskController;
use App\Http\Controllers\Admin\TaskDiscussionController;
use App\Http\Controllers\Admin\TaskFileController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin')
    ->name('admin.')
    ->middleware(['auth', 'active', 'two-factor', 'surface:admin'])
    ->group(function () {
        Route::get('/dashboard', DashboardController::class)->name('dashboard');
        Route::get('/settings', SettingsController::class)
            ->middleware('can:settings.manage')
            ->name('settings');

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

            // The project detail page's Files tab (spec §7).
            Route::get('/{project}/files', [ProjectFileController::class, 'index'])->name('files.index');
            Route::post('/{project}/files', [ProjectFileController::class, 'store'])->name('files.store');

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
            Route::post('/{task}/discussion', [TaskDiscussionController::class, 'store'])->name('discussion.store');
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

        // Admin → Workforce → Work Schedule. The working week is per employee and editable,
        // which is what stops it being a constant anywhere else: every rule that reads it —
        // Off Day, Late, and which days `hq:mark-absent` marks — is stated once in
        // AttendanceService and follows whatever is saved here. Audit-logged, because moving a
        // start time rewrites what a fortnight of past arrivals will be called.
        Route::prefix('schedules')->name('schedules.')->group(function () {
            Route::get('/', [ScheduleController::class, 'index'])->name('index');
            Route::put('/{employee}', [ScheduleController::class, 'update'])->name('update');
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
