<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Employee;
use App\Models\User;
use App\Support\RoleName;
use Illuminate\Http\Request;

/*
 * Reliability slice 3 — a deploy while tabs are open. A BACKGROUND partial reload that carries a
 * stale asset version gets a small JSON 409 `{reason: "version"}` and no `X-Inertia-Location`, so
 * the client offers "Reload now" instead of reloading the document under somebody's typing. A
 * person's own visit keeps Inertia's 409 + location (a full reload), unchanged.
 */

function VERSION_user(): User
{
    $user = User::factory()->create();
    Employee::factory()->forRole(RoleName::EMPLOYEE)->create(['user_id' => $user->id]);

    return $user->fresh();
}

function VERSION_staleHeaders(): array
{
    return [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => 'stale-build-before-the-deploy',
        'X-Requested-With' => 'XMLHttpRequest',
    ];
}

it('answers a background partial reload with a stale version with a JSON 409 and no location', function () {
    $response = $this->actingAs(VERSION_user())
        ->withHeaders([
            ...VERSION_staleHeaders(),
            'X-Inertia-Partial-Component' => 'Employee/Dashboard',
            'X-Inertia-Partial-Data' => 'shell',
        ])
        ->get('/employee/dashboard');

    $response->assertStatus(409)
        ->assertExactJson(['reason' => 'version'])
        ->assertHeaderMissing('X-Inertia-Location')
        ->assertHeaderMissing('X-Inertia');
})->group('errors');

it('lets a guest\'s stale-version background read say the session ended, not a new version', function () {
    $this->withHeaders([
        ...VERSION_staleHeaders(),
        'X-Inertia-Partial-Component' => 'Employee/Dashboard',
        'X-Inertia-Partial-Data' => 'shell',
    ])
        ->get('/employee/dashboard')
        ->assertStatus(401)
        ->assertJsonPath('reason', 'session')
        ->assertHeaderMissing('X-Inertia-Location');
})->group('errors');

it('keeps a surface 403 on a stale-version background read', function () {
    $this->actingAs(VERSION_user())
        ->withHeaders([
            ...VERSION_staleHeaders(),
            'X-Inertia-Partial-Component' => 'Admin/Dashboard',
            'X-Inertia-Partial-Data' => 'shell',
        ])
        ->get('/admin/dashboard')
        ->assertForbidden()
        ->assertJsonMissingExact(['reason' => 'version']);
})->group('errors');

it('keeps Inertia\'s 409 with a location for a person\'s own visit with a stale version', function () {
    $response = $this->actingAs(VERSION_user())
        ->withHeaders(VERSION_staleHeaders())
        ->get('/employee/dashboard');

    $response->assertStatus(409)
        ->assertHeader('X-Inertia-Location', url('/employee/dashboard'));
})->group('errors');

it('leaves a background partial reload with the current version alone', function () {
    $version = (string) app(HandleInertiaRequests::class)
        ->version(Request::create('/'));

    $this->actingAs(VERSION_user())
        ->withHeaders([
            'X-Inertia' => 'true',
            'X-Inertia-Version' => $version,
            'X-Requested-With' => 'XMLHttpRequest',
            'X-Inertia-Partial-Component' => 'Employee/Dashboard',
            'X-Inertia-Partial-Data' => 'shell',
        ])
        ->get('/employee/dashboard')
        ->assertOk()
        ->assertHeader('X-Inertia', 'true');
})->group('errors');
