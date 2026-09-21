<?php

use App\Models\AuditLog;
use App\Models\LoginHistory;
use App\Models\User;
use App\Services\TwoFactorService;
use App\Support\AuditEvent;
use App\Support\UserStatus;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed();
    $this->password = env('SEED_PASSWORD');
});

it('renders the login page for a guest', function () {
    $this->withSession(['status' => 'Welcome back.'])
        ->get('/login')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Auth/Login')
            ->where('status', 'Welcome back.'));
})->group('phase0');

it('logs a seeded employee in and lands them in the employee shell', function () {
    $this->post('/login', ['email' => 'Yaseen@GoodTechies.test', 'password' => $this->password])
        ->assertRedirect('/');

    $yaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();
    $this->assertAuthenticatedAs($yaseen);

    $this->get('/')->assertRedirect('/employee/dashboard');
})->group('phase0');

it('writes exactly one user.login audit row on a successful login', function () {
    $this->post('/login', ['email' => 'yaseen@goodtechies.test', 'password' => $this->password]);

    $yaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();

    expect(AuditLog::where('event', AuditEvent::UserLogin->value)->count())->toBe(1)
        ->and(LoginHistory::where('user_id', $yaseen->id)->where('succeeded', true)->count())->toBe(1);
})->group('phase0');

it('rejects a wrong password with one failed login_history row', function () {
    $this->from('/login')
        ->post('/login', ['email' => 'yaseen@goodtechies.test', 'password' => 'wrong-password'])
        ->assertRedirect('/login')
        ->assertSessionHasErrors(['email' => 'These credentials do not match our records.']);

    $yaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();

    expect(LoginHistory::where('succeeded', false)->count())->toBe(1)
        ->and(LoginHistory::where('succeeded', false)->first()->user_id)->toBe($yaseen->id)
        ->and(AuditLog::count())->toBe(0);
    $this->assertGuest();
})->group('phase0');

it('rejects an unknown email with a failed row that has no user', function () {
    $this->post('/login', ['email' => 'nobody@goodtechies.test', 'password' => 'whatever-password'])
        ->assertSessionHasErrors(['email' => 'These credentials do not match our records.']);

    $row = LoginHistory::sole();

    expect($row->succeeded)->toBeFalse()
        ->and($row->user_id)->toBeNull()
        ->and($row->email)->toBe('nobody@goodtechies.test');
    $this->assertGuest();
})->group('phase0');

it('refuses an inactive user with the right password', function () {
    User::where('email', 'yaseen@goodtechies.test')->update(['status' => UserStatus::Inactive->value]);

    $this->post('/login', ['email' => 'yaseen@goodtechies.test', 'password' => $this->password])
        ->assertSessionHasErrors(['email' => 'This account is inactive.']);

    $this->assertGuest();
    expect(LoginHistory::where('succeeded', true)->count())->toBe(0);
})->group('phase0');

it('throttles the sixth login attempt within a minute', function () {
    foreach (range(1, 5) as $attempt) {
        $this->post('/login', ['email' => 'yaseen@goodtechies.test', 'password' => 'wrong-password'])
            ->assertRedirect();
    }

    $this->post('/login', ['email' => 'yaseen@goodtechies.test', 'password' => 'wrong-password'])
        ->assertTooManyRequests();
})->group('phase0');

it('throttles the twenty-first hourly attempt even from fresh addresses', function () {
    // One attempt per address, so only the hourly limit keyed by the address can fire.
    foreach (range(1, 20) as $attempt) {
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.'.$attempt])
            ->post('/login', ['email' => 'yaseen@goodtechies.test', 'password' => 'wrong-password'])
            ->assertRedirect();
    }

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.21'])
        ->post('/login', ['email' => 'yaseen@goodtechies.test', 'password' => 'wrong-password'])
        ->assertTooManyRequests();

    // A different address is only throttled together with its own account.
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.21'])
        ->post('/login', ['email' => 'tapu@goodtechies.test', 'password' => 'wrong-password'])
        ->assertRedirect();
})->group('phase0');

it('sends an admin with 2FA to the challenge without logging them in', function () {
    $admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();

    $this->startSession();
    $preAuthSessionId = session()->getId();

    $this->post('/login', ['email' => 'shahadat@goodtechies.test', 'password' => $this->password, 'remember' => true])
        ->assertRedirect('/two-factor/challenge')
        ->assertSessionHas(TwoFactorService::PENDING_LOGIN_SESSION_KEY, $admin->id)
        ->assertSessionHas('login.remember', true);

    $this->assertGuest();
    // The pending keys must not sit on the session id the password step arrived with.
    expect(session()->getId())->not->toBe($preAuthSessionId)
        ->and(LoginHistory::count())->toBe(0);
})->group('phase0');

it('logs the user out', function () {
    $yaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();

    $this->actingAs($yaseen)->post('/logout')->assertRedirect('/login');

    $this->assertGuest();
})->group('phase0');

it('redirects an authenticated user away from the login page', function () {
    $yaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();

    $this->actingAs($yaseen)->get('/login')->assertRedirect('/');
})->group('phase0');
