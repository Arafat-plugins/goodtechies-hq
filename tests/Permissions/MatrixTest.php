<?php

use App\Models\Employee;
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
 * Values substituted for route parameters.
 */
const MATRIX_PARAMETERS = [
    '{session}' => 'not-a-session-of-this-user',
];

/**
 * @return list<array{0: string, 1: string, 2: array<string, int|string>}>
 */
function permissionMatrix(): array
{
    $guestOnly = ['guest' => 302, 'ADMIN' => '302 /', 'MANAGER' => '302 /', 'EMPLOYEE' => '302 /', 'REMOTE_EMPLOYEE' => '302 /', 'ACCOUNTANT' => '302 /'];
    $everyone = fn (int|string $status): array => ['guest' => '302 /login', 'ADMIN' => $status, 'MANAGER' => $status, 'EMPLOYEE' => $status, 'REMOTE_EMPLOYEE' => $status, 'ACCOUNTANT' => $status];
    $admin = ['guest' => '302 /login', 'ADMIN' => 200, 'MANAGER' => 403, 'EMPLOYEE' => 403, 'REMOTE_EMPLOYEE' => 403, 'ACCOUNTANT' => 403];
    $employee = ['guest' => '302 /login', 'ADMIN' => 403, 'MANAGER' => 200, 'EMPLOYEE' => 200, 'REMOTE_EMPLOYEE' => 200, 'ACCOUNTANT' => 403];
    $accountant = ['guest' => '302 /login', 'ADMIN' => 403, 'MANAGER' => 403, 'EMPLOYEE' => 403, 'REMOTE_EMPLOYEE' => 403, 'ACCOUNTANT' => 200];

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

        // Employee surface
        ['GET', 'employee/dashboard', $employee],

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
    $mismatches = [];

    foreach (permissionMatrix() as [$method, $uri, $expectations]) {
        expect(array_keys($expectations))->toEqualCanonicalizing(MATRIX_ROLES);

        $path = '/'.ltrim(strtr($uri, MATRIX_PARAMETERS), '/');

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
