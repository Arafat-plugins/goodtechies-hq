<?php

use App\Http\Controllers\Accountant\DashboardController;
use App\Http\Controllers\Accountant\ProjectController;
use App\Support\Permission;
use Illuminate\Support\Facades\Route;

Route::prefix('accountant')
    ->name('accountant.')
    // See routes/admin.php for what `throttle:authenticated` is.
    ->middleware(['auth', 'active', 'two-factor', 'surface:accountant', 'throttle:authenticated'])
    ->group(function () {
        Route::get('/dashboard', DashboardController::class)->name('dashboard');

        // Phase 8. The finance-only project endpoint of master prompt Part D §13: id, name,
        // domain and the `project_finance` fields, read-only, with no client name or contacts.
        // It is the ONLY project route the Accountant has; every ordinary one is 403 and stays
        // that way (tests/Feature/Privacy/ProjectPrivacyTest.php).
        //
        // `projects.view_finance` is the matrix row this endpoint is. The gate is the unscoped
        // key check, which is deliberately not `ProjectPolicy::viewFinance` — see the
        // controller for why those are different questions.
        Route::get('/projects', ProjectController::class)
            ->middleware('can:'.Permission::ProjectsViewFinance->value)
            ->name('projects.index');
    });
