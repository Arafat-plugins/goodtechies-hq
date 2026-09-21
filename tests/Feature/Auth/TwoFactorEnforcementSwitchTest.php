<?php

use App\Models\Employee;
use App\Models\User;
use App\Services\TwoFactorService;
use App\Support\RoleName;
use App\Support\UserStatus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PragmaRX\Google2FA\Google2FA;

/*
|--------------------------------------------------------------------------
| The local-development two-factor switch
|--------------------------------------------------------------------------
|
| AUTH_TWO_FACTOR_ENFORCED=false lets an Admin or Accountant sign in with a password
| alone on a developer's own machine. The property that matters is that it cannot do
| that in production. See config/auth.php and TwoFactorService::isEnforced().
|
*/

beforeEach(function () {
    $this->seed();
    $this->password = env('SEED_PASSWORD');
    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->accountant = User::where('email', 'accountant@goodtechies.test')->firstOrFail();
});

/**
 * Switch the enforcement config off the way a developer's .env would.
 */
function switchTwoFactorOff(): void
{
    Config::set('auth.two_factor.enforced', false);
}

/**
 * Put the application into the production environment for the rest of the test, and prove
 * it took. Without this assertion a typo in the environment name would make every
 * production test below pass vacuously.
 */
function pretendProduction(): void
{
    app()['env'] = 'production';

    expect(app()->isProduction())->toBeTrue('the test failed to put the app into production');
}

/**
 * POST while the app believes it is in production.
 *
 * Laravel exempts POSTs from CSRF only while the environment is `testing`, so a production
 * test has to carry a real token. Supplying one keeps the whole middleware stack in place —
 * the point of these tests is that nothing is switched off except the environment name.
 *
 * @param  array<string, mixed>  $payload
 */
function postInProduction(object $test, string $uri, array $payload): TestResponse
{
    $token = 'csrf-token-for-this-test';

    return $test->withSession(['_token' => $token])->post($uri, [...$payload, '_token' => $token]);
}

/**
 * Run $body with AUTH_TWO_FACTOR_ENFORCED set to a raw string (or absent, for null), then put
 * the process back exactly as it was.
 *
 * Restoring faithfully matters more than it looks. phpunit.xml pins the variable to true so a
 * developer's own .env cannot switch enforcement off for the whole suite — but that pin only
 * holds while the variable stays set. Leave it unset and the next application boot re-reads
 * .env, where a developer may well have AUTH_TWO_FACTOR_ENFORCED=false, and every later 2FA
 * test in the process silently stops enforcing. So: save all three channels, restore all three.
 *
 * @template T
 *
 * @param  Closure(): T  $body
 * @return T
 */
function withRawTwoFactorEnv(?string $raw, Closure $body): mixed
{
    $name = 'AUTH_TWO_FACTOR_ENFORCED';

    $saved = [
        'env' => $_ENV[$name] ?? null,
        'server' => $_SERVER[$name] ?? null,
        'putenv' => getenv($name),
    ];

    $clear = static function () use ($name): void {
        unset($_ENV[$name], $_SERVER[$name]);
        putenv($name);
    };

    try {
        $clear();

        if ($raw !== null) {
            $_ENV[$name] = $raw;
            $_SERVER[$name] = $raw;
            putenv("{$name}={$raw}");
        }

        return $body();
    } finally {
        $clear();

        if ($saved['env'] !== null) {
            $_ENV[$name] = $saved['env'];
        }
        if ($saved['server'] !== null) {
            $_SERVER[$name] = $saved['server'];
        }
        if ($saved['putenv'] !== false) {
            putenv("{$name}={$saved['putenv']}");
        }
    }
}

/**
 * The value config/auth.php resolves for a raw AUTH_TWO_FACTOR_ENFORCED, as a hand-edited
 * .env would produce it.
 */
function resolvedEnforcedConfig(?string $raw): bool
{
    return withRawTwoFactorEnv(
        $raw,
        static fn (): bool => (require base_path('config/auth.php'))['two_factor']['enforced'],
    );
}

/**
 * A user of the given role, with or without a confirmed secret, for the middleware tests.
 */
function switchTestUser(RoleName $role, bool $confirmed): User
{
    $factory = User::factory();

    if ($confirmed) {
        $factory = $factory->withTwoFactor(env('SEED_TWO_FACTOR_SECRET'));
    }

    $user = $factory->create();
    Employee::factory()->forRole($role)->create(['user_id' => $user->id]);

    return $user->fresh();
}

/**
 * A route behind the `two-factor` middleware, mirroring EnsureTwoFactorEnrolledTest.
 */
function registerTwoFactorProbeRoute(): void
{
    Route::middleware(['web', 'auth', 'two-factor'])
        ->get('/_test/protected', fn () => 'protected ok');
}

// ---------------------------------------------------------------------------
// 1. The default: absent or malformed means ON.
// ---------------------------------------------------------------------------

it('enforces two-factor for the suite, whatever the developer has in their own .env', function () {
    // phpunit.xml pins AUTH_TWO_FACTOR_ENFORCED=true. This is the canary for that pin: if a
    // developer's local switch, or a test that forgets to restore the variable, ever reaches
    // the suite, every other 2FA test would quietly stop enforcing and this one fails first.
    expect(config('auth.two_factor.enforced'))->toBeTrue()
        ->and(TwoFactorService::isEnforced())->toBeTrue();
})->group('phase0');

it('enforces two-factor when the environment variable is absent altogether', function () {
    expect(resolvedEnforcedConfig(null))->toBeTrue();
})->group('phase0');

it('falls back to enforced when the config key is missing entirely', function () {
    Config::set('auth.two_factor', []);

    expect(TwoFactorService::isEnforced())->toBeTrue();
})->group('phase0');

it('fails closed: only an explicit boolean false switches enforcement off', function (?string $raw, bool $expected) {
    // Anything unrecognised must resolve to ON, never OFF.
    expect(resolvedEnforcedConfig($raw))->toBe($expected);
})->with([
    'absent' => [null, true],
    'empty' => ['', true],
    'nonsense' => ['maybe', true],
    'misspelt' => ['flase', true],
    'the word disabled' => ['disabled', true],
    'true' => ['true', true],
    'one' => ['1', true],
    'false' => ['false', false],
    'zero' => ['0', false],
    'off' => ['off', false],
    'no' => ['no', false],
])->group('phase0');

// ---------------------------------------------------------------------------
// 2. Switched off outside production: password alone, and nothing destroyed.
// ---------------------------------------------------------------------------

it('lets an enrolled admin and accountant sign in with a password alone when the switch is off', function (string $email, string $dashboard) {
    switchTwoFactorOff();

    $this->post('/login', ['email' => $email, 'password' => $this->password])
        ->assertRedirect('/')
        ->assertSessionMissing(TwoFactorService::PENDING_LOGIN_SESSION_KEY);

    $user = User::where('email', $email)->firstOrFail();
    $this->assertAuthenticatedAs($user);

    $this->get('/')->assertRedirect($dashboard);
    $this->get($dashboard)->assertOk();
})->with([
    'admin' => ['shahadat@goodtechies.test', '/admin/dashboard'],
    'accountant' => ['accountant@goodtechies.test', '/accountant/dashboard'],
])->group('phase0');

it('never sends the user to the challenge while the switch is off', function () {
    switchTwoFactorOff();

    // As a guest there is no pending login, so the challenge page has nobody to challenge.
    $this->get('/two-factor/challenge')->assertRedirect('/login');

    $this->post('/login', ['email' => 'shahadat@goodtechies.test', 'password' => $this->password])
        ->assertRedirect('/');

    // And once signed in, the `guest` middleware bounces them home. Either way the challenge
    // is unreachable — the switch removes the step rather than leaving a half-open door.
    $this->get('/two-factor/challenge')->assertRedirect('/');
})->group('phase0');

it('leaves the secret, the confirmation and the recovery codes untouched', function () {
    switchTwoFactorOff();

    $before = $this->admin->only(['two_factor_secret', 'two_factor_confirmed_at', 'two_factor_recovery_codes']);

    $this->post('/login', ['email' => 'shahadat@goodtechies.test', 'password' => $this->password]);
    $this->get('/admin/dashboard')->assertOk();

    $after = $this->admin->fresh();

    expect($after->two_factor_secret)->toBe($before['two_factor_secret'])
        ->and($after->two_factor_confirmed_at?->toIso8601String())->toBe($before['two_factor_confirmed_at']?->toIso8601String())
        ->and($after->two_factor_recovery_codes)->toBe($before['two_factor_recovery_codes'])
        ->and($after->hasConfirmedTwoFactor())->toBeTrue();
})->group('phase0');

it('does not silently un-enrol anyone: the role still requires 2FA and it still cannot be turned off', function () {
    switchTwoFactorOff();

    // requiresTwoFactor() is a fact about the role, not about enforcement. If the switch
    // reached into it, an Admin could DELETE /profile/two-factor and destroy their secret.
    expect($this->admin->requiresTwoFactor())->toBeTrue()
        ->and($this->accountant->requiresTwoFactor())->toBeTrue();

    expect(fn () => app(TwoFactorService::class)->disable($this->admin, $this->admin))
        ->toThrow(LogicException::class);

    expect($this->admin->fresh()->hasConfirmedTwoFactor())->toBeTrue();
})->group('phase0');

it('restores the previous state exactly when the switch goes back on', function () {
    switchTwoFactorOff();

    $this->post('/login', ['email' => 'shahadat@goodtechies.test', 'password' => $this->password])
        ->assertRedirect('/');

    $this->post('/logout');

    Config::set('auth.two_factor.enforced', true);

    $this->post('/login', ['email' => 'shahadat@goodtechies.test', 'password' => $this->password])
        ->assertRedirect('/two-factor/challenge');

    $this->assertGuest();

    // And the stored secret is still the one that works.
    $code = app(Google2FA::class)->getCurrentOtp(env('SEED_TWO_FACTOR_SECRET'));
    $this->post('/two-factor/challenge', ['code' => $code])->assertRedirect('/');
    $this->assertAuthenticatedAs($this->admin);
})->group('phase0');

it('stops redirecting an unenrolled admin or accountant to enrolment when the switch is off', function (RoleName $role) {
    switchTwoFactorOff();
    registerTwoFactorProbeRoute();

    $this->actingAs(switchTestUser($role, confirmed: false))
        ->get('/_test/protected')
        ->assertOk()
        ->assertSee('protected ok');
})->with([RoleName::ADMIN, RoleName::ACCOUNTANT])->group('phase0');

it('keeps the enrolment page and its POST working while the switch is off', function () {
    switchTwoFactorOff();

    $user = switchTestUser(RoleName::ADMIN, confirmed: false);

    $this->actingAs($user)->get('/two-factor/enrol')->assertOk();

    $secret = $user->fresh()->two_factor_secret;
    expect($secret)->not->toBeNull();

    $this->actingAs($user)
        ->post('/two-factor/enrol', ['code' => app(Google2FA::class)->getCurrentOtp($secret)])
        ->assertRedirect('/two-factor/recovery-codes');

    expect($user->fresh()->hasConfirmedTwoFactor())->toBeTrue();
})->group('phase0');

// ---------------------------------------------------------------------------
// 3. The one that matters: in production the switch is inert.
// ---------------------------------------------------------------------------

it('IGNORES the switch in production: the predicate is true however the config is set', function () {
    switchTwoFactorOff();
    pretendProduction();

    // The config really is off — this is not a test that forgot to switch it.
    expect(config('auth.two_factor.enforced'))->toBeFalse()
        ->and(TwoFactorService::isEnforced())->toBeTrue(
            'TwoFactorService::isEnforced() must return true in production regardless of config. '
            .'If this fails, the production guard in isEnforced() has been removed or weakened.'
        );
})->group('phase0');

it('IGNORES the switch in production: an enrolled admin still gets the challenge', function (string $email) {
    switchTwoFactorOff();
    pretendProduction();

    postInProduction($this, '/login', ['email' => $email, 'password' => $this->password])
        ->assertRedirect('/two-factor/challenge')
        ->assertSessionHas(TwoFactorService::PENDING_LOGIN_SESSION_KEY);

    // The password alone must not have authenticated anyone.
    $this->assertGuest();
    $this->get('/admin/dashboard')->assertRedirect('/login');
})->with([
    'admin' => ['shahadat@goodtechies.test'],
    'accountant' => ['accountant@goodtechies.test'],
])->group('phase0');

it('IGNORES the switch in production: an unenrolled admin is still held at enrolment', function (RoleName $role) {
    switchTwoFactorOff();
    pretendProduction();
    registerTwoFactorProbeRoute();

    $this->actingAs(switchTestUser($role, confirmed: false))
        ->get('/_test/protected')
        ->assertRedirect('/two-factor/enrol');
})->with([RoleName::ADMIN, RoleName::ACCOUNTANT])->group('phase0');

it('IGNORES the switch in production even when the environment variable itself says false', function () {
    // Belt and braces: the real environment variable, in its real .env spelling, not just the
    // already-resolved config value.
    withRawTwoFactorEnv('false', function () {
        Config::set('auth.two_factor', (require base_path('config/auth.php'))['two_factor']);
        expect(config('auth.two_factor.enforced'))->toBeFalse();

        pretendProduction();

        expect(TwoFactorService::isEnforced())->toBeTrue();

        postInProduction($this, '/login', ['email' => 'shahadat@goodtechies.test', 'password' => $this->password])
            ->assertRedirect('/two-factor/challenge');
        $this->assertGuest();
    });
})->group('phase0');

it('honours the switch under staging, which is not production', function () {
    switchTwoFactorOff();
    $this->app['env'] = 'staging';

    expect(app()->isProduction())->toBeFalse()
        ->and(TwoFactorService::isEnforced())->toBeFalse();

    // Recorded deliberately: the guard is "not production", so a staging deployment that sets
    // AUTH_TWO_FACTOR_ENFORCED=false does get password-only sign-in. Changing this line is the
    // signal that the environment rule itself changed.
})->group('phase0');

// ---------------------------------------------------------------------------
// 4. The switch weakens nothing else.
// ---------------------------------------------------------------------------

it('still refuses an inactive user while the switch is off', function () {
    switchTwoFactorOff();

    User::where('email', 'shahadat@goodtechies.test')->update(['status' => UserStatus::Inactive->value]);

    $this->post('/login', ['email' => 'shahadat@goodtechies.test', 'password' => $this->password])
        ->assertSessionHasErrors(['email' => 'This account is inactive.']);

    $this->assertGuest();
})->group('phase0');

it('still checks the password while the switch is off', function () {
    switchTwoFactorOff();

    $this->post('/login', ['email' => 'shahadat@goodtechies.test', 'password' => 'wrong-password'])
        ->assertSessionHasErrors(['email' => 'These credentials do not match our records.']);

    $this->assertGuest();
})->group('phase0');

it('still applies the surface middleware while the switch is off', function () {
    switchTwoFactorOff();

    $this->post('/login', ['email' => 'shahadat@goodtechies.test', 'password' => $this->password]);
    $this->assertAuthenticatedAs($this->admin);

    $this->get('/employee/dashboard')->assertForbidden();
    $this->get('/accountant/dashboard')->assertForbidden();
})->group('phase0');

it('still throttles login attempts while the switch is off', function () {
    switchTwoFactorOff();

    foreach (range(1, 5) as $attempt) {
        $this->post('/login', ['email' => 'shahadat@goodtechies.test', 'password' => 'wrong-password'])
            ->assertRedirect();
    }

    $this->post('/login', ['email' => 'shahadat@goodtechies.test', 'password' => 'wrong-password'])
        ->assertTooManyRequests();
})->group('phase0');
