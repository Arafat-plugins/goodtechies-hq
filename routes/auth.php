<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\TwoFactorChallengeController;
use App\Http\Controllers\Auth\TwoFactorEnrolmentController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])
        ->middleware('throttle:login')
        ->name('login.store');

    Route::get('/two-factor/challenge', [TwoFactorChallengeController::class, 'create'])
        ->name('two-factor.challenge');
    Route::post('/two-factor/challenge', [TwoFactorChallengeController::class, 'store'])
        ->middleware('throttle:two-factor')
        ->name('two-factor.challenge.store');
});

// `logout` keeps `active` too: an inactive user posting it is signed out by the middleware and
// lands on /login all the same, so no separate group is needed to let them leave.
Route::middleware(['auth', 'active'])->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    Route::get('/two-factor/enrol', [TwoFactorEnrolmentController::class, 'create'])
        ->name('two-factor.enrol');
    Route::post('/two-factor/enrol', [TwoFactorEnrolmentController::class, 'store'])
        ->name('two-factor.enrol.store');
    Route::get('/two-factor/recovery-codes', [TwoFactorEnrolmentController::class, 'recoveryCodes'])
        ->name('two-factor.recovery-codes');
});
