<?php

use App\Models\Client;
use App\Models\Employee;
use App\Models\Project;
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
    ];
}

/** The task the task rows point at by default. */
const MATRIX_TASK = 'Fix the duplicate canonical tags on model pages';

function matrixTaskId(string $title): string
{
    return (string) Task::where('title', $title)->firstOrFail()->id;
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

        // Admin surface — clients. A body-less mutating call stops at the validation
        // redirect, which is proof enough that it got past every gate.
        ['GET', 'admin/clients', $admin],
        ['GET', 'admin/clients/create', $admin],
        ['POST', 'admin/clients', $adminAction],
        ['GET', 'admin/clients/{client}', $admin],
        ['GET', 'admin/clients/{client}/edit', $admin],
        ['PUT', 'admin/clients/{client}', $adminAction],
        ['POST', 'admin/clients/{client}/deactivate', $adminAction],

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

        // Admin surface — tasks. A status moves through `…/status` and nowhere else: there is
        // no second endpoint here for the board drag to use, which is the point.
        ['GET', 'admin/tasks', $admin],
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
        // Soft delete, on its own task, needed by no row after it.
        ['DELETE', 'admin/tasks/{task}', $consumed, ['{task}' => 'task:Write the 404 and maintenance pages']],

        // Employee surface
        ['GET', 'employee/dashboard', $employee],
        ['GET', 'employee/projects', $employee],
        // Tapu (REMOTE_EMPLOYEE) is on this project and Yaseen (EMPLOYEE) is not: a project
        // an employee is not on is missing, not forbidden.
        ['GET', 'employee/projects/{project}', ['guest' => '302 /login', 'ADMIN' => 403, 'MANAGER' => 200, 'EMPLOYEE' => 404, 'REMOTE_EMPLOYEE' => 200, 'ACCOUNTANT' => 403]],
        // The task list is a list: every role that may reach the surface gets a 200 and sees
        // its own rows. Which rows those are is Task::visibleTo()'s business, tested in
        // tests/Feature/Privacy/TaskPrivacyTest.php, not a status code here.
        ['GET', 'employee/tasks', $employee],

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
        // Delete is ADMIN/MANAGER too, and a Manager can only reach it here. Its own task.
        ['DELETE', 'employee/tasks/{task}', $consumedByManager, ['{task}' => 'task:Refresh the agency case-study deck']],

        // Accountant surface
        ['GET', 'accountant/dashboard', $accountant],

        // Shared profile
        ['GET', 'profile', $everyone(200)],
        ['PUT', 'profile', $everyone(302)],
        ['PUT', 'profile/password', $everyone(302)],
        ['DELETE', 'profile/two-factor', $everyone(302)],
        ['POST', 'profile/two-factor/recovery-codes', $everyone(302)],
        ['DELETE', 'profile/sessions/{session}', $everyone(404)],
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
