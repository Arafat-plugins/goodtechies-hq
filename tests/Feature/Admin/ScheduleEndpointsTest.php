<?php

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Schedule;
use App\Models\User;
use App\Services\AttendanceService;
use App\Support\AuditEvent;
use App\Support\RoleName;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| Admin → Workforce → Work Schedule
|--------------------------------------------------------------------------
|
| This screen is why no working week is hard-coded anywhere: every rule that
| reads a schedule follows whatever is saved here. The tests below therefore
| do not just assert that a row was written — they change a schedule and then
| assert that the STATUS of a day changed with it.
|
*/

beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->yaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();
    $this->accountant = User::where('email', 'accountant@goodtechies.test')->firstOrFail();
    $this->manager = Employee::factory()->forRole(RoleName::MANAGER)->create()->user;
});

it('lists every tracked employee with their schedule, and says who has none', function () {
    $this->actingAs($this->admin)
        ->get('/admin/schedules')
        ->assertOk()
        ->assertInertia(function ($page) {
            $rows = collect($page->toArray()['props']['rows']);

            expect($rows->pluck('employee.name')->all())
                // Alphabetical, because it is a list of people. The factory Manager this file
                // creates is tracked too and sorts among them, so the assertion is that the
                // four seeded people are here in order and the ACCOUNTANT is not: they are
                // tracked by neither clock nor timer, so there is no schedule to edit.
                ->toContain('Faruk Ahmed', 'Shahadat Hossain', 'Tapu', 'Yaseen')
                ->and($rows->pluck('employee.name')->all())->not->toContain('Accountant')
                ->and($rows->pluck('employee.name')->sort()->values()->all())
                ->toBe($rows->pluck('employee.name')->all())
                ->and($rows->firstWhere('employee.name', 'Yaseen')['schedule']['start_time'])->toBe('09:00')
                // Tapu's schedule has no start time, which is how "cannot be late" is expressed.
                ->and($rows->firstWhere('employee.name', 'Tapu')['schedule']['start_time'])->toBeNull()
                // The seven keys come from Weekday, so a checkbox cannot offer one the request
                // would refuse.
                ->and(collect($page->toArray()['props']['weekdays'])->pluck('value')->all())
                ->toBe(['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat']);
        });
});

it('saves a schedule, audit-logged with old and new', function () {
    $employee = $this->yaseen->employee;

    $this->actingAs($this->admin)
        ->put("/admin/schedules/{$employee->id}", [
            'working_days' => ['mon', 'tue', 'wed', 'thu', 'fri'],
            'working_hours_per_day' => 7.5,
            'start_time' => '10:00',
            'office_or_remote' => 'office',
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $schedule = Schedule::where('employee_id', $employee->id)->sole();

    expect($schedule->working_days)->toBe(['mon', 'tue', 'wed', 'thu', 'fri'])
        ->and((float) $schedule->working_hours_per_day)->toBe(7.5);

    $audit = AuditLog::where('event', AuditEvent::ScheduleChanged->value)->sole();

    expect($audit->actor_id)->toBe($this->admin->id)
        ->and($audit->old_value['working_days'])->toBe(['sun', 'mon', 'tue', 'wed', 'thu'])
        ->and($audit->new_value['working_days'])->toBe(['mon', 'tue', 'wed', 'thu', 'fri'])
        ->and($audit->old_value['start_time'])->toBe('09:00:00')
        ->and($audit->new_value['start_time'])->toBe('10:00');
});

it('changes what a day IS, which is the point of the screen', function () {
    $employee = $this->yaseen->employee;
    $friday = Carbon::parse('2026-09-18');
    $attendance = app(AttendanceService::class);

    // Before: the seeded Sunday-to-Thursday week, so the Friday is an off day.
    expect($attendance->dayFor($employee->fresh(['schedule']), $friday)->status?->value)->toBe('off_day');

    $this->actingAs($this->admin)
        ->put("/admin/schedules/{$employee->id}", [
            'working_days' => ['fri', 'sat'],
            'working_hours_per_day' => 8,
            'start_time' => '09:00',
            'office_or_remote' => 'office',
        ])
        ->assertRedirect();

    // After: the same date, and it is now a working day with no record rather than an off day.
    // Nothing else was touched — the rule simply read the new schedule.
    $day = $attendance->dayFor($employee->fresh(['schedule']), $friday);

    expect($day->status)->toBeNull()
        ->and($day->scheduled)->toBeTrue();
});

it('stores the working days in week order however the checkboxes were ticked', function () {
    $employee = $this->yaseen->employee;

    $this->actingAs($this->admin)
        ->put("/admin/schedules/{$employee->id}", [
            'working_days' => ['thu', 'mon', 'sun'],
            'working_hours_per_day' => 8,
            'start_time' => '09:00',
            'office_or_remote' => 'office',
        ])
        ->assertRedirect();

    // Sorted, so two saves of the same week produce the same JSON and the audit diff shows a
    // change only when there was one.
    expect(Schedule::where('employee_id', $employee->id)->sole()->working_days)
        ->toBe(['sun', 'mon', 'thu']);
});

it('accepts a schedule with no start time, and that employee can then never be late', function () {
    $employee = $this->yaseen->employee;

    $this->actingAs($this->admin)
        ->put("/admin/schedules/{$employee->id}", [
            'working_days' => ['sun', 'mon', 'tue', 'wed', 'thu'],
            'working_hours_per_day' => 8,
            'start_time' => null,
            'office_or_remote' => 'office',
        ])
        ->assertRedirect();

    $fresh = $employee->fresh(['schedule']);
    $attendance = app(AttendanceService::class);

    expect($fresh->schedule->start_time)->toBeNull()
        ->and($attendance->latestOnTime($fresh->schedule, Carbon::parse('2026-09-14')))->toBeNull()
        ->and($attendance->isLate($fresh->schedule, Carbon::parse('2026-09-14')->setTime(23, 0)))->toBeFalse();
});

it('refuses a day key that is not a weekday', function () {
    $employee = $this->yaseen->employee;

    $this->actingAs($this->admin)
        ->put("/admin/schedules/{$employee->id}", [
            'working_days' => ['mon', 'funday'],
            'working_hours_per_day' => 8,
            'start_time' => '09:00',
            'office_or_remote' => 'office',
        ])
        ->assertSessionHasErrors('working_days.1');
});

it('accepts a week with no working days at all', function () {
    // Somebody on an unpaid leave of absence has one, and refusing it would push an Admin into
    // deactivating the account instead.
    $employee = $this->yaseen->employee;

    $this->actingAs($this->admin)
        ->put("/admin/schedules/{$employee->id}", [
            'working_days' => [],
            'working_hours_per_day' => 8,
            'start_time' => '09:00',
            'office_or_remote' => 'office',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(Schedule::where('employee_id', $employee->id)->sole()->working_days)->toBe([]);
});

it('keeps everybody but an Admin out of the editor', function () {
    foreach ([$this->yaseen, $this->accountant, $this->manager] as $user) {
        $this->actingAs($user)->get('/admin/schedules')->assertForbidden();
    }
});

it('is 404 for an employee id that does not exist', function () {
    $this->actingAs($this->admin)
        ->put('/admin/schedules/999999', [
            'working_days' => ['mon'],
            'working_hours_per_day' => 8,
            'start_time' => '09:00',
            'office_or_remote' => 'office',
        ])
        ->assertNotFound();
});
