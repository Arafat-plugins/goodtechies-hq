<?php

use App\Models\Client;
use App\Models\Employee;
use App\Models\File;
use App\Models\Notification;
use App\Models\Project;
use App\Models\RecurringTask;
use App\Models\Tag;
use App\Models\Task;
use App\Models\TaskChecklistItem;
use App\Models\TaskLink;
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
    ];
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
    // type requires — see NotificationPolicy. In Phase 2 that is everybody but the Accountant.
    $notifications = fn (int $status): array => ['guest' => '302 /login', 'ADMIN' => $status, 'MANAGER' => $status, 'EMPLOYEE' => $status, 'REMOTE_EMPLOYEE' => $status, 'ACCOUNTANT' => 403];

    // A DELETE row destroys the record it points at, and route-model binding runs before the
    // surface middleware — so every cell after the one that is allowed sees 404 where it would
    // otherwise have seen 403. The surface guard on these routes is asserted directly instead,
    // in tests/Feature/Admin/TaskWriteEndpointsTest.php.
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

        // Shared — the bell and the Notification Center. No surface, like the file download
        // above: a person's own mail is a fact about the person, not about the shell they are
        // looking at, so all four roles that have a mailbox reach the same four routes.
        //
        // The ACCOUNTANT cell is 403 on every one of them, and it is not a rule about
        // Accountants. `can:viewAny` asks whether this person could receive any kind of
        // notification at all, and every Phase 2 notification type requires `tasks.view`;
        // they hold no tasks.* key, exactly as they hold none in the task rows above.
        ['GET', 'notifications', $notifications(200)],
        ['GET', 'notifications/recent', $notifications(200)],
        ['POST', 'notifications/read-all', $notifications(302)],
        // The one row that states the other half of the rule. The notification belongs to
        // Tapu, so:
        //   ADMIN / MANAGER / EMPLOYEE  404 — somebody else's mail is ABSENT, not refused,
        //                                     even to an Admin who can see the whole agency
        //   REMOTE_EMPLOYEE             302 — it is Tapu's, and marking it read is idempotent
        //   ACCOUNTANT                  403 — stopped at the gate before the row is looked up
        ['POST', 'notifications/{notification}/read', ['guest' => '302 /login', 'ADMIN' => 404, 'MANAGER' => 404, 'EMPLOYEE' => 404, 'REMOTE_EMPLOYEE' => 302, 'ACCOUNTANT' => 403]],

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
