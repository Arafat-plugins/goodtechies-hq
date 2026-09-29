<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Employee;
use App\Models\User;
use App\Services\ConversationService;
use App\Support\RoleName;
use App\Support\UserStatus;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;

/*
 * Reliability slice 2a — error pages. A person's Inertia visit that meets a 403 / 404 / 429 /
 * 500 / 503 gets the `Shared/Error` page with the SAME status (never Laravel's HTML in Inertia's
 * raw modal); a background partial reload gets `{reason: "error"}` and never a page swap; a body
 * over the server's limit gets `{reason: "too_large"}`. Statuses never change — the permission
 * matrix and the privacy rules are written against them.
 */

beforeEach(function () {
    Route::middleware(['web'])->get('/_error_test/boom', function () {
        throw new RuntimeException('Deliberate failure for the error-page test.');
    });

    Route::middleware(['web'])->post('/_error_test/boom', function () {
        throw new RuntimeException('Deliberate failure for the error-page test.');
    });

    Route::middleware(['web'])->get('/_error_test/down', fn () => abort(503));

    Route::middleware(['web'])->get('/_error_test/throttled', function () {
        throw new ThrottleRequestsException('Too Many Attempts.', null, ['Retry-After' => '60']);
    });

    Route::middleware(['web'])->post('/_error_test/too-large', function () {
        throw new PostTooLargeException('The POST data is too large.');
    });
});

function ERRPAGE_inertiaHeaders(): array
{
    return [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(Request::create('/')),
        'X-Requested-With' => 'XMLHttpRequest',
    ];
}

function ERRPAGE_partialHeaders(string $component): array
{
    return [
        ...ERRPAGE_inertiaHeaders(),
        'X-Inertia-Partial-Component' => $component,
        'X-Inertia-Partial-Data' => 'shell',
    ];
}

function ERRPAGE_user(RoleName $role): User
{
    $user = User::factory()->create();
    Employee::factory()->forRole($role)->create(['user_id' => $user->id]);

    return $user->fresh();
}

it('answers a person\'s Inertia visit to a missing record with the error page and a 404', function () {
    $this->actingAs(ERRPAGE_user(RoleName::EMPLOYEE))
        ->withHeaders(ERRPAGE_inertiaHeaders())
        ->get('/employee/tasks/999999')
        ->assertNotFound()
        ->assertHeader('X-Inertia', 'true')
        ->assertJsonPath('component', 'Shared/Error')
        ->assertJsonPath('props.status', 404)
        ->assertJsonPath('props.home', route('employee.dashboard'))
        ->assertJsonPath('props.auth.user.surface', 'employee');
})->group('errors');

it('answers a policy refusal on an Inertia visit with the error page and keeps the 403', function () {
    // An employee on a finance route is refused by the gate, not by a surface mismatch.
    $this->actingAs(ERRPAGE_user(RoleName::EMPLOYEE))
        ->withHeaders(ERRPAGE_inertiaHeaders())
        ->get('/finance')
        ->assertForbidden()
        ->assertJsonPath('component', 'Shared/Error')
        ->assertJsonPath('props.status', 403)
        ->assertJsonPath('props.home', route('employee.dashboard'));
})->group('errors');

it('answers a server error on an Inertia visit with the error page and a 500, still reported', function () {
    Exceptions::fake();

    $this->actingAs(ERRPAGE_user(RoleName::EMPLOYEE))
        ->withHeaders(ERRPAGE_inertiaHeaders())
        ->get('/_error_test/boom')
        ->assertStatus(500)
        ->assertJsonPath('component', 'Shared/Error')
        ->assertJsonPath('props.status', 500);

    Exceptions::assertReported(RuntimeException::class);
})->group('errors');

it('answers maintenance on an Inertia visit with the error page and a 503', function () {
    $this->withHeaders(ERRPAGE_inertiaHeaders())
        ->get('/_error_test/down')
        ->assertStatus(503)
        ->assertJsonPath('component', 'Shared/Error')
        ->assertJsonPath('props.status', 503)
        ->assertJsonPath('props.home', route('login'));
})->group('errors');

it('answers a rate limit on an Inertia visit with the error page, a 429 and its Retry-After', function () {
    $this->withHeaders(ERRPAGE_inertiaHeaders())
        ->get('/_error_test/throttled')
        ->assertStatus(429)
        ->assertHeader('Retry-After', '60')
        ->assertJsonPath('component', 'Shared/Error')
        ->assertJsonPath('props.status', 429);
})->group('errors');

it('answers a background partial reload that meets a 404 with a small JSON body, not a page', function () {
    $response = $this->actingAs(ERRPAGE_user(RoleName::EMPLOYEE))
        ->withHeaders(ERRPAGE_partialHeaders('Employee/Tasks/Show'))
        ->get('/employee/tasks/999999')
        ->assertNotFound()
        ->assertExactJson(['reason' => 'error', 'status' => 404]);

    expect($response->headers->has('X-Inertia'))->toBeFalse();
})->group('errors');

it('answers a background partial reload that meets a 500 with a small JSON body, not a page', function () {
    $this->actingAs(ERRPAGE_user(RoleName::EMPLOYEE))
        ->withHeaders(ERRPAGE_partialHeaders('Employee/Dashboard'))
        ->get('/_error_test/boom')
        ->assertStatus(500)
        ->assertExactJson(['reason' => 'error', 'status' => 500]);
})->group('errors');

it('answers a Save whose body is too large with a too_large reason and a 413', function () {
    $this->withHeaders(ERRPAGE_inertiaHeaders())
        ->post('/_error_test/too-large')
        ->assertStatus(413)
        ->assertJson(['reason' => 'too_large']);

    $this->postJson('/_error_test/too-large')
        ->assertStatus(413)
        ->assertJson(['reason' => 'too_large']);
})->group('errors');

it('answers a Save that meets a server error with the error page, keeping the 500', function () {
    // The client turns this into a toast and keeps the form; the server's part is the status.
    $this->actingAs(ERRPAGE_user(RoleName::EMPLOYEE))
        ->withHeaders(ERRPAGE_inertiaHeaders())
        ->post('/_error_test/boom')
        ->assertStatus(500)
        ->assertJsonPath('component', 'Shared/Error');
})->group('errors');

it('gives a full-page request the plain error page with the same status', function () {
    $this->actingAs(ERRPAGE_user(RoleName::EMPLOYEE))
        ->get('/employee/tasks/999999')
        ->assertNotFound()
        ->assertSee("This page doesn't exist, or you don't have access to it.")
        ->assertSee('Go to my home page');

    $this->get('/_error_test/down')
        ->assertStatus(503)
        ->assertSee('goodERP is being updated. Try again in a minute.');
})->group('errors');

it('leaves a JSON request\'s error as JSON', function () {
    $this->actingAs(ERRPAGE_user(RoleName::EMPLOYEE))
        ->getJson('/employee/tasks/999999')
        ->assertNotFound()
        ->assertJsonMissingPath('component');
})->group('errors');

it('keeps slice 1\'s answers: 401 session, and the surface 403 with its home', function () {
    $this->withHeaders(ERRPAGE_inertiaHeaders())
        ->get('/employee/dashboard')
        ->assertStatus(401)
        ->assertJson(['reason' => 'session']);

    $this->actingAs(ERRPAGE_user(RoleName::EMPLOYEE))
        ->withHeaders(ERRPAGE_inertiaHeaders())
        ->get('/admin/dashboard')
        ->assertForbidden()
        ->assertJson(['reason' => 'surface', 'home' => route('employee.dashboard')]);
})->group('errors');

it('marks a binding 404 and a partial-reload error as varying on X-Inertia', function () {
    $user = ERRPAGE_user(RoleName::EMPLOYEE);

    $page = $this->actingAs($user)
        ->withHeaders(ERRPAGE_inertiaHeaders())
        ->get('/employee/tasks/999999')
        ->assertNotFound();

    expect($page->headers->get('Vary'))->toContain('X-Inertia');

    $partial = $this->actingAs($user)
        ->withHeaders(ERRPAGE_partialHeaders('Employee/Tasks/Show'))
        ->get('/employee/tasks/999999')
        ->assertNotFound()
        ->assertExactJson(['reason' => 'error', 'status' => 404]);

    expect($partial->headers->get('Vary'))->toContain('X-Inertia');

    $tooLarge = $this->withHeaders(ERRPAGE_inertiaHeaders())
        ->post('/_error_test/too-large')
        ->assertStatus(413);

    expect($tooLarge->headers->get('Vary'))->toContain('X-Inertia');
})->group('errors');

it('draws a deactivated account\'s binding 404 as a guest, without its shell', function () {
    $user = ERRPAGE_user(RoleName::EMPLOYEE);
    $user->forceFill(['status' => UserStatus::Inactive])->save();

    $response = $this->actingAs($user->fresh())
        ->withHeaders(ERRPAGE_inertiaHeaders())
        ->get('/employee/tasks/999999');

    // The binding's 404 is thrown before `active` runs: the page is drawn, but as a guest's.
    $response->assertNotFound()
        ->assertJsonPath('component', 'Shared/Error')
        ->assertJsonPath('props.home', route('login'))
        ->assertJsonMissingPath('props.auth.user');
})->group('errors');

it('keeps the original status when sharing the shell props throws', function () {
    app()->instance(HandleInertiaRequests::class, new class(app(ConversationService::class)) extends HandleInertiaRequests
    {
        public function share(Request $request): array
        {
            throw new RuntimeException('Database is down.');
        }
    });

    $this->actingAs(ERRPAGE_user(RoleName::EMPLOYEE))
        ->withHeaders(ERRPAGE_inertiaHeaders())
        ->get('/employee/tasks/999999')
        ->assertNotFound()
        ->assertJsonPath('component', 'Shared/Error')
        ->assertJsonPath('props.home', route('login'))
        ->assertJsonMissingPath('props.auth.user');
})->group('errors');
