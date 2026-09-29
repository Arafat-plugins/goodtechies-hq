<?php

use App\Models\Employee;
use App\Support\RoleName;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| The dashboard's clock widget reads today's real row
|--------------------------------------------------------------------------
|
| `attendanceHero()` used to call `dayFor()` without today's record, so the
| hero always said "Not in yet" and offered Clock in — to somebody who had
| already clocked in. It now fetches the row the way `/attendance` does.
|
*/

beforeEach(function (): void {
    // A Thursday, pinned, and a schedule that works it — nothing here depends on the seed.
    Carbon::setTestNow('2026-09-24 09:05:00');

    $this->seed(RolePermissionSeeder::class);
    $this->seed(SettingsSeeder::class);

    $this->clocker = Employee::factory()->forRole(RoleName::EMPLOYEE)->create();
    $this->clocker->schedule()->create([
        'working_days' => ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'],
        'working_hours_per_day' => 8,
        'office_or_remote' => 'office',
    ]);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('shows the person as clocked in on the dashboard once they have clocked in', function (): void {
    $user = $this->clocker->user;

    $before = $this->actingAs($user)->get('/employee/dashboard')->assertOk()->inertiaPage()['props']['attendance'];

    expect($before['can_clock'])->toBeTrue()
        ->and($before['today']['clock_in'])->toBeNull();

    $this->actingAs($user)->post('/attendance/clock-in')->assertRedirect()->assertSessionHas('success');

    $after = $this->actingAs($user)->get('/employee/dashboard')->assertOk()->inertiaPage()['props']['attendance'];

    // `clock_in` set and `clock_out` null is exactly what makes the widget offer Clock out and
    // stop saying "Not in yet".
    expect($after['today']['clock_in'])->toBe('09:05')
        ->and($after['today']['clock_in_at'])->not->toBeNull()
        ->and($after['today']['clock_out'])->toBeNull()
        ->and($after['today']['status_label'])->not->toBeNull();
});
