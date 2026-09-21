<?php

use App\Models\User;
use Illuminate\Support\Facades\App;
use PragmaRX\Google2FA\Google2FA;

/*
|--------------------------------------------------------------------------
| hq:two-factor-code
|--------------------------------------------------------------------------
|
| A development convenience: print the six-digit code a user's authenticator
| would be showing. The one rule that matters is that it refuses in
| production, where printing a live second factor to a terminal would defeat
| the point of having one.
|
*/

beforeEach(function () {
    $this->seed();
});

it('prints a code that actually verifies', function () {
    $user = User::whereNotNull('two_factor_secret')->orderBy('id')->firstOrFail();

    // Artisan::call, not $this->artisan: the latter returns a PendingCommand whose
    // output never reaches Artisan::output(), so the assertion below would read an
    // empty string and pass or fail for the wrong reason.
    $exit = Artisan::call('hq:two-factor-code', ['user' => $user->email]);
    expect($exit)->toBe(0);

    // Not just "six digits appeared" — the printed code must be the one the
    // verifier accepts, or the command is worse than useless.
    preg_match('/\b(\d{6})\b/', trim(Artisan::output()), $matches);

    expect($matches[1] ?? null)->not->toBeNull()
        ->and(app(Google2FA::class)->verifyKey($user->two_factor_secret, $matches[1]))->not->toBeFalse();
})->group('auth');

it('refuses to run in production', function () {
    $user = User::whereNotNull('two_factor_secret')->orderBy('id')->firstOrFail();

    App::detectEnvironment(fn () => 'production');

    expect(App::isProduction())->toBeTrue();

    $exit = Artisan::call('hq:two-factor-code', ['user' => $user->email]);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('Not available in production')
        // And it printed no code on the way out.
        ->and(Artisan::output())->not->toMatch('/\b\d{6}\b/');
})->group('auth');

it('says so when the named user has no secret rather than printing nothing', function () {
    $user = User::whereNull('two_factor_secret')->orderBy('id')->firstOrFail();

    $this->artisan('hq:two-factor-code', ['user' => $user->email])
        ->expectsOutputToContain('no two-factor secret')
        ->assertFailed();
})->group('auth');

it('reports an unknown user instead of falling back to someone else', function () {
    $this->artisan('hq:two-factor-code', ['user' => 'nobody@goodtechies.test'])
        ->expectsOutputToContain('No user matches')
        ->assertFailed();
})->group('auth');
