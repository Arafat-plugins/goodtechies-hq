<?php

use App\Http\Controllers\Shared\AttendanceController;
use App\Http\Controllers\Shared\FileDownloadController;
use App\Http\Controllers\Shared\NotificationController;
use App\Http\Controllers\Shared\ProfileController;
use App\Http\Controllers\Shared\ProfilePasswordController;
use App\Http\Controllers\Shared\ProfileSessionController;
use App\Http\Controllers\Shared\ProfileTwoFactorController;
use App\Models\Notification;
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

    // The bell and the Notification Center. Shared rather than one set per surface, for the
    // same reason the file download is: a person's own mail is a fact about the person, not
    // about the shell they are looking at, and three copies of these four routes would be
    // three places for "whose notification is this" to be answered differently.
    //
    // `can:viewAny` is the only gate, and it is on the group: it asks whether this person could
    // receive any kind of notification at all (NotificationPolicy). The Accountant holds no
    // `tasks.*` key and every Phase 2 notification type requires one, so they are refused here
    // — by the catalogue, not by being named. Whose row a given id IS gets no gate at all: the
    // controller scopes every lookup to the signed-in user, so somebody else's notification is
    // 404 and never 403.
    Route::prefix('notifications')
        ->name('notifications.')
        ->middleware('can:viewAny,'.Notification::class)
        ->group(function () {
            // The Centre's list. JSON — the screens arrive in the next dispatch, and the bell
            // polls this shape either way.
            Route::get('/', [NotificationController::class, 'index'])->name('index');

            // The bell itself: the badge and the newest few, in one request. Declared before
            // `/{notification}/read` for clarity; they cannot collide, because that one is two
            // segments deep.
            Route::get('/recent', [NotificationController::class, 'recent'])->name('recent');

            Route::post('/read-all', [NotificationController::class, 'readAll'])->name('read-all');
            Route::post('/{notification}/read', [NotificationController::class, 'read'])->name('read');
        });

    // Somebody's attendance, and the clock (master prompt Part D §8, Phase 4). Shared rather
    // than one set per surface for the reason the notification routes above are: BOTH Admins
    // clock in and out — Part D §8 says so in its title — and so does Yaseen, so three copies
    // of these three routes would be three places for "whose day is this" to be answered
    // differently. The page picks its layout from `auth.user.surface`, as Profile does.
    //
    // `{employee?}` is what makes Part C's rule real on this surface: no parameter means
    // yours, and a parameter is resolved through `Employee::attendanceVisibleTo()` — so an
    // employee asking for a colleague's month gets **404**, not 403, and a Manager reaches
    // their own team's. The Admin's roster and the edit are NOT here; correcting somebody's
    // pay record is an Admin act on the Admin surface.
    //
    // Declared before the clock routes would be needed only if they collided; they cannot,
    // because `{employee}` is bound to a model and `clock-in` is not a numeric id. It is
    // still written this way round so the page reads as the subject and the clock as the verb.
    Route::get('/attendance/{employee?}', [AttendanceController::class, 'show'])
        ->whereNumber('employee')
        ->name('attendance.show');
    Route::post('/attendance/clock-in', [AttendanceController::class, 'clockIn'])->name('attendance.clock-in');
    Route::post('/attendance/clock-out', [AttendanceController::class, 'clockOut'])->name('attendance.clock-out');

    // Downloading a file. Shared rather than one route per surface, because who may fetch a
    // file is a fact about the requester and the record it hangs off, not about the shell they
    // are in — see FileDownloadController.
    //
    // `signed` is the outer guard: FileService mints every link through
    // URL::temporarySignedRoute, so a link that was edited or has expired is refused here with
    // a 403 before the controller runs. It is not the only guard. The signature says the link
    // is intact; FilePolicy, inside, says whether THIS person may have the file — so a link
    // forwarded to somebody who may not see the task answers 404 while it is still perfectly
    // valid. An expiring URL that works for anybody holding it is a different security model
    // and not the one this application has.
    Route::get('/files/{file}', FileDownloadController::class)
        ->middleware('signed')
        ->name('files.download');
});
