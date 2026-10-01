<?php

use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use App\Support\AuditEvent;
use App\Support\UserStatus;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Inertia\Testing\AssertableInertia as Assert;

const RESET_NEW_PASSWORD = 'a-brand-new-long-passphrase';

beforeEach(function () {
    $this->seed();
    $this->yaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();

    Notification::fake();

    // The breach check (Password::defaults()) is answered locally: nothing is breached.
    Http::preventStrayRequests();
    Http::fake([
        'api.pwnedpasswords.com/range/*' => fn (HttpRequest $request) => Http::response('0018A45C4D1DEF81644B54AB7F969B88D65:1'),
    ]);
});

function insertResetSession(string $id, int $userId): void
{
    DB::table('sessions')->insert([
        'id' => $id,
        'user_id' => $userId,
        'ip_address' => '198.51.100.7',
        'user_agent' => 'PestAgent',
        'payload' => base64_encode('a:0:{}'),
        'last_activity' => now()->getTimestamp(),
    ]);
}

function resetPayload(string $token, string $email, string $password = RESET_NEW_PASSWORD): array
{
    return [
        'token' => $token,
        'email' => $email,
        'password' => $password,
        'password_confirmation' => $password,
    ];
}

it('renders the forgot-password page for a guest', function () {
    $this->get('/forgot-password')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Auth/ForgotPassword'));
});

it('sends a reset link to an active user and flashes the neutral sentence', function () {
    $this->from('/forgot-password')
        ->post('/forgot-password', ['email' => ' Yaseen@GoodTechies.test '])
        ->assertRedirect('/forgot-password')
        ->assertSessionHas('status', PasswordResetLinkController::STATUS);

    Notification::assertSentTo($this->yaseen, ResetPasswordNotification::class);
    Notification::assertCount(1);
});

it('gives an unknown email the same sentence and sends nothing', function () {
    $this->from('/forgot-password')
        ->post('/forgot-password', ['email' => 'nobody@goodtechies.test'])
        ->assertRedirect('/forgot-password')
        ->assertSessionHas('status', PasswordResetLinkController::STATUS);

    Notification::assertNothingSent();
});

it('gives an inactive user the same sentence and sends nothing', function () {
    $this->yaseen->update(['status' => UserStatus::Inactive]);

    $this->from('/forgot-password')
        ->post('/forgot-password', ['email' => 'yaseen@goodtechies.test'])
        ->assertRedirect('/forgot-password')
        ->assertSessionHas('status', PasswordResetLinkController::STATUS);

    Notification::assertNothingSent();
});

it('puts a /reset-password/ link carrying the email in the mail', function () {
    $mail = (new ResetPasswordNotification('test-token'))->toMail($this->yaseen);

    expect($mail->subject)->toBe('Reset your goodERP password')
        ->and($mail->actionText)->toBe('Reset password')
        ->and($mail->actionUrl)->toContain('/reset-password/test-token')
        ->and($mail->actionUrl)->toContain('email='.urlencode('yaseen@goodtechies.test'));
});

it('resets the password with a valid token, ends sessions and writes one audit row', function () {
    $token = Password::broker()->createToken($this->yaseen);
    $oldRemember = $this->yaseen->remember_token;
    insertResetSession('session-a', $this->yaseen->id);
    insertResetSession('session-b', $this->yaseen->id);

    $this->post('/reset-password', resetPayload($token, 'yaseen@goodtechies.test'))
        ->assertRedirect('/login')
        ->assertSessionHas('status', 'Your password has been changed. Sign in with the new one.');

    $fresh = $this->yaseen->fresh();

    expect(Hash::check(RESET_NEW_PASSWORD, $fresh->password))->toBeTrue()
        ->and($fresh->remember_token)->not->toBe($oldRemember)
        ->and(DB::table('sessions')->where('user_id', $this->yaseen->id)->count())->toBe(0)
        ->and(AuditLog::where('event', AuditEvent::PasswordResetByEmail->value)->count())->toBe(1);
});

it('rejects a wrong token and leaves the password alone', function () {
    Password::broker()->createToken($this->yaseen);
    $oldHash = $this->yaseen->password;

    $this->from('/reset-password/wrong-token')
        ->post('/reset-password', resetPayload('wrong-token', 'yaseen@goodtechies.test'))
        ->assertSessionHasErrors(['email' => 'This reset link is invalid or has expired. Ask for a new one.']);

    expect($this->yaseen->fresh()->password)->toBe($oldHash);
});

it('accepts a token only once', function () {
    $token = Password::broker()->createToken($this->yaseen);

    $this->post('/reset-password', resetPayload($token, 'yaseen@goodtechies.test'))
        ->assertRedirect('/login');

    $this->from('/reset-password/'.$token)
        ->post('/reset-password', resetPayload($token, 'yaseen@goodtechies.test', 'another-long-passphrase'))
        ->assertSessionHasErrors('email');

    expect(Hash::check(RESET_NEW_PASSWORD, $this->yaseen->fresh()->password))->toBeTrue();
});

it('answers the sixth forgot-password post in a minute from one IP with 429', function () {
    foreach (range(1, 5) as $i) {
        $this->post('/forgot-password', ['email' => "nobody{$i}@goodtechies.test"])
            ->assertRedirect();
    }

    $this->post('/forgot-password', ['email' => 'nobody6@goodtechies.test'])
        ->assertStatus(429);
});

it('does not sign the user in after a reset', function () {
    $token = Password::broker()->createToken($this->yaseen);

    $this->post('/reset-password', resetPayload($token, 'yaseen@goodtechies.test'))
        ->assertRedirect('/login');

    $this->assertGuest();
});
