<?php

use App\Models\User;
use App\Support\Permission;
use App\Support\UserStatus;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    $this->seed();
});

it('allows an admin through the settings.manage gate', function () {
    $admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();

    expect(Gate::forUser($admin)->allows('settings.manage'))->toBeTrue();
})->group('phase0');

it('denies an employee', function () {
    $employee = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();

    expect(Gate::forUser($employee)->allows('settings.manage'))->toBeFalse()
        ->and(Gate::forUser($employee)->allows('timer.use'))->toBeFalse()
        ->and(Gate::forUser($employee)->allows('leave.apply'))->toBeTrue();
})->group('phase0');

it('denies an inactive admin', function () {
    $admin = User::where('email', 'faruk@goodtechies.test')->firstOrFail();
    $admin->update(['status' => UserStatus::Inactive]);

    expect(Gate::forUser($admin->fresh())->allows('settings.manage'))->toBeFalse();
})->group('phase0');

it('defines a gate for every permission key', function () {
    foreach (Permission::cases() as $permission) {
        expect(Gate::has($permission->value))->toBeTrue("No gate for {$permission->value}");
    }
})->group('phase0');
