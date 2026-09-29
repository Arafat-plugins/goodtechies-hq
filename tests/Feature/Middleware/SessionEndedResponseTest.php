<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Employee;
use App\Models\User;
use App\Support\RoleName;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;

/*
 * Reliability slice 1 — session expiry. A background Inertia visit or a fetch() that finds the
 * session gone gets a 401 (or 419 for a stale CSRF token) the client can turn into a dialog,
 * instead of a redirect Inertia would follow onto the login page. A role change keeps its 403
 * and tells the client where the person now lives. Full-page requests are untouched.
 */

beforeEach(function () {
    foreach (['admin', 'employee', 'accountant'] as $surface) {
        Route::middleware(['web', 'auth', "surface:{$surface}"])
            ->get("/_session_test/{$surface}", fn () => "{$surface} ok");
    }

    Route::middleware(['web'])->post('/_session_test/csrf', function () {
        throw new TokenMismatchException('CSRF token mismatch.');
    });
});

function SESSION_inertiaHeaders(): array
{
    return [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(Request::create('/')),
        'X-Requested-With' => 'XMLHttpRequest',
    ];
}

function SESSION_user(RoleName $role): User
{
    $user = User::factory()->create();
    Employee::factory()->forRole($role)->create(['user_id' => $user->id]);

    return $user->fresh();
}

it('answers an unauthenticated Inertia visit with a 401 session reason, not a redirect', function () {
    $this->withHeaders(SESSION_inertiaHeaders())
        ->get('/employee/dashboard')
        ->assertStatus(401)
        ->assertJson(['reason' => 'session'])
        ->assertJsonStructure(['message', 'reason']);
})->group('session');

it('answers an unauthenticated JSON request with a 401 session reason', function () {
    $this->getJson('/notifications/recent')
        ->assertUnauthorized()
        ->assertJson(['reason' => 'session']);
})->group('session');

it('still redirects an unauthenticated full-page GET to the login page, remembering the URL', function () {
    $this->get('/employee/dashboard')->assertRedirect('/login');

    expect(session('url.intended'))->toEndWith('/employee/dashboard');
})->group('session');

it('answers a CSRF mismatch on an Inertia request with a 419 csrf reason', function () {
    $this->withHeaders(SESSION_inertiaHeaders())
        ->post('/_session_test/csrf')
        ->assertStatus(419)
        ->assertJson(['reason' => 'csrf']);
})->group('session');

it('answers a CSRF mismatch on a JSON request with a 419 csrf reason', function () {
    $this->postJson('/_session_test/csrf')
        ->assertStatus(419)
        ->assertJson(['reason' => 'csrf']);
})->group('session');

it('keeps the plain 419 page for a CSRF mismatch on a full-page form post', function () {
    $response = $this->post('/_session_test/csrf')->assertStatus(419);

    expect($response->headers->get('Content-Type'))->not->toContain('application/json');
})->group('session');

it('answers a wrong surface over Inertia with a 403 that names the person\'s own home', function (RoleName $role, string $home, string $wrong) {
    $this->actingAs(SESSION_user($role))
        ->withHeaders(SESSION_inertiaHeaders())
        ->get("/_session_test/{$wrong}")
        ->assertForbidden()
        ->assertJson(['reason' => 'surface', 'home' => route($home)]);
})->with([
    'admin' => [RoleName::ADMIN, 'admin.dashboard', 'employee'],
    'employee' => [RoleName::EMPLOYEE, 'employee.dashboard', 'admin'],
    'accountant' => [RoleName::ACCOUNTANT, 'accountant.dashboard', 'admin'],
])->group('session');

it('answers a wrong surface over JSON with the same 403 surface body', function () {
    $this->actingAs(SESSION_user(RoleName::EMPLOYEE))
        ->getJson('/_session_test/accountant')
        ->assertForbidden()
        ->assertJson(['reason' => 'surface', 'home' => route('employee.dashboard')]);
})->group('session');

it('leaves a full-page wrong-surface response an unchanged 403 page', function () {
    $response = $this->actingAs(SESSION_user(RoleName::EMPLOYEE))
        ->get('/_session_test/admin')
        ->assertForbidden();

    expect($response->headers->get('Content-Type'))->not->toContain('application/json')
        ->and($response->getContent())->not->toContain('"reason"');
})->group('session');

it('still lets each role through to its own surface over Inertia', function () {
    $this->actingAs(SESSION_user(RoleName::EMPLOYEE))
        ->withHeaders(SESSION_inertiaHeaders())
        ->get('/_session_test/employee')
        ->assertOk()
        ->assertSee('employee ok');
})->group('session');

it('names the signed-in user to the dialog\'s identity check without sending anything else', function () {
    // The session dialog's recheck asks /profile for the shared `auth` prop only, to be sure the
    // person who signed back in is the one the page belongs to. Existing route, no new one.
    $user = SESSION_user(RoleName::EMPLOYEE);

    $response = $this->actingAs($user)
        ->withHeaders(SESSION_inertiaHeaders() + [
            'X-Inertia-Partial-Component' => 'Shared/Profile',
            'X-Inertia-Partial-Data' => 'auth',
        ])
        ->get('/profile')
        ->assertOk()
        ->assertJsonPath('component', 'Shared/Profile')
        ->assertJsonPath('props.auth.user.id', $user->id);

    expect(array_keys($response->json('props')))->not->toContain('sessions');
})->group('session');
