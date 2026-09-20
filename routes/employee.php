<?php

use App\Http\Controllers\Employee\DashboardController;
use Illuminate\Support\Facades\Route;

Route::prefix('employee')
    ->name('employee.')
    ->middleware(['auth', 'two-factor', 'surface:employee'])
    ->group(function () {
        Route::get('/dashboard', DashboardController::class)->name('dashboard');
    });
