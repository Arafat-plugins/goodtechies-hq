<?php

use App\Http\Controllers\Employee\DashboardController;
use App\Http\Controllers\Employee\FileController;
use App\Http\Controllers\Employee\MyTaskController;
use App\Http\Controllers\Employee\ProjectController;
use App\Http\Controllers\Employee\TagController;
use App\Http\Controllers\Employee\TaskController;
use App\Http\Controllers\Employee\TaskDiscussionController;
use App\Http\Controllers\Employee\TaskFileController;
use Illuminate\Support\Facades\Route;

Route::prefix('employee')
    ->name('employee.')
    ->middleware(['auth', 'active', 'two-factor', 'surface:employee'])
    ->group(function () {
        Route::get('/dashboard', DashboardController::class)->name('dashboard');

        // This person's own plate, in seven buckets on `?bucket=`. Outside the `tasks` prefix
        // so that `my-tasks` can never bind as a task id and so the Tasks row's `activePrefix`
        // does not claim it; see the same route on the Admin surface.
        Route::get('/my-tasks', [MyTaskController::class, 'index'])->name('my-tasks');

        Route::prefix('projects')->name('projects.')->group(function () {
            Route::get('/', [ProjectController::class, 'index'])->name('index');
            Route::get('/{project}', [ProjectController::class, 'show'])->name('show');
        });

        // The list, the detail page, and the writes doing the work involves. The timer arrives
        // in Phase 4.
        //
        // A Manager reaches this surface and not the Admin one, so the moves the plan gives to
        // ADMIN/MANAGER — delete, archive, the review verdicts through the status endpoint —
        // are routed here and refused to an employee by TaskPolicy, not by being absent.
        // Unarchive is Admin-only and therefore Admin-surface only.
        Route::prefix('tasks')->name('tasks.')->group(function () {
            Route::get('/', [TaskController::class, 'index'])->name('index');

            // Routes of their own rather than `?view=` on the index, for the same reasons as
            // on the Admin surface — and declared BEFORE `/{task}`, or `board` binds as a task
            // id. The employee's board and calendar hold the tasks they are assigned to and
            // nothing else; the scoping is Task::visibleTo()'s, not the route's.
            Route::get('/board', [TaskController::class, 'board'])->name('board');
            Route::get('/calendar', [TaskController::class, 'calendar'])->name('calendar');

            Route::get('/{task}', [TaskController::class, 'show'])->name('show');
            Route::put('/{task}', [TaskController::class, 'update'])->name('update');
            Route::delete('/{task}', [TaskController::class, 'destroy'])->name('destroy');

            // The same endpoint, the same request and the same service call as the Admin
            // surface's: one status machine, not one per surface.
            Route::post('/{task}/status', [TaskController::class, 'status'])->name('status');
            Route::post('/{task}/reorder', [TaskController::class, 'reorder'])->name('reorder');
            Route::post('/{task}/archive', [TaskController::class, 'archive'])->name('archive');
            Route::post('/{task}/handoff', [TaskController::class, 'handOff'])->name('handoff');

            Route::post('/{task}/checklist', [TaskController::class, 'storeChecklistItem'])->name('checklist.store');
            Route::put('/{task}/checklist/{item}', [TaskController::class, 'updateChecklistItem'])->name('checklist.update');
            Route::delete('/{task}/checklist/{item}', [TaskController::class, 'destroyChecklistItem'])->name('checklist.destroy');

            Route::post('/{task}/links', [TaskController::class, 'storeLink'])->name('links.store');
            Route::delete('/{task}/links/{link}', [TaskController::class, 'destroyLink'])->name('links.destroy');

            // Attachments on a task this employee is assigned to.
            Route::get('/{task}/files', [TaskFileController::class, 'index'])->name('files.index');
            Route::post('/{task}/files', [TaskFileController::class, 'store'])->name('files.store');

            // The discussion of a task this employee is assigned to — and of no other, which
            // is Task::visibleTo()'s doing and not this route's. A task they are not on
            // answers 404, exactly as its attachments and the task itself do.
            Route::get('/{task}/discussion', [TaskDiscussionController::class, 'index'])->name('discussion.index');
            Route::post('/{task}/discussion', [TaskDiscussionController::class, 'store'])->name('discussion.store');
        });

        // Tag management, here because this is the surface a MANAGER reaches — the plan gives
        // tag creation to Admin/Manager, and a route only on the Admin shell would have made
        // the second half of that unreachable. An employee is refused by TagPolicy with a 403,
        // the same way archive and delete above refuse them.
        Route::prefix('tags')->name('tags.')->group(function () {
            Route::get('/', [TagController::class, 'index'])->name('index');
            Route::post('/', [TagController::class, 'store'])->name('store');
            Route::put('/{tag}', [TagController::class, 'update'])->name('update');
            Route::delete('/{tag}', [TagController::class, 'destroy'])->name('destroy');
        });

        // One file. Here as well as on the Admin surface because the delete rule is "the
        // uploader or an Admin" and most uploaders are on this surface — an employee has to be
        // able to remove their own wrong screenshot. FilePolicy keeps them to their own uploads
        // on their own tasks; a project or client file is not visible from here at all.
        Route::prefix('files')->name('files.')->group(function () {
            // The history of a file this employee can already see — FilePolicy delegates to the
            // owning record's policy, so that is a task they are assigned to or a project they
            // are a member of. Every version of a chain hangs off that same record, so the
            // chain is exactly as visible as the row they asked about, and a file on somebody
            // else's task is 404 here: versions, count and all.
            Route::get('/{file}/versions', [FileController::class, 'versions'])->name('versions.index');
            Route::post('/{file}/versions', [FileController::class, 'storeVersion'])->name('versions.store');
            Route::delete('/{file}', [FileController::class, 'destroy'])->name('destroy');
        });
    });
