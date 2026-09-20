<?php

use App\Models\Employee;
use App\Models\LoginHistory;
use App\Models\User;
use App\Services\TwoFactorService;
use App\Support\RoleName;
use App\Support\UserStatus;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;
use PragmaRX\Google2FA\Google2FA;

beforeEach(function () {
    $this->seed();
    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
});

function passPasswordStep(object $test, string $email, ?string $password = null): void
{
    $test->post('/login', ['email' => $email, 'password' => $password ?? env('SEED_PASSWORD')])
        ->assertRedirect('/two-factor/challenge');
}

it('renders the challenge page while a login is pending', function () {
    passPasswordStep($this, 'shahadat@goodtechies.test');

    $this->get('/two-factor/challenge')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Auth/TwoFactorChallenge'));
})->group('phase0');

it('logs the admin in with a valid TOTP code and lands on the admin dashboard', function () {
    passPasswordStep($this, 'shahadat@goodtechies.test');

    $code = app(Google2FA::class)->getCurrentOtp(env('SEED_TWO_FACTOR_SECRET'));

    $this->post('/two-factor/challenge', ['code' => $code])
        ->assertRedirect('/')
        ->assertSessionMissing(TwoFactorService::PENDING_LOGIN_SESSION_KEY);

    $this->assertAuthenticatedAs($this->admin);
    expect(LoginHistory::where('user_id', $this->admin->id)->where('succeeded', true)->count())->toBe(1);

    $this->get('/')->assertRedirect('/admin/dashboard');
    $this->get('/admin/dashboard')->assertOk();
})->group('phase0');

it('rejects an invalid code and keeps the user a guest', function () {
    passPasswordStep($this, 'shahadat@goodtechies.test');

    $this->from('/two-factor/challenge')
        ->post('/two-factor/challenge', ['code' => '000000'])
        ->assertRedirect('/two-factor/challenge')
        ->assertSessionHasErrors(['code' => 'The code is invalid.']);

    $this->assertGuest();
})->group('phase0');

it('requires a code or a recovery code', function () {
    passPasswordStep($this, 'shahadat@goodtechies.test');

    $this->post('/two-factor/challenge', [])->assertSessionHasErrors(['code', 'recovery_code']);

    $this->assertGuest();
})->group('phase0');

it('logs in with a recovery code and consumes it', function () {
    $user = Employee::factory()->forRole(RoleName::EMPLOYEE)->create()->user;
    $service = app(TwoFactorService::class);
    $secret = $service->beginEnrolment($user);
    $codes = $service->confirmEnrolment($user, app(Google2FA::class)->getCurrentOtp($secret));
    Cache::flush();

    passPasswordStep($this, $user->email, 'password');

    $this->post('/two-factor/challenge', ['recovery_code' => $codes[0]])->assertRedirect('/');

    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->two_factor_recovery_codes)->toHaveCount(7);

    // The same code cannot be used twice.
    $this->post('/logout');
    passPasswordStep($this, $user->email, 'password');
    $this->post('/two-factor/challenge', ['recovery_code' => $codes[0]])
        ->assertSessionHasErrors(['code' => 'The code is invalid.']);
    $this->assertGuest();
})->group('phase0');

it('redirects the challenge page to login without a pending login', function () {
    $this->get('/two-factor/challenge')->assertRedirect('/login');
    $this->post('/two-factor/challenge', ['code' => '123456'])->assertRedirect('/login');

    $this->assertGuest();
})->group('phase0');

it('sends a pending user who became inactive back to login', function () {
    passPasswordStep($this, 'shahadat@goodtechies.test');
    $this->admin->forceFill(['status' => UserStatus::Inactive])->save();

    $code = app(Google2FA::class)->getCurrentOtp(env('SEED_TWO_FACTOR_SECRET'));

    $this->post('/two-factor/challenge', ['code' => $code])
        ->assertRedirect('/login')
        ->assertSessionMissing(TwoFactorService::PENDING_LOGIN_SESSION_KEY);

    $this->assertGuest();
})->group('phase0');

it('throttles the challenge after five attempts', function () {
    passPasswordStep($this, 'shahadat@goodtechies.test');

    foreach (range(1, 5) as $attempt) {
        $this->post('/two-factor/challenge', ['code' => '000000'])->assertRedirect();
    }

    $this->post('/two-factor/challenge', ['code' => '000000'])->assertTooManyRequests();
    $this->assertGuest();
})->group('phase0');
