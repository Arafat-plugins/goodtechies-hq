<?php

use App\Models\Client;
use App\Models\Conversation;
use App\Models\Employee;
use App\Models\File;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Notification;
use App\Models\Project;
use App\Models\RecurringTask;
use App\Models\Tag;
use App\Models\Task;
use App\Models\TaskChecklistItem;
use App\Models\TaskLink;
use App\Models\TimeEntry;
use App\Models\User;
use App\Support\RoleName;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Role × route permission matrix
|--------------------------------------------------------------------------
|
| Every registered route must have a row here, or the coverage guard fails.
| Each request is sent without a body, as a guest or as one user per role.
|
| Expected values:
|   200              allowed, the page renders
|   302              allowed, the action ran and redirected (a missing body gives the
|                    validation redirect back, so the request got past every gate)
|   '302 <path>'     redirected to exactly <path>:
|                      guests on protected routes     → '302 /login'
|                      signed-in users on guest pages → '302 /'
|                      `/` and state redirects        → the named target
|   403              refused: wrong surface or missing permission
|   404              allowed, but the placeholder id does not belong to the user
|
| Users: seeded Admin, Employee, Remote Employee and Accountant (Admin and
| Accountant have confirmed 2FA) plus a factory Manager.
|
*/

const MATRIX_ROLES = ['guest', 'ADMIN', 'MANAGER', 'EMPLOYEE', 'REMOTE_EMPLOYEE', 'ACCOUNTANT'];

/**
 * Routes that are not part of the application surface.
 */
const MATRIX_IGNORED_ROUTES = [
    'up',           // framework health check
    '_inertia/*',   // Inertia devtools (local only)
    'storage/*',    // local disk file serving
];

/**
 * Values substituted for route parameters that need no database row.
 */
const MATRIX_PARAMETERS = [
    '{session}' => 'not-a-session-of-this-user',
];

/**
 * Every substitution, including the seeded records the parameterised routes point at. The
 * project is one of Tapu's two SEO projects, so a role that may not see it is covered too:
 * Yaseen is on neither, and the Accountant sees no project at all.
 *
 * @return array<string, string>
 */
function matrixParameters(): array
{
    return MATRIX_PARAMETERS + [
        '{client}' => (string) Client::where('name', 'Buffalo Modular Homes')->firstOrFail()->id,
        '{project}' => (string) Project::where('name', 'Buffalo Modular — SEO')->firstOrFail()->id,

        // The task every task row points at unless it overrides it: one of Tapu's SEO tasks, so
        // the roles line up the way the project rows do — Tapu is assigned, Yaseen is not and
        // gets 404, the Accountant has no task routes at all. It is the seeded task carrying a
        // checklist and links, which the child-resource rows need something real to aim at.
        '{task}' => matrixTaskId(MATRIX_TASK),
        // Defaults for the child parameters, overridden per row so that a DELETE consumes a
        // record no other row is pointed at.
        '{item}' => matrixResolve('checklist:List every page with a wrong canonical'),
        '{link}' => matrixResolve('link:https://search.google.com/search-console'),
        '{dependency}' => matrixTaskId('Build the internal link map for the county pages'),

        // Files are not seeded — nothing writes to a disk during `migrate:fresh --seed`, and
        // these rows need rows rather than bytes. The default one is on Tapu's task and
        // uploaded BY Tapu, which is what makes the employee delete row below read the delete
        // rule out loud: the uploader gets 302 and the Manager, who may edit the very same
        // task, gets 403.
        '{file}' => matrixFileId('default'),

        // A notification of Tapu's, so the `…/{notification}/read` row can state the whole
        // privacy rule in one line: an Admin who can see everything else in the agency gets
        // 404 on one line of somebody else's mail.
        '{notification}' => matrixNotificationId(),

        // The retainer template on the same Buffalo project the `{project}` rows point at, so
        // the Recurring rows read against a project the roles already line up on. It is one of
        // RecurringTaskSeeder's three, which is also what keeps the generate row honest: it
        // runs the real engine against a real rule.
        '{recurringTask}' => matrixRecurringTaskId(MATRIX_RECURRING_TEMPLATE),

        // A finished entry of Tapu's, on the same task the task rows point at. Not seeded —
        // `migrate:fresh --seed` starts no timers — so it is made here, the way the file and
        // notification rows make theirs.
        '{timeEntry}' => matrixTimeEntryId(),

        // Attendance (Phase 4). Tapu's EMPLOYEE record — not his user — so the
        // `attendance/{employee?}` row states Part C's rule in one line: he reads his own
        // month, an Admin reads everybody's, and for everyone else it is ABSENT rather than
        // refused. `{employee?}` is spelled separately because the shared route's parameter is
        // optional and the uri carries the question mark; strtr matches the longer key first,
        // so the two cannot collide.
        '{employee?}' => matrixEmployeeId('tapu@goodtechies.test'),
        '{employee}' => matrixEmployeeId('tapu@goodtechies.test'),

        // The day the attendance edit row aims at. In the past, because a day that has not
        // happened cannot be recorded — though the row is body-less and stops at the Form
        // Request long before that check.
        '{date}' => '2026-09-14',

        // Holidays (Phase 5). A seeded row, and a fixed-date one — Victory Day is 16 December
        // by statute, so the row it points at does not move when HolidaySeeder's lunar
        // estimates are corrected. The DELETE row overrides this with its own holiday, because
        // it consumes the one it is aimed at.
        '{holiday}' => matrixResolve('holiday:Victory Day'),

        // Leave (Phase 5). The balance row's type is Annual — a CAPPED one, because an uncapped
        // type has no balance for that URL to be about and the row would then be testing the
        // wrong refusal. The `{employee}` beside it is Tapu's, shared with the attendance rows
        // above: an Admin sets somebody else's balance, which is what the endpoint is for.
        //
        // `{leaveRequest}` has no default: every row that uses one overrides it with its own,
        // because two of the three verbs would otherwise decide the row the third is aimed at.
        '{leaveType}' => (string) LeaveType::where('name', 'Annual')->firstOrFail()->id,

        // Messages (Phase 6). The TEAM channel, which is the one conversation every role that
        // can use messaging can read — so the rows read as the capability rule and nothing
        // else, and "somebody else's conversation is 404" is asserted where it belongs, in
        // tests/Feature/Privacy/MessagePrivacyTest.php.
        '{conversation}' => matrixTeamConversationId(),

        // Tapu's USER (not his employee record, which is `{employee}`), so the direct-message
        // row states two rules in one line: anybody who may use messaging can open a DM with
        // him, and Tapu himself gets 404 — a DM with yourself is not a conversation.
        '{user}' => (string) User::where('email', 'tapu@goodtechies.test')->firstOrFail()->id,
    ];
}

/**
 * An employee record by the user's email, for the attendance rows.
 */
function matrixEmployeeId(string $email): string
{
    return (string) User::where('email', $email)->firstOrFail()->employee->id;
}

/**
 * A stopped time entry belonging to Tapu, created once and remembered.
 *
 * It is Tapu's because Tapu is the only person in the company with a timer, which is exactly
 * what the rows pointing at it are about. The existence check keeps the memo honest across a
 * rolled-back database, as matrixFileId's does.
 */
function matrixTimeEntryId(): string
{
    static $id = null;

    if ($id === null || ! TimeEntry::whereKey($id)->exists()) {
        $tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();

        $id = (string) TimeEntry::factory()
            ->forEmployee($tapu->employee)
            ->onTask(Task::where('title', MATRIX_TASK)->firstOrFail())
            ->create()->id;
    }

    return $id;
}

/** The retainer template the Recurring rows point at. */
const MATRIX_RECURRING_TEMPLATE = 'Buffalo Modular Monthly SEO — {period}';

function matrixRecurringTaskId(string $titleTemplate): string
{
    return (string) RecurringTask::where('title_template', $titleTemplate)->firstOrFail()->id;
}

/** The task the task rows point at by default. */
const MATRIX_TASK = 'Fix the duplicate canonical tags on model pages';

function matrixTaskId(string $title): string
{
    return (string) Task::where('title', $title)->firstOrFail()->id;
}

/**
 * A file row for the file routes to point at, created once per key and remembered.
 *
 * Three of them, because two rows consume the file they are aimed at and one row needs a file
 * that survives every other row: `GET /files/{file}` runs SubstituteBindings before `auth`, so
 * a consumed id would answer 404 to the guest instead of sending them to log in, and the row
 * would stop being about the signature.
 */
function matrixFileId(string $key): string
{
    static $files = [];

    // The existence check keeps the memo honest across a rolled-back database: an id cached
    // from a previous test's transaction is not a row any more, and re-using it would point
    // these rows at nothing.
    if (! isset($files[$key]) || ! File::withTrashed()->whereKey($files[$key])->exists()) {
        $tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();

        $files[$key] = (string) File::factory()
            ->forTask(Task::where('title', MATRIX_TASK)->firstOrFail())
            ->uploadedBy($tapu)
            ->create()->id;
    }

    return $files[$key];
}

/**
 * A notification for the notification rows to point at, created once and remembered.
 *
 * Notifications are not seeded — nothing in `migrate:fresh --seed` does anything a person
 * would be notified about — so this makes one, addressed to Tapu. The existence check keeps
 * the memo honest across a rolled-back database, exactly as matrixFileId's does.
 */
/**
 * The team channel, created by the Phase 6 backfill migration and therefore always there.
 */
function matrixTeamConversationId(): string
{
    return (string) Conversation::query()->where('type', 'team')->firstOrFail()->id;
}

function matrixNotificationId(): string
{
    static $id = null;

    if ($id === null || ! Notification::whereKey($id)->exists()) {
        $tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();

        $id = (string) Notification::factory()
            ->forUser($tapu)
            ->about(Task::where('title', MATRIX_TASK)->firstOrFail())
            ->create()->id;
    }

    return $id;
}

/**
 * The four dates the leave rows use, one window per row.
 *
 * They are Sundays and Mondays in October 2026 — working days on the seeded Sunday-to-Thursday
 * week — and they do not touch each other, because `leave_requests_no_overlap` refuses two
 * pending or approved requests of the same person over overlapping dates. One row approving its
 * request must not make the next row's unfileable.
 *
 * @var array<string, array{0: string, 1: string}>
 */
const MATRIX_LEAVE_WINDOWS = [
    'approve' => ['2026-10-04', '2026-10-05'],
    'reject' => ['2026-10-11', '2026-10-12'],
    'correction' => ['2026-10-18', '2026-10-19'],
    'resubmit' => ['2026-10-25', '2026-10-26'],
];

/**
 * A leave request for the rows that act on one, created once per key and remembered.
 *
 * Leave is not seeded — `LeaveSeeder` writes the six types and an opening balance per person
 * and deliberately creates no requests, so the acceptance walk starts from an empty queue — so
 * these are made here, the way the file, notification and time-entry rows make theirs. The
 * existence check keeps the memo honest across a rolled-back database.
 *
 * They are **Yaseen's**, so every one of them is a request an Admin may rule on and nobody
 * else's surface can reach: that is what the rows are about. The resubmit row's is already in
 * `correction_requested`, which is the one status its endpoint accepts — and it is written with
 * that status in the INSERT, which the model's guard allows (a row that does not exist yet has
 * no status to move away from) and which keeps the matrix from depending on a decision another
 * row happened to make first.
 */
function matrixLeaveRequestId(string $key): string
{
    static $ids = [];

    if (! isset($ids[$key]) || ! LeaveRequest::whereKey($ids[$key])->exists()) {
        $yaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();
        [$from, $to] = MATRIX_LEAVE_WINDOWS[$key] ?? MATRIX_LEAVE_WINDOWS['approve'];

        $factory = LeaveRequest::factory()
            ->forEmployee($yaseen->employee)
            ->ofType(LeaveType::where('name', 'Annual')->firstOrFail())
            ->between($from, $to);

        if ($key === 'resubmit') {
            $factory = $factory->correctionRequested(
                User::where('email', 'shahadat@goodtechies.test')->firstOrFail(),
            );
        }

        $ids[$key] = (string) $factory->create()->id;
    }

    return $ids[$key];
}

/**
 * Resolve a per-row override token to an id.
 *
 * The rows carry tokens rather than ids because permissionMatrix() is also read by the coverage
 * guard, which never touches the database — a row that queried on the way in would make the
 * guard depend on the seed.
 */
function matrixResolve(string $token): string
{
    [$kind, $value] = explode(':', $token, 2);

    return match ($kind) {
        'task' => matrixTaskId($value),
        // By title and by URL, not by position in the list: an earlier DELETE row has already
        // removed one of these by the time a later row resolves, and an index would then slide
        // onto the wrong record.
        'checklist' => (string) TaskChecklistItem::query()
            ->where('task_id', matrixTaskId(MATRIX_TASK))
            ->where('title', $value)
            ->firstOrFail()->id,
        'link' => (string) TaskLink::query()
            ->where('task_id', matrixTaskId(MATRIX_TASK))
            ->where('url', $value)
            ->firstOrFail()->id,
        'file' => matrixFileId($value),
        // By name, because the four seeded labels are global and named, and a row that
        // consumes one must not be pointed at whichever id happens to sort first.
        'tag' => (string) Tag::query()->whereNull('project_id')->where('name', $value)->firstOrFail()->id,
        // By name, for the same reason the tags are: the DELETE row consumes the row it points
        // at, and an id that happened to sort first would take a different holiday each time
        // the seeded list changed.
        'holiday' => (string) Holiday::query()->where('name', $value)->firstOrFail()->id,
        // A leave request per row that acts on one. See matrixLeaveRequestId().
        'leave' => matrixLeaveRequestId($value),
        default => $value,
    };
}

/**
 * @return list<array{0: string, 1: string, 2: array<string, int|string>, 3?: array<string, string>}>
 */
function permissionMatrix(): array
{
    $guestOnly = ['guest' => 302, 'ADMIN' => '302 /', 'MANAGER' => '302 /', 'EMPLOYEE' => '302 /', 'REMOTE_EMPLOYEE' => '302 /', 'ACCOUNTANT' => '302 /'];
    $everyone = fn (int|string $status): array => ['guest' => '302 /login', 'ADMIN' => $status, 'MANAGER' => $status, 'EMPLOYEE' => $status, 'REMOTE_EMPLOYEE' => $status, 'ACCOUNTANT' => $status];
    $admin = ['guest' => '302 /login', 'ADMIN' => 200, 'MANAGER' => 403, 'EMPLOYEE' => 403, 'REMOTE_EMPLOYEE' => 403, 'ACCOUNTANT' => 403];
    $adminAction = ['guest' => '302 /login', 'ADMIN' => 302, 'MANAGER' => 403, 'EMPLOYEE' => 403, 'REMOTE_EMPLOYEE' => 403, 'ACCOUNTANT' => 403];
    $employee = ['guest' => '302 /login', 'ADMIN' => 403, 'MANAGER' => 200, 'EMPLOYEE' => 200, 'REMOTE_EMPLOYEE' => 200, 'ACCOUNTANT' => 403];
    $accountant = ['guest' => '302 /login', 'ADMIN' => 403, 'MANAGER' => 403, 'EMPLOYEE' => 403, 'REMOTE_EMPLOYEE' => 403, 'ACCOUNTANT' => 200];
    // Everybody with a mailbox, which is everybody who holds a permission some notification
    // type requires — see NotificationPolicy.
    //
    // **In Phases 2–4 that was everybody but the Accountant; from Phase 5 it is everybody.**
    // Nothing was carved out for them: `leave.approved` / `.rejected` / `.correction_requested`
    // require `leave.apply`, which Part C §1 gives to every role, so the catalogue now contains
    // a type they can receive and `NotificationPolicy::viewAny` stops refusing them. Their
    // mailbox holds exactly those rows and no task ones, because the task types still require
    // `tasks.view` and they still hold none. Decision 2-42's follow-up — "the Accountant costs
    // one 403 /notifications/recent per page load" — closes by itself here.
    $notifications = fn (int $status): array => ['guest' => '302 /login', 'ADMIN' => $status, 'MANAGER' => $status, 'EMPLOYEE' => $status, 'REMOTE_EMPLOYEE' => $status, 'ACCOUNTANT' => $status];

    // Everybody who may use messaging, which is everybody but the ACCOUNTANT — and not
    // because anybody named them. `messages.use` is Phase 6's one new permission key
    // (Part C §1 has no messaging row at all; Phase 6's security paragraph says only that the
    // Accountant has no messaging access by default), the route group is gated on it, and the
    // matrix seed gives it to the other four roles. A future role that should have team chat
    // gets these cells by holding the key, with no edit here.
    $messaging = fn (int $status): array => ['guest' => '302 /login', 'ADMIN' => $status, 'MANAGER' => $status, 'EMPLOYEE' => $status, 'REMOTE_EMPLOYEE' => $status, 'ACCOUNTANT' => 403];

    // A DELETE row destroys the record it points at, and route-model binding runs before the
    // surface middleware — so every cell after the one that is allowed sees 404 where it would
    // otherwise have seen 403. The surface guard on these routes is asserted directly instead,
    // in tests/Feature/Admin/TaskWriteEndpointsTest.php.
    // Phase 4's timer: Tapu and nobody else. See the block of rows this pair labels.
    $timer = ['guest' => '302 /login', 'ADMIN' => 403, 'MANAGER' => 403, 'EMPLOYEE' => 403, 'REMOTE_EMPLOYEE' => 200, 'ACCOUNTANT' => 403];
    $timerAction = ['guest' => '302 /login', 'ADMIN' => 403, 'MANAGER' => 403, 'EMPLOYEE' => 403, 'REMOTE_EMPLOYEE' => 302, 'ACCOUNTANT' => 403];

    $consumed = ['guest' => '302 /login', 'ADMIN' => 302, 'MANAGER' => 404, 'EMPLOYEE' => 404, 'REMOTE_EMPLOYEE' => 404, 'ACCOUNTANT' => 404];
    $consumedByManager = ['guest' => '302 /login', 'ADMIN' => 403, 'MANAGER' => 302, 'EMPLOYEE' => 404, 'REMOTE_EMPLOYEE' => 404, 'ACCOUNTANT' => 404];

    return [
        // method, uri, expected status per role
        ['GET', '/', ['guest' => '302 /login', 'ADMIN' => '302 /admin/dashboard', 'MANAGER' => '302 /employee/dashboard', 'EMPLOYEE' => '302 /employee/dashboard', 'REMOTE_EMPLOYEE' => '302 /employee/dashboard', 'ACCOUNTANT' => '302 /accountant/dashboard']],

        // Auth (guest only)
        ['GET', 'login', ['guest' => 200] + $guestOnly],
        ['POST', 'login', $guestOnly],
        ['GET', 'two-factor/challenge', ['guest' => '302 /login'] + $guestOnly],
        ['POST', 'two-factor/challenge', $guestOnly],

        // Auth (signed in)
        ['POST', 'logout', $everyone('302 /login')],
        ['GET', 'two-factor/enrol', ['guest' => '302 /login', 'ADMIN' => '302 /', 'MANAGER' => 200, 'EMPLOYEE' => 200, 'REMOTE_EMPLOYEE' => 200, 'ACCOUNTANT' => '302 /']],
        ['POST', 'two-factor/enrol', $everyone(302)],
        ['GET', 'two-factor/recovery-codes', $everyone('302 /')],

        // Admin surface
        ['GET', 'admin/dashboard', $admin],
        ['GET', 'admin/settings', $admin],
        // An Admin's own plate. One route for all seven buckets — Due Today and Overdue are
        // `?bucket=` on this, not routes of their own, so there is one row here and not three.
        // The Accountant holds no tasks.* permission, so `viewAny` refuses them before the
        // surface middleware would have.
        ['GET', 'admin/my-tasks', $admin],

        // Admin surface — clients. A body-less mutating call stops at the validation
        // redirect, which is proof enough that it got past every gate.
        ['GET', 'admin/clients', $admin],
        ['GET', 'admin/clients/create', $admin],
        ['POST', 'admin/clients', $adminAction],
        ['GET', 'admin/clients/{client}', $admin],
        ['GET', 'admin/clients/{client}/edit', $admin],
        ['PUT', 'admin/clients/{client}', $adminAction],
        ['POST', 'admin/clients/{client}/deactivate', $adminAction],
        // The client Files tab (spec §28). A body-less POST stops at the validation redirect,
        // which is proof it got past every gate.
        ['GET', 'admin/clients/{client}/files', $admin],
        ['POST', 'admin/clients/{client}/files', $adminAction],

        // Admin surface — projects. archive and unarchive sit next to each other on purpose:
        // the Admin cell of the first is undone by the Admin cell of the second.
        ['GET', 'admin/projects', $admin],
        ['GET', 'admin/projects/create', $admin],
        ['POST', 'admin/projects', $adminAction],
        ['GET', 'admin/projects/{project}', $admin],
        ['GET', 'admin/projects/{project}/edit', $admin],
        ['PUT', 'admin/projects/{project}', $adminAction],
        ['PUT', 'admin/projects/{project}/finance', $adminAction],
        ['PUT', 'admin/projects/{project}/members', $adminAction],
        ['POST', 'admin/projects/{project}/status', $adminAction],
        ['POST', 'admin/projects/{project}/archive', $adminAction],
        ['POST', 'admin/projects/{project}/unarchive', $adminAction],
        // The project Files tab (spec §7), the same FileService as the client tab above.
        ['GET', 'admin/projects/{project}/files', $admin],
        ['POST', 'admin/projects/{project}/files', $adminAction],

        // Admin surface — the project detail page's Recurring tab (Phase 3). A retainer template
        // is an Admin object: the plan scopes these screens to this surface, and
        // RecurringTaskPolicy refuses a Manager for the same reason even though a Manager may
        // perfectly well edit the tasks the template will produce.
        //
        // Every cell here is 403 and not 404, and that is the whole shape of the rule on this
        // surface: an Admin sees every project, so there is no template that is
        // visible-to-one-Admin-and-not-another for a 404 to be about, and everybody else is
        // refused by `surface:admin` before a record is looked up at all. The 404 that DOES
        // exist — a template on a project outside the requester's scope, and an unknown id — is
        // asserted directly in tests/Feature/Admin/RecurringTaskEndpointsTest.php, where a
        // request can be made for an id that is not there.
        ['GET', 'admin/projects/{project}/recurring', $admin],
        // Body-less, so an Admin stops at the validation redirect, which is proof it got past
        // every gate.
        ['POST', 'admin/projects/{project}/recurring', $adminAction],
        // A GET with a Form Request in front of it: no rule in the query string is a
        // validation redirect, which is proof enough it got past every gate.
        ['GET', 'admin/projects/{project}/recurring/preview', $adminAction],
        // The three per-template routes. `generate` carries no Form Request, so the Admin cell
        // really does run the engine against the seeded Buffalo retainer — which is the point:
        // the button is the engine's forced entry point, not a second creation path, and a row
        // that only ever reached a validator would not have shown that.
        ['PUT', 'admin/recurring-tasks/{recurringTask}', $adminAction],
        ['POST', 'admin/recurring-tasks/{recurringTask}/generate', $adminAction],
        ['GET', 'admin/recurring-tasks/{recurringTask}/log', $admin],

        // Admin surface — tasks. A status moves through `…/status` and nowhere else: there is
        // no second endpoint here for the board drag to use, which is the point.
        ['GET', 'admin/tasks', $admin],
        // The Board and the Calendar are routes, not a `?view=` on the index — which is what
        // gives each of them a row of its own here instead of a surface hiding behind another
        // route's cells. The Accountant holds no tasks.* permission, so viewAny refuses them
        // even before the surface middleware would.
        ['GET', 'admin/tasks/board', $admin],
        ['GET', 'admin/tasks/calendar', $admin],
        ['POST', 'admin/tasks', $adminAction],
        ['GET', 'admin/tasks/{task}', $admin],
        ['PUT', 'admin/tasks/{task}', $adminAction],
        ['POST', 'admin/tasks/{task}/status', $adminAction],
        ['POST', 'admin/tasks/{task}/reorder', $adminAction],
        ['PUT', 'admin/tasks/{task}/assignees', $adminAction],
        ['POST', 'admin/tasks/{task}/handoff', $adminAction],
        ['POST', 'admin/tasks/{task}/checklist', $adminAction],
        ['PUT', 'admin/tasks/{task}/checklist/{item}', $adminAction, ['{item}' => 'checklist:List every page with a wrong canonical']],
        ['DELETE', 'admin/tasks/{task}/checklist/{item}', $consumed, ['{item}' => 'checklist:Point each model page at itself']],
        ['POST', 'admin/tasks/{task}/links', $adminAction],
        ['DELETE', 'admin/tasks/{task}/links/{link}', $consumed, ['{link}' => 'link:https://search.google.com/search-console']],
        ['POST', 'admin/tasks/{task}/dependencies', $adminAction],
        // Detaching a dependency that is not there is a no-op, which is all this row needs to
        // show it got past the gates — so it consumes nothing and keeps its 403s.
        ['DELETE', 'admin/tasks/{task}/dependencies/{dependency}', $adminAction, ['{dependency}' => 'task:Build the internal link map for the county pages']],
        // Adjacent on purpose, like the project pair above: the Admin cell of the first is
        // undone by the Admin cell of the second, so the rows after these see a live task.
        ['POST', 'admin/tasks/{task}/archive', $adminAction],
        ['POST', 'admin/tasks/{task}/unarchive', $adminAction],
        // Attachments on a task.
        ['GET', 'admin/tasks/{task}/files', $admin],
        ['POST', 'admin/tasks/{task}/files', $adminAction],
        // Soft delete, on its own task, needed by no row after it.
        ['DELETE', 'admin/tasks/{task}', $consumed, ['{task}' => 'task:Write the 404 and maintenance pages']],

        // Admin surface — the task discussion. The GET has no Form Request in front of it, so
        // an Admin gets the thread; the POST has one, so a body-less call stops at the
        // validation redirect, which is proof it got past every gate.
        ['GET', 'admin/tasks/{task}/discussion', $admin],
        ['POST', 'admin/tasks/{task}/discussion', $adminAction],

        // Admin surface — tag management. Creating, renaming and removing a label is
        // Admin/Manager; a Manager reaches it on the Employee surface, below.
        ['GET', 'admin/tags', $admin],
        ['POST', 'admin/tags', $adminAction],
        // `update` sends no body here, so it stops at validation for everybody who reaches the
        // surface rather than at the policy. The policy's answer is asserted directly in
        // tests/Feature/Admin/TagEndpointsTest.php.
        ['PUT', 'admin/tags/{tag}', $adminAction, ['{tag}' => 'tag:Development']],
        // Its own tag, because it destroys the one it points at — and a global one, so no
        // other row's picker loses an option it was counting on.
        ['DELETE', 'admin/tags/{tag}', $consumed, ['{tag}' => 'tag:Maintenance']],

        // Admin surface — Workforce → Attendance and Work Schedule (Phase 4).
        //
        // Every cell here is 403 and not 404, and that is the shape of the rule on this
        // surface: reading the agency's morning and rewriting somebody's pay record are Admin
        // acts, so everybody else is stopped by `surface:admin` before a record is looked up.
        // The 404 that DOES exist — an employee outside the requester's scope, and an unknown
        // id — is asserted directly in tests/Feature/Attendance/AttendanceEndpointsTest.php,
        // and its *employee-facing* half is the `attendance/{employee?}` row below.
        //
        // The month grid of one employee is not here because it is not an Admin route: it is
        // the shared `GET /attendance/{employee}`, one screen for the person and the Admin.
        ['GET', 'admin/attendance', $admin],
        // Body-less, so an Admin stops at the Form Request's missing `status` and `note` —
        // which is proof it got past every gate, and proof the reason is required.
        ['PUT', 'admin/attendance/{employee}/{date}', $adminAction],
        ['GET', 'admin/schedules', $admin],
        ['PUT', 'admin/schedules/{employee}', $adminAction],

        // Admin surface — Workforce → Leave → Holidays (Phase 5). The company calendar.
        //
        // **Every cell here is 403 and not one is 404**, and unlike the Recurring block above
        // that is not a fact about this surface — it is a fact about the record. A holiday has
        // no employee, no project and no scope: it is the same fact for everybody signed in, so
        // there is no holiday that is present for one reader and absent for another and Part C's
        // absence rule has nothing to be about. The only 404 these routes can produce is an id
        // that is not in the table, which is route-model binding and is asserted directly in
        // tests/Feature/Workforce/HolidayEndpointsTest.php.
        //
        // Two gates agree on the 403s: `surface:admin` stops the other shells, and
        // `can:settings.manage` sits behind it — so widening the surface one day would not
        // quietly hand somebody the power to shut the agency for a day. The MANAGER cell is the
        // one worth reading twice: a Manager may approve their own team's leave, and still may
        // not put a day on the company calendar (HolidayPolicy says why).
        ['GET', 'admin/holidays', $admin],
        // Body-less, so an Admin stops at the Form Request's missing `date` and `name` — proof
        // it got past every gate.
        ['POST', 'admin/holidays', $adminAction],
        // Same, and it points at a seeded fixed-date holiday, so it consumes nothing and the
        // DELETE row below still has its own to take.
        ['PUT', 'admin/holidays/{holiday}', $adminAction],
        // Its own holiday, because it destroys the one it is pointed at. Christmas Day is
        // seeded, fixed by statute, and nothing else in the matrix points at it.
        ['DELETE', 'admin/holidays/{holiday}', $consumed, ['{holiday}' => 'holiday:Christmas Day']],

        // Admin surface — Workforce → Leave (Phase 5): the queue, the calendar and the
        // balances grid.
        //
        // **Every cell but the Admin's is 403**, and that is the shape of the rule on this
        // surface: reading the agency's leave, ruling on it and setting somebody's days are
        // Admin acts, so `surface:admin` stops the other shells before a record is looked up
        // and `LeaveRequestPolicy::review` / `::decide` / `::manageBalances` sit behind it —
        // widening the surface one day would not quietly hand somebody the power to approve
        // leave. The MANAGER cell is the one worth reading twice: a Manager holds
        // `leave.approve` and would rule on their own team, and they still get 403 here,
        // because this queue is on the Admin shell and a Manager lands on the Employee one
        // (decision 0-5).
        //
        // The **404** half — a request or an employee outside the requester's scope — cannot be
        // shown here, because an Admin sees every request and everybody else is refused by the
        // surface first. It is asserted directly in tests/Feature/Leave/LeaveEndpointsTest.php,
        // the same way decision 3-8 handles it for a recurring template.
        ['GET', 'admin/leave', $admin],
        ['GET', 'admin/leave/calendar', $admin],
        ['GET', 'admin/leave/balances', $admin],
        // Body-less, so an Admin stops at the Form Request's missing `balance_days` and
        // `reason` — proof it got past every gate, and proof the reason is required.
        ['PUT', 'admin/leave/balances/{employee}/{leaveType}', $adminAction],

        // The three verbs, each pointed at its OWN request, because two of them would otherwise
        // decide the row the third is aimed at — and because the requests may not overlap each
        // other's dates (`leave_requests_no_overlap`), so they are three separate windows.
        //
        // `approve` carries no required body, so the Admin cell really does approve: it spends
        // two days of Yaseen's Annual balance, writes the Leave attendance rows and fires the
        // notification. That is the point — the row proves the whole side effect runs through
        // this endpoint, not just that a validator was reached. `reject` and `correction`
        // require a reason, so their Admin cells stop at the validation redirect, which is
        // equally proof they got past every gate and proof a refusal cannot be made silently.
        ['POST', 'admin/leave/{leaveRequest}/approve', $adminAction, ['{leaveRequest}' => 'leave:approve']],
        ['POST', 'admin/leave/{leaveRequest}/reject', $adminAction, ['{leaveRequest}' => 'leave:reject']],
        ['POST', 'admin/leave/{leaveRequest}/correction', $adminAction, ['{leaveRequest}' => 'leave:correction']],

        // Admin surface — Workforce → Time (Phase 4): the approval queue, and hours today and
        // this week. Decision 4-16's answer.
        //
        // Every cell but the Admin's is 403, from two gates that agree: `surface:admin` stops
        // the other shells before anything is resolved, and `TimeEntryPolicy::review` /
        // `::approve` sit behind it, so widening the surface one day would not quietly hand
        // somebody the power to sign off other people's hours. Tapu's 403 is the one worth
        // reading twice — the entry these rows point at is HIS, and he still may not rule on
        // it, which is the self-approval rule the policy states and
        // tests/Feature/Admin/TimeApprovalTest.php asserts directly.
        //
        // The 404 half — an entry id that does not exist, or one outside the requester's scope
        // — is asserted in that same file, because every role that could show it here is
        // refused by the surface first.
        ['GET', 'admin/time', $admin],
        // These two run in order against ONE entry, and that is deliberate rather than
        // incidental: the Admin's approve lands (302), and the Admin's reject then arrives
        // body-less and stops at the Form Request's missing `reason` — also 302, and proof both
        // that it got past every gate and that a refusal cannot be made without a sentence.
        ['POST', 'admin/time/entries/{timeEntry}/approve', $adminAction],
        ['POST', 'admin/time/entries/{timeEntry}/reject', $adminAction],

        // Admin surface — Workforce → Timesheet and Workload (Phase 4).
        //
        // Both are reads and both are 403 for everybody but the Admin, and for two different
        // reasons that happen to agree: `surface:admin` stops the other roles before anything
        // is looked up, and the Timesheet carries `can:attendance.manage_others` behind that,
        // so widening the surface one day would not quietly open somebody's hours.
        //
        // The Timesheet row points at Tapu's employee record, which is the whole point of the
        // parameter: the Admin reads somebody else's week. The 404 half — an id outside the
        // requester's scope, and an employee asking for a colleague's — is asserted directly
        // in tests/Feature/Workforce/TimesheetTest.php, because every role that could show it
        // here is refused by the surface first.
        ['GET', 'admin/timesheet/{employee?}', $admin],
        // No parameter at all: the agency's own counts, so there is no record to be absent and
        // no 404 to have. An employee the viewer may not see is missing from the list.
        ['GET', 'admin/workload', $admin],

        // Admin surface — one file. The history GET has no Form Request in front of it, so it
        // reads the visibility rule out loud: an Admin sees the chain of a file on any task,
        // and everybody else is stopped by the surface before the question arises. It consumes
        // nothing, so it can point at the same default file the two rows below do.
        ['GET', 'admin/files/{file}/versions', $admin],
        // `versions` takes a body, so nobody who reaches the surface
        // gets past the Form Request without one and the row consumes nothing.
        ['POST', 'admin/files/{file}/versions', $adminAction],
        // Its own file, because it destroys the one it is pointed at.
        ['DELETE', 'admin/files/{file}', $consumed, ['{file}' => 'file:admin-delete']],

        // Employee surface
        ['GET', 'employee/dashboard', $employee],
        // The same one-route-seven-buckets page on this surface. A 200 for everyone who may
        // reach the surface: a plate with nothing on it is still a plate, so an employee with
        // no tasks gets the page and seven zeroes, not a refusal.
        ['GET', 'employee/my-tasks', $employee],
        ['GET', 'employee/projects', $employee],
        // Tapu (REMOTE_EMPLOYEE) is on this project and Yaseen (EMPLOYEE) is not: a project
        // an employee is not on is missing, not forbidden.
        ['GET', 'employee/projects/{project}', ['guest' => '302 /login', 'ADMIN' => 403, 'MANAGER' => 200, 'EMPLOYEE' => 404, 'REMOTE_EMPLOYEE' => 200, 'ACCOUNTANT' => 403]],
        // The task list is a list: every role that may reach the surface gets a 200 and sees
        // its own rows. Which rows those are is Task::visibleTo()'s business, tested in
        // tests/Feature/Privacy/TaskPrivacyTest.php, not a status code here.
        ['GET', 'employee/tasks', $employee],
        // Same for the employee pair. Yaseen is assigned nothing on the seed, so his board is
        // eight empty columns and his calendar an empty month — a 200 either way, because an
        // empty view is not a refusal.
        ['GET', 'employee/tasks/board', $employee],
        ['GET', 'employee/tasks/calendar', $employee],

        // Employee surface — one task. The Manager lives on THIS surface, so the moves the plan
        // gives to ADMIN/MANAGER are routed here too and refused to an employee by TaskPolicy.
        //
        // Two things shape the cells below:
        //   - a task an employee is not assigned to is ABSENT: Yaseen gets 404, not 403;
        //   - a Form Request runs before the controller, so on a row whose body is required
        //     everybody who reaches the surface stops at the same validation redirect —
        //     including Yaseen, who would otherwise have got a 404 one line later.
        ['GET', 'employee/tasks/{task}', ['guest' => '302 /login', 'ADMIN' => 403, 'MANAGER' => 200, 'EMPLOYEE' => 404, 'REMOTE_EMPLOYEE' => 200, 'ACCOUNTANT' => 403]],
        ['PUT', 'employee/tasks/{task}', ['guest' => '302 /login', 'ADMIN' => 403, 'MANAGER' => 302, 'EMPLOYEE' => 404, 'REMOTE_EMPLOYEE' => 302, 'ACCOUNTANT' => 403]],
        // `status` is required, so nobody gets past validation without a body.
        ['POST', 'employee/tasks/{task}/status', ['guest' => '302 /login', 'ADMIN' => 403, 'MANAGER' => 302, 'EMPLOYEE' => 302, 'REMOTE_EMPLOYEE' => 302, 'ACCOUNTANT' => 403]],
        ['POST', 'employee/tasks/{task}/reorder', ['guest' => '302 /login', 'ADMIN' => 403, 'MANAGER' => 302, 'EMPLOYEE' => 404, 'REMOTE_EMPLOYEE' => 302, 'ACCOUNTANT' => 403]],
        ['POST', 'employee/tasks/{task}/handoff', ['guest' => '302 /login', 'ADMIN' => 403, 'MANAGER' => 302, 'EMPLOYEE' => 302, 'REMOTE_EMPLOYEE' => 302, 'ACCOUNTANT' => 403]],
        ['POST', 'employee/tasks/{task}/checklist', ['guest' => '302 /login', 'ADMIN' => 403, 'MANAGER' => 302, 'EMPLOYEE' => 302, 'REMOTE_EMPLOYEE' => 302, 'ACCOUNTANT' => 403]],
        ['PUT', 'employee/tasks/{task}/checklist/{item}', ['guest' => '302 /login', 'ADMIN' => 403, 'MANAGER' => 302, 'EMPLOYEE' => 404, 'REMOTE_EMPLOYEE' => 302, 'ACCOUNTANT' => 403], ['{item}' => 'checklist:Re-crawl and confirm the canonicals resolved']],
        ['DELETE', 'employee/tasks/{task}/checklist/{item}', $consumedByManager, ['{item}' => 'checklist:Ask Google to re-index the eight pages']],
        ['POST', 'employee/tasks/{task}/links', ['guest' => '302 /login', 'ADMIN' => 403, 'MANAGER' => 302, 'EMPLOYEE' => 302, 'REMOTE_EMPLOYEE' => 302, 'ACCOUNTANT' => 403]],
        ['DELETE', 'employee/tasks/{task}/links/{link}', $consumedByManager, ['{link}' => 'link:https://buffalomodular.com/sitemap.xml']],
        // Archive is ADMIN/MANAGER: an assignee reaches the task and is still refused, which is
        // a 403 about their role and not a 404 about the record.
        ['POST', 'employee/tasks/{task}/archive', ['guest' => '302 /login', 'ADMIN' => 403, 'MANAGER' => 302, 'EMPLOYEE' => 404, 'REMOTE_EMPLOYEE' => 403, 'ACCOUNTANT' => 403]],
        // Attachments. The GET has no Form Request in front of it, so Yaseen's 404 survives to
        // be seen; the POST has one, so everybody who reaches the surface stops at the same
        // validation redirect.
        ['GET', 'employee/tasks/{task}/files', ['guest' => '302 /login', 'ADMIN' => 403, 'MANAGER' => 200, 'EMPLOYEE' => 404, 'REMOTE_EMPLOYEE' => 200, 'ACCOUNTANT' => 403]],
        ['POST', 'employee/tasks/{task}/files', ['guest' => '302 /login', 'ADMIN' => 403, 'MANAGER' => 302, 'EMPLOYEE' => 302, 'REMOTE_EMPLOYEE' => 302, 'ACCOUNTANT' => 403]],
        // The discussion. The GET has no Form Request, so Yaseen's 404 survives to be seen —
        // which is the whole privacy rule for this slice, read straight off a row: an employee
        // sees the discussion only of tasks they are assigned to, and one they are not on is
        // ABSENT rather than refused. The POST has a Form Request (a message needs a body or a
        // file), so everybody who reaches the surface stops at the same validation redirect.
        ['GET', 'employee/tasks/{task}/discussion', ['guest' => '302 /login', 'ADMIN' => 403, 'MANAGER' => 200, 'EMPLOYEE' => 404, 'REMOTE_EMPLOYEE' => 200, 'ACCOUNTANT' => 403]],
        ['POST', 'employee/tasks/{task}/discussion', ['guest' => '302 /login', 'ADMIN' => 403, 'MANAGER' => 302, 'EMPLOYEE' => 302, 'REMOTE_EMPLOYEE' => 302, 'ACCOUNTANT' => 403]],

        // Delete is ADMIN/MANAGER too, and a Manager can only reach it here. Its own task.
        ['DELETE', 'employee/tasks/{task}', $consumedByManager, ['{task}' => 'task:Refresh the agency case-study deck']],

        // Employee surface — the remote timer and the Time page (Phase 4).
        //
        // One cell shape runs through all ten rows and it is the phase's whole privacy rule:
        // **only a REMOTE_EMPLOYEE may time, and every office role gets 403.** Not a 404, and
        // not a disabled button — `TimeEntryPolicy::track` asks for the `timer.use` key AND
        // `tracking_mode = remote_timer`, and a route a role may not use is Part C §1's 403.
        //
        //   ADMIN / ACCOUNTANT  403 — stopped by `surface:employee` before the policy is asked
        //   MANAGER / EMPLOYEE  403 — on the surface, refused by the policy: no timer.use key,
        //                             and office_attendance rather than remote_timer
        //   REMOTE_EMPLOYEE     200 / 302 — Tapu, the one person in the company with a timer
        //
        // The refusal lands BEFORE validation on every row that has a Form Request, because
        // `authorize()` runs first — see `TimerRequest`. Without that these rows would read 302
        // for everybody on the surface and the 403 the plan asks for would be untested.
        //
        // The 404 half of the rule — another employee's entry — cannot be shown here, because
        // the matrix's entry belongs to Tapu and every other role is refused before the lookup.
        // It is asserted directly in tests/Feature/Employee/TimeEndpointsTest.php.
        ['GET', 'employee/time', $timer],
        // JSON, and 200 for Tapu whether or not a timer is going: "nothing is running" is an
        // answer, not an error.
        ['GET', 'employee/time/current', $timer],
        // `task_id` and `client_uuid` are required, so Tapu stops at the validation redirect —
        // which is proof it got past the gate that refused everybody else.
        ['POST', 'employee/time/start', $timerAction],
        // No body at all on these three: Tapu reaches the controller, has no timer going, and
        // is told so in a flash. A 302 either way, and the row is about the gate.
        ['POST', 'employee/time/pause', $timerAction],
        ['POST', 'employee/time/resume', $timerAction],
        ['POST', 'employee/time/stop', $timerAction],
        // A ping for a session that is not there is not an error — it is how the browser finds
        // out the watchdog stopped it. 200 with `running: null`.
        ['POST', 'employee/time/heartbeat', $timer],
        ['POST', 'employee/time/replay', $timerAction],
        ['POST', 'employee/time/entries', $timerAction],
        // Tapu's own entry. Every field is required, so his cell is the validation redirect and
        // the other cells are the gate.
        ['PUT', 'employee/time/entries/{timeEntry}', $timerAction],

        // Employee surface — the weekly timesheet (Phase 4). The same cell shape as the ten
        // timer rows above, and for the same reason: `TimeEntryPolicy::viewAny` wants
        // `timer.use` AND `tracking_mode = remote_timer`, so only Tapu has a week here and
        // every office role is refused about WHO THEY ARE — a 403, not an empty grid.
        //
        // The parameter is Tapu's own employee record, so this row is "he reads his own week".
        // The other half of Part C's rule, a colleague's week answering 404 rather than 403,
        // cannot be shown here — every role that would ask is stopped by the policy first — so
        // it is asserted directly in tests/Feature/Workforce/TimesheetTest.php.
        ['GET', 'employee/timesheet/{employee?}', $timer],

        // Employee surface — tag management, which is here because this is where a MANAGER is.
        // The GET and the DELETE carry no body, so both read the policy out loud: the Manager
        // manages tags and an employee does not, as a 403 about their role rather than a 404
        // about a record they can perfectly well see the name of.
        ['GET', 'employee/tags', ['guest' => '302 /login', 'ADMIN' => 403, 'MANAGER' => 200, 'EMPLOYEE' => 403, 'REMOTE_EMPLOYEE' => 403, 'ACCOUNTANT' => 403]],
        ['POST', 'employee/tags', ['guest' => '302 /login', 'ADMIN' => 403, 'MANAGER' => 302, 'EMPLOYEE' => 302, 'REMOTE_EMPLOYEE' => 302, 'ACCOUNTANT' => 403]],
        // Every field on the update is optional, so an empty body passes validation and the
        // request reaches the policy — which is why this row shows the refusal that the POST
        // above hides behind its Form Request.
        ['PUT', 'employee/tags/{tag}', ['guest' => '302 /login', 'ADMIN' => 403, 'MANAGER' => 302, 'EMPLOYEE' => 403, 'REMOTE_EMPLOYEE' => 403, 'ACCOUNTANT' => 403], ['{tag}' => 'tag:Branding']],
        // Its own tag: the Manager cell consumes it, so every cell after sees 404.
        ['DELETE', 'employee/tags/{tag}', $consumedByManager, ['{tag}' => 'tag:SEO']],

        // Employee surface — one file. The history GET carries no body, so Yaseen's 404
        // survives to be seen and the row states the privacy rule the endpoint exists to keep:
        // the file is on Tapu's task, so the Manager and Tapu read its chain and Yaseen — who
        // is not on that task — is told it is ABSENT, not refused. Its version count is behind
        // the same 404. It must stay ABOVE the DELETE row, which consumes this file.
        ['GET', 'employee/files/{file}/versions', ['guest' => '302 /login', 'ADMIN' => 403, 'MANAGER' => 200, 'EMPLOYEE' => 404, 'REMOTE_EMPLOYEE' => 200, 'ACCOUNTANT' => 403]],
        // A body is required, so this stops at validation for
        // everybody on the surface and consumes nothing.
        ['POST', 'employee/files/{file}/versions', ['guest' => '302 /login', 'ADMIN' => 403, 'MANAGER' => 302, 'EMPLOYEE' => 302, 'REMOTE_EMPLOYEE' => 302, 'ACCOUNTANT' => 403]],
        // The delete rule, read straight off the row. The file is on Tapu's task and Tapu
        // uploaded it, so:
        //   MANAGER          403 — sees the task, may edit it, and it is not their upload
        //   EMPLOYEE         404 — Yaseen is not on the task, so the file is absent, not refused
        //   REMOTE_EMPLOYEE  302 — Tapu is the uploader, and this is the cell that consumes it
        //   ACCOUNTANT       404 — the file is gone by the time the last cell runs
        ['DELETE', 'employee/files/{file}', ['guest' => '302 /login', 'ADMIN' => 403, 'MANAGER' => 403, 'EMPLOYEE' => 404, 'REMOTE_EMPLOYEE' => 302, 'ACCOUNTANT' => 404]],

        // Accountant surface
        ['GET', 'accountant/dashboard', $accountant],

        // Shared profile
        ['GET', 'profile', $everyone(200)],
        ['PUT', 'profile', $everyone(302)],
        ['PUT', 'profile/password', $everyone(302)],
        ['DELETE', 'profile/two-factor', $everyone(302)],
        ['POST', 'profile/two-factor/recovery-codes', $everyone(302)],
        ['DELETE', 'profile/sessions/{session}', $everyone(404)],

        // Shared — somebody's attendance, and the clock (Phase 4). No surface, like the bell
        // and the file download below: clocking in is a fact about the person and not about
        // the shell, and Part D §8's office employees include BOTH Admins.
        //
        // This row is the one that states Part C's record rule out loud. It points at TAPU's
        // employee record, so:
        //   ADMIN             200 — an Admin sees everybody's month
        //   MANAGER           404 — the factory Manager has nobody on their team, so Tapu's
        //                           attendance is ABSENT to them, not refused
        //   EMPLOYEE          404 — Yaseen asking for a colleague's month is told it is not
        //                           there, and never learns whether the id exists
        //   REMOTE_EMPLOYEE   200 — it is Tapu's own
        //   ACCOUNTANT        404 — holds `attendance.view_own` and nothing else, so their own
        //                           month is all there is, and this is not it
        ['GET', 'attendance/{employee?}', ['guest' => '302 /login', 'ADMIN' => 200, 'MANAGER' => 404, 'EMPLOYEE' => 404, 'REMOTE_EMPLOYEE' => 200, 'ACCOUNTANT' => 404]],
        // The clock. 403 for the two roles the office clock does not track — Tapu, whose work
        // is the timer's, and the Accountant, whose work is tracked by neither — and that is
        // `AttendanceRecordPolicy::clock` reading `tracking_mode`, never a role name. Everybody
        // else gets a 302: a refusal from the day's own state (already clocked in, not a
        // working day) is a flash on the page they came from, not a status code, because the
        // person holding the phone at the door needs a sentence.
        ['POST', 'attendance/clock-in', ['guest' => '302 /login', 'ADMIN' => 302, 'MANAGER' => 302, 'EMPLOYEE' => 302, 'REMOTE_EMPLOYEE' => 403, 'ACCOUNTANT' => 403]],
        ['POST', 'attendance/clock-out', ['guest' => '302 /login', 'ADMIN' => 302, 'MANAGER' => 302, 'EMPLOYEE' => 302, 'REMOTE_EMPLOYEE' => 403, 'ACCOUNTANT' => 403]],

        // Shared — My Leave, and applying for it (Phase 5). No surface, like the clock above
        // and the bell below: applying for leave is a fact about the person and not about the
        // shell they are looking at, and Part C §1 gives that cell to **every** role.
        //
        // **The ACCOUNTANT cell is a 200, and it is the whole point of these three rows.** It
        // is the first thing the Accountant surface does beyond finance — Part C §1 says so
        // under its own matrix — and this is where it is proved rather than argued. They reach
        // the same route an Admin does and the page picks `AccountantLayout` from their surface.
        ['GET', 'leave', $everyone(200)],
        // Body-less, so everybody stops at the Form Request's missing type, dates and reason:
        // proof they all got past the gate, and proof a request cannot be filed with nothing in
        // it. That the refusals for overlap and balance are flashes rather than status codes is
        // asserted in tests/Feature/Leave/LeaveEndpointsTest.php.
        ['POST', 'leave', $everyone(302)],
        // Resubmitting after a correction. `ApplyLeaveRequest` runs before the controller, so a
        // body-less PUT stops at validation for everybody — including the roles that would have
        // got a 404 one line later. The 404 for somebody else's request, and the refusal to
        // resubmit one that was not sent back, are asserted directly in that same file.
        ['PUT', 'leave/{leaveRequest}', $everyone(302), ['{leaveRequest}' => 'leave:resubmit']],

        // Shared — the bell and the Notification Center. No surface, like the file download
        // above: a person's own mail is a fact about the person, not about the shell they are
        // looking at, so all four roles that have a mailbox reach the same four routes.
        //
        // The ACCOUNTANT cell is 403 on every one of them, and it is not a rule about
        // Accountants. `can:viewAny` asks whether this person could receive any kind of
        // notification at all, and every Phase 2 notification type requires `tasks.view`;
        // they hold no tasks.* key, exactly as they hold none in the task rows above.
        // Messages (Phase 6, master prompt Part D §10). One row per messaging route, and every
        // one of them says the same thing about the Accountant: **403, on every route, because
        // they hold no `messages.use`.** That is the spec's "Accountant has no messaging
        // routes" — a permission on the route group, never a role named anywhere. It is the
        // same shape `can:viewAny` gave the notification rows in Phases 2–4.
        //
        // `{conversation}` is the team channel, which everybody else can read, so these rows
        // are about the capability. Who can read a PROJECT channel or somebody else's DM is a
        // 404-shaped question and is asserted in tests/Feature/Privacy/MessagePrivacyTest.php.
        ['GET', 'messages', $messaging(200)],
        ['GET', 'messages/{conversation}', $messaging(200)],
        // Body-less, so it stops at the validation redirect — which is proof it got past every
        // gate. Whether an ordinary member may post in the ANNOUNCEMENTS channel is a policy
        // question, not a route one, and MessageEndpointsTest asserts it directly.
        ['POST', 'messages/{conversation}', $messaging(302)],
        ['POST', 'messages/{conversation}/read', $messaging(302)],
        // Opening a DM with Tapu. Everybody who may use messaging gets one — and Tapu gets
        // **404**, because a DM with yourself is not a conversation and the endpoint says so
        // the way this application says no to a record: by failing to find it.
        ['POST', 'messages/direct/{user}', [
            'guest' => '302 /login', 'ADMIN' => 302, 'MANAGER' => 302, 'EMPLOYEE' => 302,
            'REMOTE_EMPLOYEE' => 404, 'ACCOUNTANT' => 403,
        ]],

        ['GET', 'notifications', $notifications(200)],
        ['GET', 'notifications/recent', $notifications(200)],
        ['POST', 'notifications/read-all', $notifications(302)],
        // The one row that states the other half of the rule. The notification belongs to
        // Tapu, so:
        //   ADMIN / MANAGER / EMPLOYEE  404 — somebody else's mail is ABSENT, not refused,
        //                                     even to an Admin who can see the whole agency
        //   REMOTE_EMPLOYEE             302 — it is Tapu's, and marking it read is idempotent
        //   ACCOUNTANT                  404 — since Phase 5 they have a mailbox of their own
        //                                     (leave), so they reach the route and are told the
        //                                     row is ABSENT rather than refused at the gate.
        //                                     The cell moved from 403 to 404 and that is the
        //                                     privacy rule getting *stronger*: they now learn
        //                                     nothing about whether the id exists, which is
        //                                     exactly what the three cells above it say.
        ['POST', 'notifications/{notification}/read', ['guest' => '302 /login', 'ADMIN' => 404, 'MANAGER' => 404, 'EMPLOYEE' => 404, 'REMOTE_EMPLOYEE' => 302, 'ACCOUNTANT' => 404]],

        // The Team directory (Phase 6). Shared, like the Messages page it sits next to, and
        // gated by the same `messages.use` — so "the Accountant has no messaging routes" is one
        // key refusing them here exactly as it refuses them there, with no role named. The
        // MANAGER cell is 200 and not 403: they hold the key, and the row would otherwise be a
        // role test wearing a permission's clothes.
        ['GET', 'team', ['guest' => '302 /login', 'ADMIN' => 200, 'MANAGER' => 200, 'EMPLOYEE' => 200, 'REMOTE_EMPLOYEE' => 200, 'ACCOUNTANT' => 403]],

        // Broadcast authorisation (Phase 6). Laravel's own route, registered by
        // `withBroadcasting()` in bootstrap/app.php with this application's full signed-in
        // middleware stack — `web`, `auth`, `active`, `two-factor` — because a socket is a
        // second door into the same rooms.
        //
        // **These cells do not test channel authorisation, and cannot.** `phpunit.xml` pins
        // `BROADCAST_CONNECTION=null`, and Laravel's `null` and `log` broadcasters both
        // override `auth()` with an empty body: they authorise nothing and answer an empty 200
        // to anybody signed in, whatever channel is named. The rows record that truthfully.
        // The real answers — 403 for a non-member on each of the three channels — are asserted
        // in tests/Feature/Realtime/BroadcastAuthTest.php, which configures the `reverb`
        // connection first and says why in its header.
        //
        // What these rows DO assert is the door: a guest is sent to the login page and never
        // reaches the controller, which is the thing that would be a hole.
        ['GET', 'broadcasting/auth', $everyone(200)],
        ['POST', 'broadcasting/auth', $everyone(200)],

        // Downloading a file: one route, no surface, its own file so that no earlier row has
        // consumed it. The matrix sends no signature, and that is what this row asserts — every
        // signed-in role is refused, whatever they could otherwise see, because the link has to
        // be minted by FileService. Who may use a VALID link is a policy question and is
        // answered in tests/Feature/Privacy/FilePrivacyTest.php, where an employee holding a
        // perfectly good link to a task they are not on gets a 404.
        ['GET', 'files/{file}', ['guest' => '302 /login', 'ADMIN' => 403, 'MANAGER' => 403, 'EMPLOYEE' => 403, 'REMOTE_EMPLOYEE' => 403, 'ACCOUNTANT' => 403], ['{file}' => 'file:download']],
    ];
}

/**
 * @return array<string, User|null>
 */
function matrixUsers(): array
{
    $seeded = fn (string $email): User => User::where('email', $email)->firstOrFail();

    return [
        'guest' => null,
        'ADMIN' => $seeded('shahadat@goodtechies.test'),
        'MANAGER' => Employee::factory()->forRole(RoleName::MANAGER)->create()->user,
        'EMPLOYEE' => $seeded('yaseen@goodtechies.test'),
        'REMOTE_EMPLOYEE' => $seeded('tapu@goodtechies.test'),
        'ACCOUNTANT' => $seeded('accountant@goodtechies.test'),
    ];
}

function matrixKey(string $method, string $uri): string
{
    return strtoupper($method).' '.(trim($uri, '/') === '' ? '/' : trim($uri, '/'));
}

it('enforces the role × route matrix', function () {
    $this->seed();
    $users = matrixUsers();
    $parameters = matrixParameters();
    $mismatches = [];

    foreach (permissionMatrix() as $row) {
        [$method, $uri, $expectations] = $row;
        // A fourth element overrides a parameter for this row only. A route appears in the
        // matrix exactly once, so a destructive row needs its own record to consume rather
        // than the one every other row of the same route is pointed at.
        $overrides = array_map(matrixResolve(...), $row[3] ?? []);

        expect(array_keys($expectations))->toEqualCanonicalizing(MATRIX_ROLES);

        $path = '/'.ltrim(strtr($uri, $overrides + $parameters), '/');

        foreach (MATRIX_ROLES as $role) {
            // Every cell starts from a clean guard, session and rate limiter
            // (the login and two-factor limiters would otherwise count all six cells).
            $this->app['auth']->forgetGuards();
            $this->flushSession();
            Cache::flush();

            if ($users[$role] !== null) {
                $this->actingAs($users[$role]->fresh());
            }

            $response = $this->call($method, $path);

            [$status, $location] = array_pad(explode(' ', (string) $expectations[$role], 2), 2, null);
            $actual = $response->getStatusCode();
            $actualLocation = $response->headers->get('Location');

            if ($actual !== (int) $status || ($location !== null && $actualLocation !== url($location))) {
                $mismatches[] = sprintf(
                    '%s %s as %s: expected %s, got %d%s',
                    $method,
                    $path,
                    $role,
                    $expectations[$role],
                    $actual,
                    $actualLocation !== null ? " → {$actualLocation}" : '',
                );
            }
        }
    }

    expect($mismatches)->toBe([]);
})->group('phase0', 'permissions');

it('has a matrix row for every registered route', function () {
    $registered = collect(Route::getRoutes()->getRoutes())
        ->reject(fn (RoutingRoute $route): bool => Str::is(MATRIX_IGNORED_ROUTES, $route->uri()))
        ->flatMap(fn (RoutingRoute $route): array => array_map(
            fn (string $method): string => matrixKey($method, $route->uri()),
            array_diff($route->methods(), ['HEAD']),
        ))
        ->unique()
        ->sort()
        ->values()
        ->all();

    $covered = collect(permissionMatrix())
        ->map(fn (array $row): string => matrixKey($row[0], $row[1]))
        ->sort()
        ->values()
        ->all();

    expect(array_values(array_diff($registered, $covered)))->toBe([], 'Routes missing from the permission matrix')
        ->and(array_values(array_diff($covered, $registered)))->toBe([], 'Matrix rows without a registered route')
        ->and(count($covered))->toBe(count(array_unique($covered)), 'Duplicate matrix rows');
})->group('phase0', 'permissions');
