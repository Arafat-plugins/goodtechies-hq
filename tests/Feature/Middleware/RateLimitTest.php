<?php

use App\Models\Conversation;
use App\Models\Task;
use App\Models\User;
use App\Support\ReportKey;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rate limits beyond the sign-in path (Phase 12 — the security pass)
|--------------------------------------------------------------------------
|
| Part C §3: "rate limiting on auth and API routes". Until this slice the
| application had exactly two limiters, both on the way in — `login` and
| `two-factor`. Neither can see the case they are not about: an account that is
| already signed in and is being used against the agency.
|
| The numbers and what each was measured against are documented on
| AppServiceProvider::defineAuthenticatedRateLimiters(). This file asserts three
| things a comment cannot:
|
|   1. each limiter is DEFINED and resolves to the rate it is documented at;
|   2. the routes that are supposed to carry it actually do — a named limiter
|      nobody attached is dead configuration, and the only way to tell from the
|      outside is to read the route's middleware;
|   3. the key is the USER and not the IP. The office shares one address, so an
|      IP-keyed limit is a limit one person spends on behalf of everybody — a
|      denial of service with extra steps.
|
| Every constant and helper is prefixed LIMIT_ / limit*, because Pest declares
| both globally across the whole suite (AGENTS.md).
|
*/

/** Limiter name => the per-minute rate it is documented at. */
const LIMIT_RATES = [
    'authenticated' => 600,
    'search' => 120,
    'downloads' => 240,
    'reports' => 60,
    'posting' => 60,
];

beforeEach(function (): void {
    $this->seed();

    $this->limitAdmin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->limitEmployee = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();
});

/** Resolve a named limiter for a request and return the limits it produced. */
function limitFor(string $name, ?User $user = null): array
{
    $request = Request::create('/', 'GET');

    if ($user !== null) {
        $request->setUserResolver(fn (): User => $user);
    }

    $resolved = RateLimiter::limiter($name);

    expect($resolved)->not->toBeNull('There is no rate limiter named ['.$name.'].');

    $limits = $resolved($request);

    return is_array($limits) ? $limits : [$limits];
}

/** The middleware a named route carries, flattened. */
function limitMiddlewareOf(string $routeName): array
{
    $route = collect(Route::getRoutes())->first(fn ($r): bool => $r->getName() === $routeName);

    expect($route)->not->toBeNull('There is no route named ['.$routeName.'].');

    return $route->gatherMiddleware();
}

/*
|--------------------------------------------------------------------------
| The limiters exist at the rates they are documented at
|--------------------------------------------------------------------------
*/

it('defines each limiter at its documented rate', function (string $name, int $perMinute): void {
    $limits = limitFor($name, test()->limitAdmin);

    expect($limits)->toHaveCount(1);
    expect($limits[0])->toBeInstanceOf(Limit::class);
    expect($limits[0]->maxAttempts)->toBe($perMinute)
        ->and($limits[0]->decaySeconds)->toBe(60);
})->with(collect(LIMIT_RATES)->map(fn (int $rate, string $name): array => [$name, $rate])->values()->all());

it('spends a limit per account and never per address', function (string $name): void {
    $mine = limitFor($name, test()->limitAdmin)[0]->key;
    $theirs = limitFor($name, test()->limitEmployee)[0]->key;

    // Two people on one office connection must not share a bucket.
    expect($mine)->not->toBe($theirs)
        ->and($mine)->toContain((string) test()->limitAdmin->getKey());

    // And there is still a key when nobody is signed in, so the closure cannot divide by an
    // absent user if one of these names is ever put on a guest route.
    expect(limitFor($name)[0]->key)->toBeString()->not->toBe('');
})->with(array_keys(LIMIT_RATES));

/*
|--------------------------------------------------------------------------
| And the routes carry them
|--------------------------------------------------------------------------
*/

it('puts the blanket limit on every signed-in surface group', function (string $routeName): void {
    expect(limitMiddlewareOf($routeName))->toContain('throttle:authenticated');
})->with([
    'the admin shell' => ['admin.dashboard'],
    'the employee shell' => ['employee.dashboard'],
    'the accountant shell' => ['accountant.dashboard'],
    'the shared routes' => ['profile.show'],
    // The shared group covers Messages, Meetings, Finance, Payroll, Attendance, Leave and the
    // download route, so one row per surface plus this is the whole of it.
    'the download route' => ['files.download'],
]);

it('puts the targeted limit on each expensive surface', function (string $routeName, string $limiter): void {
    expect(limitMiddlewareOf($routeName))->toContain('throttle:'.$limiter);
})->with([
    'global search' => ['search.index', 'search'],
    'message search' => ['messages.search', 'search'],
    'file download' => ['files.download', 'downloads'],
    'a report' => ['admin.reports.show', 'reports'],
    'posting to a conversation' => ['messages.store', 'posting'],
    'posting to a task discussion (admin)' => ['admin.tasks.discussion.store', 'posting'],
    'posting to a task discussion (employee)' => ['employee.tasks.discussion.store', 'posting'],
]);

it('leaves the sign-in limiters exactly as they were', function (): void {
    // The two that existed before this slice. A hardening pass that quietly widened the login
    // limit while adding four others would be a net loss.
    expect(limitMiddlewareOf('login.store'))->toContain('throttle:login');

    $login = limitFor('login');

    expect($login)->toHaveCount(2)
        ->and($login[0]->maxAttempts)->toBe(5)
        ->and($login[1]->maxAttempts)->toBe(20);
});

/*
|--------------------------------------------------------------------------
| A limit that fires answers 429 and does not leak
|--------------------------------------------------------------------------
*/

it('answers 429 once a targeted limit is spent, and lets a second account straight through', function (): void {
    // The rate is asserted above; what is asserted here is that the middleware is wired to
    // something that actually refuses, and that the refusal is that ACCOUNT's and not the box's.
    // Set the limiter down to one attempt for the duration of this test rather than sending 60
    // real requests through a report builder.
    RateLimiter::for('reports', fn (Request $request): Limit => Limit::perMinute(1)
        ->by('user:'.($request->user()?->getAuthIdentifier() ?? 'guest')));

    $url = '/admin/reports/'.ReportKey::Task->value;

    $this->actingAs($this->limitAdmin)->get($url)->assertOk();
    $this->actingAs($this->limitAdmin)->get($url)->assertStatus(429);

    // A different Admin is unaffected. `faruk` holds the same keys, so this is the limit's
    // bucket being separate and not a permission difference.
    $other = User::where('email', 'faruk@goodtechies.test')->firstOrFail();

    $this->actingAs($other)->get($url)->assertOk();
});

it('does not throttle a task detail or a board out of usefulness', function (): void {
    // The blanket limit is 600 a minute, so twenty ordinary page loads in one test must not
    // approach it. This is the "do not throttle something into uselessness" half of the brief,
    // stated as something that can fail.
    $task = Task::query()->whereNotNull('project_id')->firstOrFail();
    $conversation = Conversation::query()->firstOrFail();

    for ($i = 0; $i < 20; $i++) {
        $this->actingAs($this->limitAdmin)->get('/admin/tasks/'.$task->getKey())->assertOk();
    }

    $this->actingAs($this->limitAdmin)->get('/admin/tasks/board')->assertOk();
    $this->actingAs($this->limitAdmin)->get('/messages/'.$conversation->getKey())->assertOk();
    $this->actingAs($this->limitAdmin)->get('/search?q=seo')->assertOk();
});
