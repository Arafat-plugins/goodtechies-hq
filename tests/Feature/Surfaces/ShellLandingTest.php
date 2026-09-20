<?php

use App\Models\Employee;
use App\Models\User;
use App\Support\UserStatus;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Arr;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PragmaRX\Google2FA\Google2FA;

const FORBIDDEN_PAYLOAD_KEYS = ['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'];

beforeEach(function () {
    $this->seed();
});

/**
 * Log in through the real HTTP flow (password, then TOTP when the user has 2FA).
 */
function loginThroughForms(object $test, string $email): User
{
    $user = User::where('email', $email)->firstOrFail();

    $test->post('/login', ['email' => $email, 'password' => env('SEED_PASSWORD')]);

    if ($user->hasConfirmedTwoFactor()) {
        $test->post('/two-factor/challenge', [
            'code' => app(Google2FA::class)->getCurrentOtp(env('SEED_TWO_FACTOR_SECRET')),
        ]);
    }

    $test->assertAuthenticatedAs($user);

    return $user;
}

/**
 * Fails when any key at any depth of the Inertia page object is a secret-bearing key.
 */
function assertNoSecretsInPayload(TestResponse $response): void
{
    $keys = array_map(
        fn (string $path): string => (string) last(explode('.', $path)),
        array_keys(Arr::dot($response->inertiaPage())),
    );

    foreach (FORBIDDEN_PAYLOAD_KEYS as $forbidden) {
        expect($keys)->not->toContain($forbidden);
    }
}

it('lands each seeded role in its own shell', function (string $email, string $home, string $component) {
    loginThroughForms($this, $email);

    $this->get('/')->assertRedirect($home);

    $response = $this->get($home)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component($component)
            ->has('greetingName')
            ->where('today', now(config('app.timezone'))->toDateString()));

    assertNoSecretsInPayload($response);
})->with([
    'admin' => ['shahadat@goodtechies.test', '/admin/dashboard', 'Admin/Dashboard'],
    'accountant' => ['accountant@goodtechies.test', '/accountant/dashboard', 'Accountant/Dashboard'],
    'employee' => ['yaseen@goodtechies.test', '/employee/dashboard', 'Employee/Dashboard'],
    'remote employee' => ['tapu@goodtechies.test', '/employee/dashboard', 'Employee/Dashboard'],
])->group('phase0');

it('gives each dashboard exactly its documented props', function (string $email, string $home, array $props) {
    loginThroughForms($this, $email);

    $response = $this->get($home)->assertOk();
    $pageProps = array_keys($response->inertiaProps());
    $shared = ['errors', 'auth', 'flash', 'app'];

    expect(array_values(array_diff($pageProps, $shared)))->toEqualCanonicalizing($props);
})->with([
    'admin' => ['shahadat@goodtechies.test', '/admin/dashboard', ['greetingName', 'today', 'stats']],
    'accountant' => ['accountant@goodtechies.test', '/accountant/dashboard', ['greetingName', 'today']],
    'employee' => ['yaseen@goodtechies.test', '/employee/dashboard', ['greetingName', 'today', 'trackingMode']],
])->group('phase0');

it('passes the greeting name and tracking mode to the employee dashboard', function (string $email, string $name, string $mode) {
    loginThroughForms($this, $email);

    $this->get('/employee/dashboard')
        ->assertInertia(fn (Assert $page) => $page
            ->where('greetingName', $name)
            ->where('trackingMode', $mode));
})->with([
    'remote' => ['tapu@goodtechies.test', 'Tapu', 'remote_timer'],
    'office' => ['yaseen@goodtechies.test', 'Yaseen', 'office_attendance'],
])->group('phase0');

it('shares only the documented auth.user keys', function () {
    $admin = loginThroughForms($this, 'shahadat@goodtechies.test');

    $response = $this->get('/admin/dashboard')
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.user', [
                'id' => $admin->id,
                'name' => 'Shahadat Hossain',
                'email' => 'shahadat@goodtechies.test',
                'role' => 'ADMIN',
                'surface' => 'admin',
                'trackingMode' => 'office_attendance',
                'twoFactorEnabled' => true,
            ])
            ->where('greetingName', 'Shahadat')
            ->where('app.name', config('app.name'))
            ->where('flash.success', null)
            ->where('flash.error', null));

    expect(array_keys($response->inertiaProps('auth.user')))
        ->toBe(['id', 'name', 'email', 'role', 'surface', 'trackingMode', 'twoFactorEnabled']);
    assertNoSecretsInPayload($response);
})->group('phase0');

it('shares a null auth.user with guests', function () {
    $this->get('/login')->assertInertia(fn (Assert $page) => $page->where('auth.user', null));
})->group('phase0');

it('counts active employees for the admin dashboard from the database', function () {
    Employee::factory()->count(2)->create();
    Employee::factory()->create(['status' => UserStatus::Inactive]);

    loginThroughForms($this, 'shahadat@goodtechies.test');

    $expected = Employee::where('status', UserStatus::Active)->count();
    expect($expected)->toBe(7);

    $this->get('/admin/dashboard')
        ->assertInertia(fn (Assert $page) => $page->where('stats', ['activeEmployees' => $expected]));
})->group('phase0');

it('lists every settings key in order on the admin settings page', function () {
    loginThroughForms($this, 'shahadat@goodtechies.test');

    $response = $this->get('/admin/settings')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Settings')
            ->where('backup.lastVerifiedAt', null)
            ->where('drivers.realtime', config('broadcasting.default'))
            ->where('drivers.googleCalendar', 'manual'));

    $settings = $response->inertiaProps('settings');

    expect(array_column($settings, 'key'))->toBe(array_keys(SettingsSeeder::DEFAULTS))
        ->and($settings[0])->toBe(['key' => 'timezone', 'value' => 'Asia/Dhaka']);
    assertNoSecretsInPayload($response);
})->group('phase0');

it('keeps secrets out of the profile page', function (string $email) {
    loginThroughForms($this, $email);

    assertNoSecretsInPayload($this->get('/profile')->assertOk());
})->with([
    'admin' => ['shahadat@goodtechies.test'],
    'employee' => ['yaseen@goodtechies.test'],
])->group('phase0');
