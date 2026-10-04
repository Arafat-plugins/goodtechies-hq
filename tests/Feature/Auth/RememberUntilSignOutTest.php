<?php

use App\Models\Employee;
use App\Models\User;
use App\Support\RoleName;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Stay signed in until signing out — and nothing longer (decision 12-89)
|--------------------------------------------------------------------------
|
| "Remember me" lasts 400 days and one token serves every device. Ending a session from Profile
| (a lost phone) and changing the password must still sign the OTHER devices out for good, and
| must keep the device in hand signed in.
|
*/

function rememberUser(): User
{
    $user = User::factory()->create();
    Employee::factory()->forRole(RoleName::EMPLOYEE)->create(['user_id' => $user->id]);

    return $user->fresh();
}

/** The remember cookie a returning browser sends, for this user's token at the time. */
function rememberCookieValue(User $user): string
{
    return $user->id.'|'.$user->getRememberToken().'|'.$user->password;
}

it('ending another session stops that device signing back in with its remember cookie, and keeps this one remembered', function () {
    config(['session.driver' => 'database']);
    $user = rememberUser();
    $user->forceFill(['remember_token' => Str::random(60)])->save();
    $lostPhoneCookie = rememberCookieValue($user->fresh());

    $current = Str::random(40);
    $other = Str::random(40);
    foreach ([$current, $other] as $id) {
        DB::table('sessions')->insert(['id' => $id, 'user_id' => $user->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'Pest', 'payload' => base64_encode('{}'), 'last_activity' => now()->timestamp]);
    }

    $response = $this->actingAs($user)
        ->withCookie(config('session.cookie'), $current)
        ->delete("/profile/sessions/{$other}")
        ->assertRedirect('/profile');

    $fresh = $user->fresh();
    $recaller = Auth::guard('web')->getRecallerName();

    // This device got a fresh remember cookie for the NEW token.
    $response->assertCookie($recaller);
    expect($fresh->getRememberToken())->not->toBe(explode('|', $lostPhoneCookie)[1]);

    // The lost phone's old cookie no longer signs anybody in.
    Auth::forgetGuards();
    $this->flushSession();
    $this->withCookie($recaller, $lostPhoneCookie)->get('/profile')->assertRedirect('/login');
});

it('changing the password keeps this device remembered', function () {
    $user = rememberUser();

    $this->actingAs($user)
        ->put('/profile/password', [
            'current_password' => 'password',
            'password' => 'a-brand-new-long-passphrase',
            'password_confirmation' => 'a-brand-new-long-passphrase',
        ])
        ->assertRedirect('/profile')
        ->assertCookie(Auth::guard('web')->getRecallerName());
});
