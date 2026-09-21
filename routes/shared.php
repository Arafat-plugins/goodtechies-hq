<?php

use App\Http\Controllers\Shared\ProfileController;
use App\Http\Controllers\Shared\ProfilePasswordController;
use App\Http\Controllers\Shared\ProfileSessionController;
use App\Http\Controllers\Shared\ProfileTwoFactorController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'active', 'two-factor'])->group(function () {
    Route::get('/profile', [ProfileController::class, 'show'])->name('profile.show');
    Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('/profile/password', ProfilePasswordController::class)->name('profile.password.update');

    Route::delete('/profile/two-factor', [ProfileTwoFactorController::class, 'destroy'])
        ->name('profile.two-factor.destroy');
    Route::post('/profile/two-factor/recovery-codes', [ProfileTwoFactorController::class, 'regenerateRecoveryCodes'])
        ->name('profile.two-factor.recovery-codes');

    // {session} is the raw session id, not a bound model.
    Route::delete('/profile/sessions/{session}', [ProfileSessionController::class, 'destroy'])
        ->name('profile.sessions.destroy');
});
