<?php

use App\Models\Employee;
use App\Models\User;
use App\Support\RoleName;
use App\Support\UserStatus;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/**
 * An employee, who needs no 2FA enrolment, so `active` is the only thing under test.
 */
function activeMiddlewareUser(UserStatus $status = UserStatus::Active): User
{
    $user = User::factory()->create(['status' => $status]);
    Employee::factory()->forRole(RoleName::EMPLOYEE)->create(['user_id' => $user->id]);

    return $user->fresh();
}

/**
 * The cookie a browser would send back after "remember me", for this user's current token.
 */
function recallerCookie(User $user): array
{
    return [Auth::guard('web')->getRecallerName(), $user->id.'|'.$user->getRememberToken().'|'.$user->password];
}

it('logs an inactive user out and redirects to login', function (string $path) {
    $user = activeMiddlewareUser(UserStatus::Inactive);

    $this->actingAs($user)
        ->get($path)
        ->assertRedirect('/login')
        ->assertSessionHas('error', 'Your account is inactive. Contact an administrator.');

    $this->assertGuest();
})->with([
    'profile' => ['/profile'],
    'two-factor enrolment' => ['/two-factor/enrol'],
    'employee dashboard' => ['/employee/dashboard'],
])->group('phase0');

it('does not let a remember-me cookie sign an inactive user in', function () {
    $user = activeMiddlewareUser(UserStatus::Inactive);
    [$name, $value] = recallerCookie($user);

    // No session at all: only the recaller cookie, exactly as a returning browser would send it.
    $this->withCookie($name, $value)
        ->get('/profile')
        ->assertRedirect('/login');

    $this->assertGuest();
})->group('phase0');

it('still lets a remember-me cookie sign an active user in', function () {
    $user = activeMiddlewareUser();
    [$name, $value] = recallerCookie($user);

    $this->withCookie($name, $value)->get('/profile')->assertOk();

    $this->assertAuthenticatedAs($user);
})->group('phase0');

it('lets an active user through', function () {
    $this->actingAs(activeMiddlewareUser())->get('/profile')->assertOk();
})->group('phase0');

it('answers an inactive JSON request with 403', function () {
    $this->actingAs(activeMiddlewareUser(UserStatus::Inactive))
        ->getJson('/profile')
        ->assertForbidden();

    $this->assertGuest();
})->group('phase0');

it('leaves guests to the auth middleware', function () {
    $this->get('/profile')->assertRedirect('/login');

    // `active` on its own passes a guest straight through.
    Route::middleware(['web', 'active'])->get('/_test/active-guest', fn () => 'guest ok');
    $this->get('/_test/active-guest')->assertOk()->assertSee('guest ok');
})->group('phase0');

it('still lets an inactive user log out', function () {
    $this->actingAs(activeMiddlewareUser(UserStatus::Inactive))
        ->post('/logout')
        ->assertRedirect('/login');

    $this->assertGuest();
})->group('phase0');
