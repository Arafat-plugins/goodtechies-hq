<?php

use App\Http\Controllers\Admin\ClientController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\ProjectController;
use App\Http\Controllers\Admin\ProjectFinanceController;
use App\Http\Controllers\Admin\ProjectMemberController;
use App\Http\Controllers\Admin\ProjectStatusController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\TaskController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin')
    ->name('admin.')
    ->middleware(['auth', 'active', 'two-factor', 'surface:admin'])
    ->group(function () {
        Route::get('/dashboard', DashboardController::class)->name('dashboard');
        Route::get('/settings', SettingsController::class)
            ->middleware('can:settings.manage')
            ->name('settings');

        Route::prefix('clients')->name('clients.')->group(function () {
            Route::get('/', [ClientController::class, 'index'])->name('index');
            Route::get('/create', [ClientController::class, 'create'])->name('create');
            Route::post('/', [ClientController::class, 'store'])->name('store');
            Route::get('/{client}', [ClientController::class, 'show'])->name('show');
            Route::get('/{client}/edit', [ClientController::class, 'edit'])->name('edit');
            Route::put('/{client}', [ClientController::class, 'update'])->name('update');
            // Clients are never deleted; a finished one is deactivated.
            Route::post('/{client}/deactivate', [ClientController::class, 'deactivate'])->name('deactivate');
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
        });
    });
