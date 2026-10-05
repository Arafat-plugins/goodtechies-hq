<?php

use App\Http\Controllers\Api\ExtensionPairingController;
use App\Http\Controllers\Api\HeartbeatController;
use App\Http\Controllers\Api\IdleDecisionController;
use App\Http\Controllers\Api\TimerController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Timer extension API (Phase 11, docs/extension-api.md §2)
|--------------------------------------------------------------------------
|
| The only `/api/*` routes there are. Bearer tokens (Sanctum) carrying the three timer
| abilities, for remote timer users only; anything unlisted here is a 404.
*/

Route::post('/extension/exchange', [ExtensionPairingController::class, 'exchange'])
    ->middleware('throttle:5,1')
    ->name('api.extension.exchange');

Route::middleware(['auth:sanctum', 'remote-timer'])->group(function (): void {
    Route::post('/extension/disconnect', [ExtensionPairingController::class, 'disconnect'])
        ->middleware('abilities:timer:track')
        ->name('api.extension.disconnect');

    Route::get('/timer/state', [TimerController::class, 'state'])
        ->middleware('abilities:timer:track')
        ->name('api.timer.state');
    Route::get('/timer/tasks', [TimerController::class, 'tasks'])
        ->middleware('abilities:timer:read-tasks')
        ->name('api.timer.tasks');
    Route::post('/timer/start', [TimerController::class, 'start'])
        ->middleware('abilities:timer:track')
        ->name('api.timer.start');
    Route::post('/timer/pause', [TimerController::class, 'pause'])
        ->middleware('abilities:timer:track')
        ->name('api.timer.pause');
    Route::post('/timer/resume', [TimerController::class, 'resume'])
        ->middleware('abilities:timer:track')
        ->name('api.timer.resume');
    Route::post('/timer/stop', [TimerController::class, 'stop'])
        ->middleware('abilities:timer:track')
        ->name('api.timer.stop');
    Route::post('/timer/heartbeat', HeartbeatController::class)
        ->middleware('abilities:timer:heartbeat')
        ->name('api.timer.heartbeat');
    Route::post('/timer/idle-decision', IdleDecisionController::class)
        ->middleware('abilities:timer:track')
        ->name('api.timer.idle-decision');
});
