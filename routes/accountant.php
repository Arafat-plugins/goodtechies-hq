<?php

use App\Http\Controllers\Accountant\DashboardController;
use Illuminate\Support\Facades\Route;

Route::prefix('accountant')
    ->name('accountant.')
    ->middleware(['auth', 'two-factor', 'surface:accountant'])
    ->group(function () {
        Route::get('/dashboard', DashboardController::class)->name('dashboard');
    });
