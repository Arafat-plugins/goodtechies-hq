<?php

use App\Models\Employee;
use App\Models\User;
use App\Support\RoleName;
use Illuminate\Auth\SessionGuard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Session lifetime — two days of inactivity, sliding (decision 12-84)
|--------------------------------------------------------------------------
|
| Closing the tab does not sign anybody out; two days without a request ends the SESSION.
| "Remember me" (on by default) signs the person back in for 400 days — they stay signed in
| until they sign out (decision 12-89, replacing 12-84's two-day cap on it).
|
*/

it('keeps a session for two days of inactivity and not on browser close', function () {
    expect(config('session.lifetime'))->toBe(2880)
        ->and((bool) config('session.expire_on_close'))->toBeFalse();
});

it('remembers a person for 400 days, until they sign out (decision 12-89)', function () {
    $guard = Auth::guard('web');

    expect($guard)->toBeInstanceOf(SessionGuard::class)
        ->and((fn (): int => $this->rememberDuration)->call($guard))->toBe(576000);
});

it('ticks Remember me on the sign-in form by default', function () {
    $form = file_get_contents(resource_path('js/Pages/Auth/Login.vue'));

    expect($form)->toContain('remember: true,');
});

/**
 * A `sessions` row as `DatabaseSessionHandler` writes it, for $user, idle for $hours.
 */
function sessionRowFor(User $user, int $hours): string
{
    $id = Str::random(40);

    DB::table('sessions')->insert([
        'id' => $id,
        'user_id' => $user->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'Symfony',
        // config/session.php `serialization` is `json`.
        'payload' => base64_encode(json_encode([
            '_token' => Str::random(40),
            'login_web_'.sha1(SessionGuard::class) => $user->id,
        ], JSON_THROW_ON_ERROR)),
        'last_activity' => now()->subHours($hours)->getTimestamp(),
    ]);

    return $id;
}

/**
 * The database driver, as production runs it. phpunit.xml pins `array`; the store and the guard
 * are rebuilt so nothing built before this line still holds the array driver.
 */
function useDatabaseSessions(): void
{
    config(['session.driver' => 'database']);
    app()->forgetInstance('session.store');
    app('session')->forgetDrivers();
    app('auth')->forgetGuards();
}

it('still authenticates a session idle for 47 hours', function () {
    useDatabaseSessions();
    $user = Employee::factory()->forRole(RoleName::EMPLOYEE)->create()->user;
    $id = sessionRowFor($user, 47);

    $this->withCookie(config('session.cookie'), $id)->get('/profile')->assertOk();
});

it('signs out a session idle for 49 hours', function () {
    useDatabaseSessions();
    $user = Employee::factory()->forRole(RoleName::EMPLOYEE)->create()->user;
    $id = sessionRowFor($user, 49);

    $this->withCookie(config('session.cookie'), $id)->get('/profile')->assertRedirect('/login');
});
