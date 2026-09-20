<?php

use App\Models\Employee;
use App\Models\User;
use App\Support\RoleName;
use Inertia\Testing\AssertableInertia as Assert;
use PragmaRX\Google2FA\Google2FA;

beforeEach(function () {
    $this->seed();
});

function userWithoutTwoFactor(RoleName $role): User
{
    return Employee::factory()->forRole($role)->create()->user;
}

it('forces an admin or accountant without 2FA to enrol on every surface and profile route', function (RoleName $role, string $home) {
    $user = userWithoutTwoFactor($role);

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/');
    $this->assertAuthenticatedAs($user);

    foreach ([
        ['get', $home],
        ['get', '/profile'],
        ['put', '/profile'],
        ['put', '/profile/password'],
        ['delete', '/profile/two-factor'],
        ['post', '/profile/two-factor/recovery-codes'],
        ['delete', '/profile/sessions/some-session'],
    ] as [$method, $uri]) {
        $this->{$method}($uri)->assertRedirect('/two-factor/enrol');
    }

    $this->get('/')->assertRedirect($home);
})->with([
    'admin' => [RoleName::ADMIN, '/admin/dashboard'],
    'accountant' => [RoleName::ACCOUNTANT, '/accountant/dashboard'],
])->group('phase0');

it('enrols, shows the recovery codes once and then lets the user in', function (RoleName $role, string $home) {
    $user = userWithoutTwoFactor($role);
    $this->actingAs($user);

    $this->get('/two-factor/enrol')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Auth/TwoFactorEnrol')
            ->where('qrSvg', fn (string $svg) => str_contains($svg, '<svg'))
            ->where('secret', fn (string $secret) => strlen($secret) >= 16)
            ->where('required', true));

    $secret = $user->fresh()->two_factor_secret;

    // Reloading the page keeps the same pending secret.
    $this->get('/two-factor/enrol')->assertInertia(fn (Assert $page) => $page->where('secret', $secret));

    $this->post('/two-factor/enrol', ['code' => app(Google2FA::class)->getCurrentOtp($secret)])
        ->assertRedirect('/two-factor/recovery-codes');

    $this->get('/two-factor/recovery-codes')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Auth/RecoveryCodes')
            ->has('codes', 8)
            ->missing('auth.user.recoveryCodes')
            ->missing('flash.recoveryCodes'));

    $this->get('/two-factor/recovery-codes')->assertRedirect('/');

    expect($user->fresh()->hasConfirmedTwoFactor())->toBeTrue();

    $this->get('/two-factor/enrol')->assertRedirect('/');
    $this->get($home)->assertOk()->assertInertia(fn (Assert $page) => $page->missing('recoveryCodes'));
})->with([
    'admin' => [RoleName::ADMIN, '/admin/dashboard'],
    'accountant' => [RoleName::ACCOUNTANT, '/accountant/dashboard'],
])->group('phase0');

it('rejects a wrong confirmation code', function () {
    $user = userWithoutTwoFactor(RoleName::ADMIN);
    $this->actingAs($user);
    $this->get('/two-factor/enrol')->assertOk();

    $this->from('/two-factor/enrol')
        ->post('/two-factor/enrol', ['code' => '000000'])
        ->assertRedirect('/two-factor/enrol')
        ->assertSessionHasErrors(['code' => 'The code is invalid.']);

    expect($user->fresh()->hasConfirmedTwoFactor())->toBeFalse();
    $this->get('/admin/dashboard')->assertRedirect('/two-factor/enrol');
})->group('phase0');

it('does not force an employee without 2FA to enrol', function () {
    $yaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();

    $this->actingAs($yaseen)->get('/employee/dashboard')->assertOk();
    $this->actingAs($yaseen)->get('/profile')->assertOk();
})->group('phase0');

it('lets an employee enrol optionally', function () {
    $yaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();
    $this->actingAs($yaseen);

    $this->get('/two-factor/enrol')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Auth/TwoFactorEnrol')
            ->where('required', false));

    $secret = $yaseen->fresh()->two_factor_secret;

    $this->post('/two-factor/enrol', ['code' => app(Google2FA::class)->getCurrentOtp($secret)])
        ->assertRedirect('/two-factor/recovery-codes');

    expect($yaseen->fresh()->hasConfirmedTwoFactor())->toBeTrue();
})->group('phase0');

it('sends a guest from the recovery codes page to login', function () {
    $this->get('/two-factor/recovery-codes')->assertRedirect('/login');
    $this->get('/two-factor/enrol')->assertRedirect('/login');
})->group('phase0');
