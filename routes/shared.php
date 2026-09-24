<?php

use App\Http\Controllers\Shared\AttendanceController;
use App\Http\Controllers\Shared\FileDownloadController;
use App\Http\Controllers\Shared\LeaveController;
use App\Http\Controllers\Shared\MessageController;
use App\Http\Controllers\Shared\NotificationController;
use App\Http\Controllers\Shared\ProfileController;
use App\Http\Controllers\Shared\ProfilePasswordController;
use App\Http\Controllers\Shared\ProfileSessionController;
use App\Http\Controllers\Shared\ProfileTwoFactorController;
use App\Http\Controllers\Shared\TeamController;
use App\Models\Notification;
use App\Support\Permission;
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

    // Messages (master prompt Part D §10, Phase 6): the team channel, the project channels,
    // the announcements channel and this person's DMs. Shared rather than one set per surface
    // for the reason the notification routes above are: whose mail a thread is belongs to the
    // PERSON, not to the shell they are in, and three copies of these five routes would be
    // three places for "may this person read this conversation" to be answered differently.
    // `Pages/Shared/Messages.vue` picks its layout from `auth.user.surface`, as Profile,
    // Attendance and Leave do.
    //
    // **`can:messages.use` on the group is "the Accountant has no messaging routes".** The
    // spec's sentence is a rule about a capability, not about a person, so it is spelled as a
    // permission and the Accountant is refused by holding none of it — the same shape
    // `can:viewAny` on the notification group has, and the same reason no policy in this
    // codebase names a role. Every one of these five routes answers 403 for them, the Messages
    // nav row is absent from `navigation/accountant.ts`, and nothing anywhere says "Accountant".
    //
    // The task discussion is NOT here. It is read inside its task, on the task's own surface,
    // where it has been since Phase 2.
    //
    // `{conversation}` is `whereNumber`ed so `messages/direct/{user}` cannot be read as a
    // conversation called "direct". A conversation this person may not open is **404** from the
    // controller, never 403: they do not learn whether the id exists (Part C).
    Route::prefix('messages')
        ->name('messages.')
        ->middleware('can:'.Permission::MessagesUse->value)
        ->group(function () {
            Route::get('/', [MessageController::class, 'index'])->name('index');

            // Declared before `{conversation}` for readability; they cannot collide, because
            // this one is two segments deep and that one is numeric.
            Route::post('/direct/{user}', [MessageController::class, 'direct'])
                ->whereNumber('user')
                ->name('direct');

            Route::get('/{conversation}', [MessageController::class, 'show'])
                ->whereNumber('conversation')
                ->name('show');
            Route::post('/{conversation}', [MessageController::class, 'store'])
                ->whereNumber('conversation')
                ->name('store');
            Route::post('/{conversation}/read', [MessageController::class, 'read'])
                ->whereNumber('conversation')
                ->name('read');
        });

    // The Team directory (Part D §2: Admin WORK → Team, Employee Messages → Team, Phase 6).
    //
    // Shared, and outside the `messages` prefix but behind the SAME key, for the two reasons
    // above: who works here is a fact about the agency rather than about a shell, and the
    // plan's *"Accountant has no messaging routes"* is a rule about a capability. A directory
    // whose only action is "send this person a message" is a messaging route, so it takes
    // `messages.use` and the Accountant gets 403 from the same key as the five above — while
    // still appearing IN the directory, because they work here. See TeamController.
    //
    // One route and one verb. There is no `/team/{employee}`: a person's page is their
    // attendance month or their profile, both of which already exist and are both scoped by
    // something this screen deliberately is not.
    Route::get('/team', [TeamController::class, 'index'])
        ->middleware('can:'.Permission::MessagesUse->value)
        ->name('team.index');

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

    // My Leave, and applying for it (master prompt Part D §9, Phase 5). Shared rather than one
    // set per surface for the reason the clock above is, and Part C §1 states it outright:
    // **every** role may apply for their own leave, the ACCOUNTANT included — and the note
    // under that matrix says so again ("The Accountant shell therefore carries My Leave and My
    // Payslip"). Applying is a fact about the person, not about the shell they are in
    // (decision 4-15), so three copies of these three routes would have been three places for
    // "whose leave is this" to be answered differently — and the Accountant's copy would have
    // been the one nobody tested.
    //
    // **The Accountant still applies in its own shell.** That is the page's doing, not the
    // route's: `Pages/Shared/Leave.vue` picks its layout from `auth.user.surface`, exactly as
    // Profile and Attendance do, so an Accountant gets `AccountantLayout` and imports nothing
    // from `Layouts/AdminLayout.vue` or `Pages/Admin/`.
    //
    // The QUEUE is not here. Approving, rejecting, sending a request back and editing a balance
    // are Admin acts on the Admin surface — see `routes/admin.php`.
    //
    // `PUT /leave/{leaveRequest}` is the employee's one move: answering a correction request by
    // amending and resubmitting, which is `correction_requested → pending` on the SAME request.
    // The id is re-resolved through `LeaveRequest::visibleTo()` before the policy is asked, so
    // somebody else's request is **404** and never 403 — the requester never learns whether the
    // id existed (Part C).
    Route::get('/leave', [LeaveController::class, 'show'])->name('leave.show');
    Route::post('/leave', [LeaveController::class, 'store'])->name('leave.store');
    Route::put('/leave/{leaveRequest}', [LeaveController::class, 'update'])->name('leave.update');

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
