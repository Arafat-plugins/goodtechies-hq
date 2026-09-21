<?php

use App\Http\Controllers\Employee\DashboardController;
use App\Http\Controllers\Employee\ProjectController;
use App\Http\Controllers\Employee\TaskController;
use Illuminate\Support\Facades\Route;

Route::prefix('employee')
    ->name('employee.')
    ->middleware(['auth', 'active', 'two-factor', 'surface:employee'])
    ->group(function () {
        Route::get('/dashboard', DashboardController::class)->name('dashboard');

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
        });
    });
