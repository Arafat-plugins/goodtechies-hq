<?php

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LoginHistory;
use App\Models\User;
use App\Support\AuditEvent;
use App\Support\RoleName;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

const PWNED_PASSWORD = 'correct-horse-battery-staple';

beforeEach(function () {
    $this->seed();
    $this->yaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();
    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();

    // The k-anonymity range API reports PWNED_PASSWORD as breached and nothing else.
    Http::preventStrayRequests();
    Http::fake([
        'api.pwnedpasswords.com/range/*' => function (HttpRequest $request) {
            $hash = strtoupper(sha1(PWNED_PASSWORD));
            $body = str_ends_with($request->url(), '/'.substr($hash, 0, 5))
                ? substr($hash, 5).":42\r\n0018A45C4D1DEF81644B54AB7F969B88D65:1"
                : '0018A45C4D1DEF81644B54AB7F969B88D65:1';

            return Http::response($body);
        },
    ]);
});

function insertProfileSession(string $id, int $userId, int $lastActivity): void
{
    DB::table('sessions')->insert([
        'id' => $id,
        'user_id' => $userId,
        'ip_address' => '198.51.100.7',
        'user_agent' => 'PestAgent',
        'payload' => base64_encode('a:0:{}'),
        'last_activity' => $lastActivity,
    ]);
}

function employeeWithTwoFactor(): User
{
    return Employee::factory()
        ->forRole(RoleName::EMPLOYEE)
        ->create(['user_id' => User::factory()->withTwoFactor('JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP')])
        ->user;
}

it('renders the profile page with the documented props', function () {
    $this->actingAs($this->admin)
        ->get('/profile')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Shared/Profile')
            ->where('profile', [
                'name' => 'Shahadat Hossain',
                'email' => 'shahadat@goodtechies.test',
                'timezone' => 'Asia/Dhaka',
            ])
            ->where('timezones', DateTimeZone::listIdentifiers())
            ->where('twoFactor', ['enabled' => true, 'required' => true, 'recoveryCodesLeft' => 8])
            ->has('sessions', 0)
            ->has('loginHistory', 0));
})->group('phase0');

it('updates the profile', function () {
    $this->actingAs($this->yaseen)
        ->put('/profile', ['name' => 'Yaseen Arafat', 'email' => 'Yaseen.New@GoodTechies.test', 'timezone' => 'Europe/London'])
        ->assertRedirect('/profile')
        ->assertSessionHas('success');

    $fresh = $this->yaseen->fresh();

    expect($fresh->name)->toBe('Yaseen Arafat')
        ->and($fresh->email)->toBe('yaseen.new@goodtechies.test')
        ->and($fresh->timezone)->toBe('Europe/London');
})->group('phase0');

it('validates the profile update', function () {
    $this->actingAs($this->yaseen)
        ->put('/profile', ['name' => '', 'email' => 'not-an-email', 'timezone' => 'Mars/Olympus'])
        ->assertSessionHasErrors(['name', 'email', 'timezone']);

    $this->actingAs($this->yaseen)
        ->put('/profile', ['name' => str_repeat('a', 256), 'email' => 'Tapu@goodtechies.test', 'timezone' => 'Asia/Dhaka'])
        ->assertSessionHasErrors(['name', 'email']);

    // Keeping one's own email is not a uniqueness conflict.
    $this->actingAs($this->yaseen)
        ->put('/profile', ['name' => 'Yaseen', 'email' => 'yaseen@goodtechies.test', 'timezone' => 'Asia/Dhaka'])
        ->assertSessionHasNoErrors();

    expect($this->yaseen->fresh()->email)->toBe('yaseen@goodtechies.test');
})->group('phase0');

it('requires the current password to change the password', function () {
    $this->actingAs($this->yaseen)
        ->put('/profile/password', [
            'current_password' => 'wrong-password',
            'password' => 'a-brand-new-long-passphrase',
            'password_confirmation' => 'a-brand-new-long-passphrase',
        ])
        ->assertSessionHasErrors(['current_password']);

    expect(Hash::check(env('SEED_PASSWORD'), $this->yaseen->fresh()->password))->toBeTrue();
})->group('phase0');

it('rejects a short or breached new password', function (string $password) {
    $this->actingAs($this->yaseen)
        ->put('/profile/password', [
            'current_password' => env('SEED_PASSWORD'),
            'password' => $password,
            'password_confirmation' => $password,
        ])
        ->assertSessionHasErrors(['password']);

    expect(Hash::check(env('SEED_PASSWORD'), $this->yaseen->fresh()->password))->toBeTrue();
})->with([
    'shorter than 12' => ['short-pass1'],
    'breached' => [PWNED_PASSWORD],
])->group('phase0');

it('changes the password and ends the other sessions', function () {
    config(['session.driver' => 'database']);

    insertProfileSession(Str::random(40), $this->yaseen->id, now()->subHour()->timestamp);
    insertProfileSession(Str::random(40), $this->yaseen->id, now()->subHours(2)->timestamp);
    insertProfileSession(Str::random(40), $this->admin->id, now()->timestamp);
    $oldToken = $this->yaseen->remember_token;

    $this->actingAs($this->yaseen)
        ->put('/profile/password', [
            'current_password' => env('SEED_PASSWORD'),
            'password' => 'a-brand-new-long-passphrase',
            'password_confirmation' => 'a-brand-new-long-passphrase',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect('/profile');

    $fresh = $this->yaseen->fresh();

    expect(Hash::check('a-brand-new-long-passphrase', $fresh->password))->toBeTrue()
        ->and($fresh->remember_token)->not->toBe($oldToken)
        // Only the session of the request itself remains for Yaseen; the admin's is untouched.
        ->and(DB::table('sessions')->where('user_id', $this->yaseen->id)->count())->toBe(1)
        ->and(DB::table('sessions')->where('user_id', $this->admin->id)->count())->toBe(1);
})->group('phase0');

it('refuses to disable 2FA for an admin', function () {
    $this->actingAs($this->admin)
        ->delete('/profile/two-factor', ['password' => env('SEED_PASSWORD')])
        ->assertForbidden();

    expect($this->admin->fresh()->hasConfirmedTwoFactor())->toBeTrue();
})->group('phase0');

it('lets an employee disable 2FA with their password and audits it', function () {
    $user = employeeWithTwoFactor();

    $this->actingAs($user)
        ->delete('/profile/two-factor', ['password' => 'wrong-password'])
        ->assertSessionHasErrors(['password']);
    expect($user->fresh()->hasConfirmedTwoFactor())->toBeTrue();

    $this->actingAs($user)
        ->delete('/profile/two-factor', ['password' => 'password'])
        ->assertRedirect('/profile')
        ->assertSessionHas('success');

    expect($user->fresh()->hasConfirmedTwoFactor())->toBeFalse()
        ->and(AuditLog::where('event', AuditEvent::TwoFactorDisabled->value)->count())->toBe(1);
})->group('phase0');

it('regenerates recovery codes only with the password', function () {
    $user = employeeWithTwoFactor();
    $oldHashes = $user->two_factor_recovery_codes;

    $this->actingAs($user)
        ->post('/profile/two-factor/recovery-codes', [])
        ->assertSessionHasErrors(['password']);
    $this->actingAs($user)
        ->post('/profile/two-factor/recovery-codes', ['password' => 'wrong-password'])
        ->assertSessionHasErrors(['password']);

    expect($user->fresh()->two_factor_recovery_codes)->toBe($oldHashes);

    $this->actingAs($user)
        ->post('/profile/two-factor/recovery-codes', ['password' => 'password'])
        ->assertRedirect('/two-factor/recovery-codes');

    $codes = $this->get('/two-factor/recovery-codes')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Auth/RecoveryCodes')->has('codes', 8))
        ->inertiaProps('codes');

    $hashes = $user->fresh()->two_factor_recovery_codes;
    expect($hashes)->not->toBe($oldHashes)
        ->and(Hash::check($codes[0], $hashes[0]))->toBeTrue();
})->group('phase0');

it('does not regenerate recovery codes without 2FA', function () {
    $this->actingAs($this->yaseen)
        ->post('/profile/two-factor/recovery-codes', ['password' => env('SEED_PASSWORD')])
        ->assertRedirect('/profile')
        ->assertSessionHas('error');
})->group('phase0');

it('lists sessions and revokes one of the user\'s other sessions', function () {
    config(['session.driver' => 'database']);
    $currentId = Str::random(40);
    $otherId = Str::random(40);
    insertProfileSession($currentId, $this->yaseen->id, now()->timestamp);
    insertProfileSession($otherId, $this->yaseen->id, now()->subHour()->timestamp);

    $this->actingAs($this->yaseen)
        ->withCookie(config('session.cookie'), $currentId)
        ->get('/profile')
        ->assertInertia(fn (Assert $page) => $page
            ->has('sessions', 2)
            ->where('sessions.0.id', $currentId)
            ->where('sessions.0.isCurrent', true)
            ->where('sessions.1.id', $otherId)
            ->where('sessions.1.isCurrent', false)
            ->where('sessions.1.ipAddress', '198.51.100.7')
            ->where('sessions.1.userAgent', 'PestAgent')
            ->has('sessions.1.lastActiveAt'));

    $this->actingAs($this->yaseen)
        ->withCookie(config('session.cookie'), $currentId)
        ->delete("/profile/sessions/{$otherId}")
        ->assertRedirect('/profile')
        ->assertSessionHas('success');

    expect(DB::table('sessions')->where('id', $otherId)->exists())->toBeFalse();
})->group('phase0');

it('refuses to revoke the current session', function () {
    config(['session.driver' => 'database']);
    $currentId = Str::random(40);
    insertProfileSession($currentId, $this->yaseen->id, now()->timestamp);

    $this->actingAs($this->yaseen)
        ->withCookie(config('session.cookie'), $currentId)
        ->delete("/profile/sessions/{$currentId}")
        ->assertSessionHasErrors(['session']);

    expect(DB::table('sessions')->where('id', $currentId)->exists())->toBeTrue();
})->group('phase0');

it('returns 404 for another user\'s or an unknown session', function () {
    $adminSession = Str::random(40);
    insertProfileSession($adminSession, $this->admin->id, now()->timestamp);

    $this->actingAs($this->yaseen)->delete("/profile/sessions/{$adminSession}")->assertNotFound();
    $this->actingAs($this->yaseen)->delete('/profile/sessions/'.Str::random(40))->assertNotFound();

    expect(DB::table('sessions')->where('id', $adminSession)->exists())->toBeTrue();
})->group('phase0');

it('shows only the last 20 login history rows, newest first', function () {
    foreach (range(1, 25) as $i) {
        $row = LoginHistory::create([
            'user_id' => $this->yaseen->id,
            'email' => $this->yaseen->email,
            'ip' => "203.0.113.{$i}",
            'user_agent' => 'PestAgent',
            'succeeded' => $i % 2 === 0,
        ]);
        $row->forceFill(['created_at' => now()->subMinutes(100 - $i)])->save();
    }
    LoginHistory::create(['user_id' => $this->admin->id, 'email' => $this->admin->email, 'ip' => '192.0.2.1', 'succeeded' => true]);

    $this->actingAs($this->yaseen)
        ->get('/profile')
        ->assertInertia(fn (Assert $page) => $page
            ->has('loginHistory', 20)
            ->where('loginHistory.0.ip', '203.0.113.25')
            ->where('loginHistory.0.succeeded', false)
            ->where('loginHistory.0.userAgent', 'PestAgent')
            ->has('loginHistory.0.at')
            ->where('loginHistory.19.ip', '203.0.113.6'));
})->group('phase0');
