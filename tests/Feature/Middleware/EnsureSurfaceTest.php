<?php

use App\Models\Employee;
use App\Models\User;
use App\Support\RoleName;
use App\Support\UserStatus;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    foreach (['admin', 'employee', 'accountant'] as $surface) {
        Route::middleware(['web', 'auth', "surface:{$surface}"])
            ->get("/_test/{$surface}", fn () => "{$surface} ok");
    }
});

function surfaceUser(RoleName $role, UserStatus $status = UserStatus::Active): User
{
    $user = User::factory()->create(['status' => $status]);
    Employee::factory()->forRole($role)->create(['user_id' => $user->id]);

    return $user->fresh();
}

it('lets each role reach only its own surface', function (RoleName $role, string $allowed) {
    $user = surfaceUser($role);

    foreach (['admin', 'employee', 'accountant'] as $surface) {
        $response = $this->actingAs($user)->get("/_test/{$surface}");

        $surface === $allowed
            ? $response->assertOk()->assertSee("{$surface} ok")
            : $response->assertForbidden();
    }
})->with([
    'admin' => [RoleName::ADMIN, 'admin'],
    'employee' => [RoleName::EMPLOYEE, 'employee'],
    'remote employee' => [RoleName::REMOTE_EMPLOYEE, 'employee'],
    'manager (dormant)' => [RoleName::MANAGER, 'employee'],
    'accountant' => [RoleName::ACCOUNTANT, 'accountant'],
])->group('phase0');

it('forbids a user without an employee record', function () {
    $this->actingAs(User::factory()->create())->get('/_test/employee')->assertForbidden();
})->group('phase0');

// Inactive users are `EnsureActiveUser`'s job now; see EnsureActiveUserTest.

it('does not let a guest through', function () {
    // The HTML redirect needs the `login` route, which arrives with the auth routes.
    $this->getJson('/_test/admin')->assertUnauthorized();

    Route::middleware(['web', 'surface:admin'])->get('/_test/no-auth', fn () => 'leaked');
    $this->getJson('/_test/no-auth')->assertUnauthorized();
})->group('phase0');
