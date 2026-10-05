<?php

use App\Http\Controllers\Shared\AttendanceController;
use App\Http\Controllers\Shared\ExpenseController;
use App\Http\Controllers\Shared\FileDownloadController;
use App\Http\Controllers\Shared\FinanceCategoryController;
use App\Http\Controllers\Shared\FinanceReportController;
use App\Http\Controllers\Shared\GroupController;
use App\Http\Controllers\Shared\IncomeController;
use App\Http\Controllers\Shared\LeaveController;
use App\Http\Controllers\Shared\MeetingController;
use App\Http\Controllers\Shared\MeetingDetailController;
use App\Http\Controllers\Shared\MessageController;
use App\Http\Controllers\Shared\MessageEditController;
use App\Http\Controllers\Shared\NotificationController;
use App\Http\Controllers\Shared\PayrollController;
use App\Http\Controllers\Shared\PayslipController;
use App\Http\Controllers\Shared\PresenceController;
use App\Http\Controllers\Shared\ProfileController;
use App\Http\Controllers\Shared\ProfileExtensionController;
use App\Http\Controllers\Shared\ProfilePasswordController;
use App\Http\Controllers\Shared\ProfileSessionController;
use App\Http\Controllers\Shared\ProfileThemeController;
use App\Http\Controllers\Shared\ProfileTwoFactorController;
use App\Http\Controllers\Shared\PushSubscriptionController;
use App\Http\Controllers\Shared\SalaryController;
use App\Http\Controllers\Shared\SearchController;
use App\Http\Controllers\Shared\TaskTimerController;
use App\Http\Controllers\Shared\TeamController;
use App\Models\Notification;
use App\Support\Permission;
use Illuminate\Support\Facades\Route;

// See routes/admin.php for what `throttle:authenticated` is.
Route::middleware(['auth', 'active', 'two-factor', 'throttle:authenticated'])->group(function () {
    // ── Global search (master prompt Part D §17, Phase 10) ──────────────────────────
    //
    // One route, and the ONLY group in this file with no `can:` on it. Every neighbour below
    // carries one — `can:messages.use`, `can:meetings.use`, `can:finance.view`,
    // `can:payroll.view_own` — so the absence here is a statement rather than an omission:
    // **every signed-in role may search.** What differs between them is what they FIND, not
    // whether they may look, and a gate would have to name a `search.use` key that every role
    // holds, which is not a capability.
    //
    // Shared rather than one copy per shell, for the reason Messages, Leave, Attendance,
    // Meetings and Finance are shared: what a person can find is a fact about the PERSON, not
    // about the shell they are in. The deep link in each result is resolved for the viewer's
    // own surface on the server (`SearchService::surfaceBase()`), so an employee is never
    // handed `/admin/projects/12` — a 403 dressed up as a link, which the Meetings slice hit.
    //
    // The whole privacy rule lives in `SearchService`: the requester's accessible ids scope
    // every query BEFORE the term ranks anything, reusing each model's existing `visibleTo()`
    // rather than a second access rule. The Accountant reaching this route is correct — they
    // find their finance records and nothing else, because of the keys they hold and not
    // because anything here names them.
    //
    // JSON, because the command palette fetches it on a keystroke; an Inertia visit would push
    // a history entry per letter. A short or empty `q` is 200 with an empty envelope, never
    // 422 (decision M-4) — a search-as-you-type box must not flash an error on the first key.
    //
    // `throttle:search`: this is the most expensive read in the application per request —
    // one tsvector query per searchable type. 120 a minute; the palette's 180 ms debounce
    // and its abort-the-previous-request mean a human is nowhere near it.
    Route::get('/search', [SearchController::class, 'index'])
        ->middleware('throttle:search')
        ->name('search.index');

    // Presence heartbeat (12-79): moves `users.last_seen_at`, at most every 30 seconds. Every
    // signed-in person has a last-seen time; who may SEE it is the messaging payloads' business.
    Route::post('/presence/heartbeat', [PresenceController::class, 'heartbeat'])
        ->name('presence.heartbeat');

    Route::get('/profile', [ProfileController::class, 'show'])->name('profile.show');
    Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('/profile/password', ProfilePasswordController::class)->name('profile.password.update');
    // Light / Dark / System on the account (2026-10-05): every browser and device opens the same.
    Route::put('/profile/theme', ProfileThemeController::class)
        ->middleware('throttle:30,1')
        ->name('profile.theme.update');

    Route::delete('/profile/two-factor', [ProfileTwoFactorController::class, 'destroy'])
        ->name('profile.two-factor.destroy');
    Route::post('/profile/two-factor/recovery-codes', [ProfileTwoFactorController::class, 'regenerateRecoveryCodes'])
        ->name('profile.two-factor.recovery-codes');

    // {session} is the raw session id, not a bound model.
    Route::delete('/profile/sessions/{session}', [ProfileSessionController::class, 'destroy'])
        ->name('profile.sessions.destroy');

    // Timer extension pairing (Phase 11, docs/extension-api.md §2). Remote timer users only.
    Route::post('/profile/extension/code', [ProfileExtensionController::class, 'code'])
        ->name('profile.extension.code');
    Route::delete('/profile/extension/devices/{device}', [ProfileExtensionController::class, 'destroyDevice'])
        ->whereNumber('device')
        ->name('profile.extension.devices.destroy');

    // Push notifications on this device (Profile). JSON for the two subscription calls, because
    // the browser's PushManager — not a form — produces what is posted.
    Route::post('/push/subscriptions', [PushSubscriptionController::class, 'store'])
        ->middleware('throttle:30,1')
        ->name('push.subscriptions.store');
    Route::delete('/push/subscriptions', [PushSubscriptionController::class, 'destroy'])
        ->middleware('throttle:30,1')
        ->name('push.subscriptions.destroy');
    Route::put('/profile/push', [PushSubscriptionController::class, 'preferences'])
        ->name('profile.push.update');

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
            // Polish 012: delete one, or every one already read. Dismissals, not row deletes.
            Route::delete('/read', [NotificationController::class, 'clearRead'])->name('clear-read');
            Route::delete('/{notification}', [NotificationController::class, 'destroy'])
                ->whereNumber('notification')
                ->name('destroy');
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

            // Search, INSIDE this group so it inherits `can:messages.use` like everything else
            // here — a search endpoint hanging outside the gate would be the one route where
            // "the Accountant has no messaging routes" was not said. It cannot collide with
            // `/{conversation}`, because that one is numeric and this is a word.
            //
            // The scope is the requester's own inbox and it is built BEFORE the term is: see
            // ConversationService::search(). A term that appears only in a project channel they
            // are not on is not discoverable, not even as a count (Part C).
            Route::get('/search', [MessageController::class, 'search'])
                ->middleware('throttle:search')
                ->name('search');

            // Declared before `{conversation}` for readability; they cannot collide, because
            // this one is two segments deep and that one is numeric.
            Route::post('/direct/{user}', [MessageController::class, 'direct'])
                ->whereNumber('user')
                ->name('direct');

            Route::get('/{conversation}', [MessageController::class, 'show'])
                ->whereNumber('conversation')
                ->name('show');

            // The thread's right-hand panel: who is in the room, what has been posted into it,
            // and the work it is about. The SAME 404 `show()` gives for a conversation this
            // person may not open, and deliberately no read-marking — a side panel is not a
            // visit, and one that moved the unread line would make the list row disagree with
            // what the reader has actually seen.
            Route::get('/{conversation}/context', [MessageController::class, 'context'])
                ->whereNumber('conversation')
                ->name('context');
            Route::post('/{conversation}', [MessageController::class, 'store'])
                ->middleware('throttle:posting')
                ->whereNumber('conversation')
                ->name('store');
            Route::post('/{conversation}/read', [MessageController::class, 'read'])
                ->whereNumber('conversation')
                ->name('read');

            // Edit, delete-for-everyone and react (12-79). The message must belong to the
            // conversation in the URL (404 otherwise); MessagePolicy decides the rest.
            Route::patch('/{conversation}/messages/{message}', [MessageEditController::class, 'update'])
                ->middleware('throttle:posting')
                ->whereNumber(['conversation', 'message'])
                ->name('update');
            Route::delete('/{conversation}/messages/{message}', [MessageEditController::class, 'destroy'])
                ->middleware('throttle:posting')
                ->whereNumber(['conversation', 'message'])
                ->name('destroy');
            Route::post('/{conversation}/messages/{message}/reactions', [MessageEditController::class, 'react'])
                ->middleware('throttle:posting')
                ->whereNumber(['conversation', 'message'])
                ->name('react');

            // Message groups (12-81). Writes need `messages.manage` (403, the Form Requests);
            // a `{conversation}` that is not a group the actor may view is 404 (GroupController).
            Route::post('/groups', [GroupController::class, 'store'])
                ->middleware('throttle:posting')
                ->name('groups.store');
            Route::post('/groups/{conversation}', [GroupController::class, 'update'])
                ->middleware('throttle:posting')
                ->whereNumber('conversation')
                ->name('groups.update');
            Route::post('/groups/{conversation}/members', [GroupController::class, 'addMembers'])
                ->middleware('throttle:posting')
                ->whereNumber('conversation')
                ->name('groups.members.add');
            Route::delete('/groups/{conversation}/members/{user}', [GroupController::class, 'removeMember'])
                ->middleware('throttle:posting')
                ->whereNumber(['conversation', 'user'])
                ->name('groups.members.remove');
            Route::delete('/groups/{conversation}', [GroupController::class, 'destroy'])
                ->middleware('throttle:posting')
                ->whereNumber('conversation')
                ->name('groups.destroy');
            Route::get('/groups/{conversation}/avatar', [GroupController::class, 'avatar'])
                ->whereNumber('conversation')
                ->name('groups.avatar');
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
    // Client doc 2026-10-05 item 1: the last tab's pagehide beacon (see AttendanceController::leaving).
    Route::post('/attendance/leaving', [AttendanceController::class, 'leaving'])->name('attendance.leaving');

    // The task timer for everyone who works tasks (flow F3, decision 12-73): ▶ on a board card
    // or in the drawer, then ⏸ / resume / ⏹ on whatever is open. Shared for the clock's reason —
    // an Admin and Yaseen press the same button — and gated by `TimeEntryPolicy::trackTasks`
    // (403: the Accountant) with the task resolved through `Task::visibleTo()` (404) and the
    // assignment asked by `trackTask` (403). Office employees and Admins must be clocked in;
    // clock-out above stops the timer. The remote widget's `/employee/time/*` is untouched.
    Route::post('/tasks/{task}/timer', [TaskTimerController::class, 'start'])
        ->whereNumber('task')
        ->name('task-timer.start');
    Route::post('/task-timer/pause', [TaskTimerController::class, 'pause'])->name('task-timer.pause');
    Route::post('/task-timer/resume', [TaskTimerController::class, 'resume'])->name('task-timer.resume');
    Route::post('/task-timer/stop', [TaskTimerController::class, 'stop'])->name('task-timer.stop');
    Route::post('/task-timer/heartbeat', [TaskTimerController::class, 'heartbeat'])->name('task-timer.heartbeat');
    // The last tab's `pagehide` beacon (`_token` as a form field): a mark the sweep acts on.
    Route::post('/task-timer/leaving', [TaskTimerController::class, 'leaving'])->name('task-timer.leaving');

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
    //
    // `POST /leave/{leaveRequest}/withdraw` is the employee's SECOND move (decision 5-19): taking
    // their own request back while it is still waiting on somebody. It is here rather than in
    // `routes/admin.php` beside approve/reject/correction for the reason the three routes above are
    // here — it is a fact about the applicant, not about an approver, and the Accountant may do it
    // too. It resolves the id the same way `update` does, so somebody else's request is 404.
    Route::get('/leave', [LeaveController::class, 'show'])->name('leave.show');
    Route::post('/leave', [LeaveController::class, 'store'])->name('leave.store');
    Route::put('/leave/{leaveRequest}', [LeaveController::class, 'update'])->name('leave.update');
    Route::post('/leave/{leaveRequest}/withdraw', [LeaveController::class, 'withdraw'])
        ->whereNumber('leaveRequest')
        ->name('leave.withdraw');

    // Meetings (master prompt Part D §12, Phase 7): the list, the month and week calendar, and
    // the create/edit form. Shared rather than one set per surface for the reason the Messages
    // group above is: **whose calendar a meeting is on belongs to the PERSON**, not to the shell
    // they happen to be looking at. Both Admins book meetings, so do the employees, and three
    // copies of these routes would have been three places for "may this person see this
    // meeting" to be answered differently — with one of the three the copy nobody tested.
    // `Pages/Shared/Meetings/*.vue` pick their layout from `auth.user.surface`, exactly as
    // Profile, Attendance, Leave and Messages do.
    //
    // **`can:meetings.use` on the group is Part D §12's "the Accountant has no meetings".** It
    // is a rule about a capability rather than about a person, so it is spelled as a permission
    // and the Accountant is refused by holding none of it — the same shape `can:messages.use`
    // has above, and the reason nothing in this group names a role. The Meetings nav row is
    // absent from `navigation/accountant.ts` for the same reason.
    //
    // `{meeting}` is `whereNumber`ed, so `meetings/create` can never be read as a meeting
    // called "create". A meeting this person may not see is **404** from the controller, never
    // 403: they do not learn whether the id exists (Part C). One they can see and may not edit
    // is **403** — the act is refused, not the record.
    Route::prefix('meetings')
        ->name('meetings.')
        ->middleware('can:'.Permission::MeetingsUse->value)
        ->group(function () {
            Route::get('/', [MeetingController::class, 'index'])->name('index');

            // Declared before `{meeting}` for readability; they cannot collide, because that
            // one is numeric and this is a word.
            Route::get('/create', [MeetingController::class, 'create'])->name('create');
            Route::post('/', [MeetingController::class, 'store'])->name('store');

            Route::get('/{meeting}/edit', [MeetingController::class, 'edit'])
                ->whereNumber('meeting')
                ->name('edit');
            Route::put('/{meeting}', [MeetingController::class, 'update'])
                ->whereNumber('meeting')
                ->name('update');

            // ── The meeting itself (Phase 7 slice 3) ────────────────────────────────────
            //
            // One screen and the four things it does: answer the invitation, write the
            // meeting up, turn what was agreed into a task, and call it off. They sit INSIDE
            // this group rather than in one of their own, so "the Accountant has no meetings"
            // is said once — a second group behind the same key would be a second place for
            // that sentence to be edited, and the one that got missed.
            //
            // `{meeting}` is `whereNumber`ed on every one of them, like the two above.
            // `MeetingDetailController` resolves each id through `Meeting::visibleTo($user)`
            // BEFORE asking any ability, so a meeting this person is not in is **404** and one
            // they are in but may not act on is **403** — see that controller's docblock for
            // why swapping the two would tell an employee that a meeting with that id exists
            // and that they were not invited.
            Route::get('/{meeting}', [MeetingDetailController::class, 'show'])
                ->whereNumber('meeting')
                ->name('show');
            Route::post('/{meeting}/cancel', [MeetingDetailController::class, 'cancel'])
                ->whereNumber('meeting')
                ->name('cancel');
            Route::post('/{meeting}/rsvp', [MeetingDetailController::class, 'rsvp'])
                ->whereNumber('meeting')
                ->name('rsvp');
            Route::put('/{meeting}/notes', [MeetingDetailController::class, 'notes'])
                ->whereNumber('meeting')
                ->name('notes');
            Route::post('/{meeting}/action-items', [MeetingDetailController::class, 'actionItem'])
                ->whereNumber('meeting')
                ->name('action-items');
        });

    // ── Finance (Phase 8) ───────────────────────────────────────────────────────────
    //
    // The company's books. Shared rather than one copy per shell, for the reason Messages,
    // Leave, Attendance and Meetings are shared: **whose money it is belongs to the agency,
    // not to the shell somebody is looking at.** Part E's Phase 8 heading reads "Screens
    // (Accountant shell + Admin → Finance)", and an Admin reading September and the Accountant
    // reading September are the same rows asked the same way — two sets of routes would be two
    // places for "what is September" to be answered differently, and both of them are about
    // money. `Pages/Shared/Finance/*.vue` pick their layout from `auth.user.surface`, exactly
    // as Profile, Attendance, Leave, Messages and Meetings do.
    //
    // **`can:finance.view` on the group is the phase's own security line**, *"Employees/Remote
    // get 403 on every finance route"*, spelled as a capability rather than as a role: they
    // hold neither finance key, so they are refused here before a controller runs. Nothing in
    // this group names a role, which is also what would let a future bookkeeper role get these
    // screens by being granted the key in `RolePermissionSeeder` and nothing else.
    //
    // A write in here is refused a second time, by `IncomePolicy` / `ExpensePolicy` through
    // `FinanceService` — this gate is `finance.view`, and reading the books is not permission
    // to change them.
    Route::prefix('finance')
        ->name('finance.')
        ->middleware('can:'.Permission::FinanceView->value)
        ->group(function () {
            // "This month income / expense / net, by category" (Part D §13). The month is in
            // the URL — `?month=YYYY-MM` — so a month is a link somebody can send.
            Route::get('/', [FinanceReportController::class, 'dashboard'])->name('dashboard');

            // The Monthly financial report's three cuts: by category, by project, trend. Its
            // window is in the URL too, length included (`?month=…&months=…`), so a trend is
            // something a reader can cite.
            Route::get('/report', [FinanceReportController::class, 'report'])->name('report');

            // ── The ledgers (slice 2a) ──────────────────────────────────────────────
            //
            // A month of money in, a month of money out, and the list of names it is filed
            // under. `?month=YYYY-MM` on both ledgers, for the reason the dashboard above
            // carries one: a month is the unit Part D's rollup and every report are
            // denominated in, and which month you are reading is a link somebody can send
            // (DESIGN.md §5 rule 10).
            //
            // **`create` and `edit` are real GET routes rendering the same page component as
            // the index.** The form is a dialog over the ledger and its state lives in the
            // URL, not in component state: a validation failure then redirects back to an
            // address that renders the form again with the errors on it, the back button
            // closes it, and a half-written income survives a refresh.
            //
            // Every id is `whereNumber`ed, so `/finance/income/create` can never be read as an
            // income called "create".
            Route::get('/income', [IncomeController::class, 'index'])->name('income.index');
            Route::get('/income/create', [IncomeController::class, 'create'])->name('income.create');
            Route::post('/income', [IncomeController::class, 'store'])->name('income.store');
            Route::get('/income/{income}/edit', [IncomeController::class, 'edit'])
                ->whereNumber('income')
                ->name('income.edit');
            Route::put('/income/{income}', [IncomeController::class, 'update'])
                ->whereNumber('income')
                ->name('income.update');
            Route::delete('/income/{income}', [IncomeController::class, 'destroy'])
                ->whereNumber('income')
                ->name('income.destroy');

            Route::get('/expenses', [ExpenseController::class, 'index'])->name('expenses.index');
            Route::get('/expenses/create', [ExpenseController::class, 'create'])->name('expenses.create');
            Route::post('/expenses', [ExpenseController::class, 'store'])->name('expenses.store');
            Route::get('/expenses/{expense}/edit', [ExpenseController::class, 'edit'])
                ->whereNumber('expense')
                ->name('expenses.edit');
            Route::put('/expenses/{expense}', [ExpenseController::class, 'update'])
                ->whereNumber('expense')
                ->name('expenses.update');
            Route::delete('/expenses/{expense}', [ExpenseController::class, 'destroy'])
                ->whereNumber('expense')
                ->name('expenses.destroy');

            // The category list. It is INSIDE this group, so reading it takes `finance.view`
            // like every other finance screen — the Accountant lives in the pickers these
            // names fill, and a list they could not read would make both forms unusable.
            //
            // **Writing it is `settings.manage`, not `finance.manage`** — decision 8-12, Part
            // D §13's *"Admin-editable"*. That is `FinanceCategoryPolicy`'s answer and the
            // controller asks it per act; it is deliberately NOT restated as a second
            // middleware here, because a rule spelled in two places is a rule that can be
            // changed in one of them.
            Route::get('/categories', [FinanceCategoryController::class, 'index'])->name('categories.index');
            Route::post('/categories', [FinanceCategoryController::class, 'store'])->name('categories.store');
            Route::put('/categories/{category}', [FinanceCategoryController::class, 'update'])
                ->whereNumber('category')
                ->name('categories.update');
            Route::delete('/categories/{category}', [FinanceCategoryController::class, 'destroy'])
                ->whereNumber('category')
                ->name('categories.destroy');
        });

    // ── My Payslip (Phase 9, slice 2b) ──────────────────────────────────────────────
    //
    // This person's own pay. Shared rather than one copy per shell, for the reason My Leave
    // above is shared and stated in the same place: Part C §1 gives *View own payslip* a ✅ in
    // **every** column of the matrix, and the note under it says outright that *"the Accountant
    // shell therefore carries My Leave and My Payslip"*. Whose pay it is belongs to the PERSON,
    // not to the shell they are looking at, so three copies of these three routes would have
    // been three places for "whose payslip is this" to be answered differently — and the
    // Accountant's copy would have been the one nobody tested. `Pages/Shared/Payslip/*.vue`
    // pick their layout from `auth.user.surface`, exactly as Profile, Attendance, Leave,
    // Messages, Meetings and Finance do, so the Accountant reads their payslip in the
    // **Accountant** shell.
    //
    // `can:payroll.view_own` on the group is the whole gate, and every role holds that key —
    // the Accountant included. It is deliberately NOT `can:view,item`: the item is not a bound
    // model here.
    //
    // **`{item}` is a payroll item id and it is resolved through
    // `PayrollService::findItemFor($viewer, $id)`, never by route-model binding.** That single
    // call is all three halves of Part B §3 rule 1 at once — the listing omits other people's
    // rows, a direct id is **404** rather than 403 (a 403 would confirm the id exists), and the
    // attempt is written to `audit_logs`. The PDF goes through the very same call on the line
    // above the renderer, because a download endpoint that forgot the scope would be the one
    // hole in an otherwise airtight rule.
    //
    // `whereNumber` on both id routes, so nothing word-shaped can ever be read as an item id.
    Route::prefix('payslip')
        ->name('payslip.')
        ->middleware('can:'.Permission::PayrollViewOwn->value)
        ->group(function () {
            Route::get('/', [PayslipController::class, 'index'])->name('index');

            // `/{item}/pdf` is declared before `/{item}` only for readability; they cannot
            // collide, because that one is two segments deep.
            Route::get('/{item}/pdf', [PayslipController::class, 'pdf'])
                ->whereNumber('item')
                ->name('pdf');
            Route::get('/{item}', [PayslipController::class, 'show'])
                ->whereNumber('item')
                ->name('show');
        });

    // ── Salary settings per employee (Phase 9, slice 2b) ─────────────────────────────
    //
    // What each person is paid, from a date. Part D §14 puts *"salary settings per employee
    // (base salary, allowances — audit-logged)"* under **Admin → Payroll**, and
    // `can:payroll.approve` is how that is said: `RolePermissionSeeder` grants that key to
    // ADMIN and to nobody else, so the Accountant — who holds `payroll.view_own`,
    // `payroll.view_others` and `payroll.draft` — gets **403** here, along with every Manager,
    // Employee and Remote employee. A whole screen a role may not use is a 403 and not a 404:
    // Part B §3 rule 1 keeps the 404 for somebody's RECORD, and a route's existence is not
    // sensitive.
    //
    // The Accountant being refused is the plan's own division of labour, not an oversight:
    // their half of Part D §14 is *"fills/adjusts base salary, allowance, bonus, deduction,
    // advance"* on **this month's payroll item**, which is the payroll workbench. This screen
    // changes what somebody is paid from now on, which is every future month.
    //
    // **The write is a new effective-dated ROW, never an edit of an old one** (decision 9-1):
    // `PayrollService::setSalary()` is the only writer, it audits the change as
    // `salary.changed` in the same transaction, and nothing about that is restated in the
    // controller. It is `PUT` because it sets one employee's salary — the thing being
    // addressed is the person, and the row that records it is an implementation detail of how
    // this application remembers history.
    Route::prefix('salaries')
        ->name('salaries.')
        ->middleware('can:'.Permission::PayrollApprove->value)
        ->group(function () {
            Route::get('/', [SalaryController::class, 'index'])->name('index');
            Route::put('/{employee}', [SalaryController::class, 'update'])
                ->whereNumber('employee')
                ->name('update');
            // Polish 002: remove a salary row entered by mistake (audited `salary.deleted`).
            Route::delete('/rows/{salary}', [SalaryController::class, 'destroy'])
                ->whereNumber('salary')
                ->name('destroy');
        });

    // ── Payroll (Phase 9, slice 2a) ─────────────────────────────────────────────────
    //
    // The payroll workbench: the month list, the month itself, and every move the month can
    // make. Shared rather than one copy per shell, for the reason Finance above is shared —
    // **whose pay it is belongs to the agency, not to the shell somebody is looking at.**
    // Part E's Phase 9 heading splits the VERBS across two surfaces ("Accountant → Payroll:
    // … Calculate, submit for review. Admin → Payroll: Review, Approve, Lock, Reverse lock,
    // Mark paid") and not the screens: an Admin reading September and the Accountant reading
    // September are the same rows with the same figures. Two sets of routes would be two
    // places for "may this person lock a month" to be answered differently.
    // `Pages/Shared/Payroll/*.vue` pick their layout from `auth.user.surface`, exactly as
    // Profile, Attendance, Leave, Messages, Meetings and Finance do.
    //
    // **`can:payroll.draft` on the group is Phase 9's security line.** ADMIN and ACCOUNTANT
    // hold that key and nobody else does (`RolePermissionSeeder`), so an Employee, a Remote
    // employee and a Manager get **403** here before a controller runs — refused by the
    // absence of a key rather than by being named, like every other group in this file. It is
    // deliberately the DRAFT key and not the APPROVE one: reading the month and calculating it
    // are the Accountant's (Part C §1's "🟡 draft/calculate only"), and each of the five
    // Admin-only verbs is refused a second time by `PayrollPeriodPolicy` inside.
    //
    // My Payslip is NOT here. An employee reaches their own line through `PayrollItem`, never
    // through a period, and `payroll.view_own` — which every role holds — buys nothing at this
    // gate.
    //
    // Both ids are `whereNumber`ed. `{period}` is route-model bound and a period nobody has is
    // 404 from the binding; a period somebody may not ACT on is **403**, because a month is not
    // a secret from anybody holding the key above. `{item}` is deliberately NOT bound: one
    // person's salary IS a secret, so `PayrollController` resolves it through
    // `PayrollService::findItemFor()`, whose 404 is Part B §3 rule 1 and whose audit row is
    // Part C §3's "every access attempt to another employee's salary is logged".
    Route::prefix('payroll')
        ->name('payroll.')
        ->middleware('can:'.Permission::PayrollDraft->value)
        ->group(function () {
            Route::get('/', [PayrollController::class, 'index'])->name('index');

            // Drafting this month by hand, for a month that missed the 1st. Declared before
            // `/{period}` for readability; they cannot collide, because that one is numeric
            // and this is the collection itself.
            Route::post('/', [PayrollController::class, 'store'])->name('store');

            Route::get('/{period}', [PayrollController::class, 'show'])
                ->whereNumber('period')
                ->name('show');

            // Polish 005: move a Draft to the month it pays for.
            Route::put('/{period}/month', [PayrollController::class, 'changeMonth'])
                ->whereNumber('period')
                ->name('month');

            Route::put('/{period}/items/{item}', [PayrollController::class, 'updateItem'])
                ->whereNumber('period')
                ->whereNumber('item')
                ->name('items.update');

            // The state machine, one route per verb. They are POSTs rather than one
            // `POST /{period}/transition` taking a target, for the reason Phase 2's task
            // status routes are separate: each one is a different act with a different
            // policy method, a different audit consequence and a different confirmation —
            // and a single endpoint switching on a body value would put the state machine's
            // map into a request parameter.
            Route::post('/{period}/calculate', [PayrollController::class, 'calculate'])
                ->whereNumber('period')
                ->name('calculate');
            Route::post('/{period}/review', [PayrollController::class, 'review'])
                ->whereNumber('period')
                ->name('review');
            Route::post('/{period}/approve', [PayrollController::class, 'approve'])
                ->whereNumber('period')
                ->name('approve');
            Route::post('/{period}/lock', [PayrollController::class, 'lock'])
                ->whereNumber('period')
                ->name('lock');
            Route::post('/{period}/reverse-lock', [PayrollController::class, 'reverseLock'])
                ->whereNumber('period')
                ->name('reverse-lock');
            Route::post('/{period}/paid', [PayrollController::class, 'markPaid'])
                ->whereNumber('period')
                ->name('paid');
        });

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
        ->middleware(['signed', 'throttle:downloads'])
        ->name('files.download');
});
