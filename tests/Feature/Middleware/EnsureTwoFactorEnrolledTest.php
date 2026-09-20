<?php

use App\Models\Employee;
use App\Models\User;
use App\Support\RoleName;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::middleware(['web', 'auth', 'two-factor'])->group(function () {
        Route::get('/_test/protected', fn () => 'protected ok');
        Route::get('/two-factor/enrol', fn () => 'enrol page');
        Route::post('/logout', fn () => 'logged out');
    });
});

function twoFactorUser(RoleName $role, bool $confirmed): User
{
    $factory = User::factory();

    if ($confirmed) {
        $factory = $factory->withTwoFactor('SSDS4NSGDATIE52QCAQQJU7OONUKLRL4');
    }

    $user = $factory->create();
    Employee::factory()->forRole($role)->create(['user_id' => $user->id]);

    return $user->fresh();
}

it('redirects an admin without confirmed 2FA to enrolment', function (RoleName $role) {
    $user = twoFactorUser($role, confirmed: false);

    $this->actingAs($user)->get('/_test/protected')->assertRedirect('/two-factor/enrol');
})->with([RoleName::ADMIN, RoleName::ACCOUNTANT])->group('phase0');

it('lets an admin with a pending, unconfirmed secret reach only enrolment and logout', function () {
    $user = twoFactorUser(RoleName::ADMIN, confirmed: false);
    $user->forceFill(['two_factor_secret' => 'SSDS4NSGDATIE52QCAQQJU7OONUKLRL4'])->save();

    $this->actingAs($user)->get('/_test/protected')->assertRedirect('/two-factor/enrol');
    $this->actingAs($user)->get('/two-factor/enrol')->assertOk()->assertSee('enrol page');
    $this->actingAs($user)->post('/logout')->assertOk();
})->group('phase0');

it('lets an admin with confirmed 2FA through', function () {
    $this->actingAs(twoFactorUser(RoleName::ADMIN, confirmed: true))
        ->get('/_test/protected')
        ->assertOk()
        ->assertSee('protected ok');
})->group('phase0');

it('lets an employee without 2FA through', function (RoleName $role) {
    $this->actingAs(twoFactorUser($role, confirmed: false))
        ->get('/_test/protected')
        ->assertOk();
})->with([RoleName::EMPLOYEE, RoleName::REMOTE_EMPLOYEE, RoleName::MANAGER])->group('phase0');

it('answers JSON requests with 403', function () {
    $this->actingAs(twoFactorUser(RoleName::ADMIN, confirmed: false))
        ->getJson('/_test/protected')
        ->assertForbidden()
        ->assertJson(['message' => 'Two-factor enrolment required.']);
})->group('phase0');
