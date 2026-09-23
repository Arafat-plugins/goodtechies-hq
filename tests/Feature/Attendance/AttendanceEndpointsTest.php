<?php

use App\Exceptions\AttendanceStateException;
use App\Models\AttendanceRecord;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\User;
use App\Services\AttendanceService;
use App\Support\AttendanceStatus;
use App\Support\AuditEvent;
use App\Support\RoleName;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| Attendance endpoints: the clock, the self page, the roster and the edit
|--------------------------------------------------------------------------
|
| Two privacy rules are asserted here rather than argued:
|
|   - an employee asking for a colleague's month gets **404**, not 403 — the
|     record is ABSENT (Part C), and the lookup goes through
|     Employee::attendanceVisibleTo() so absence is what the controller has
|     rather than what it decides;
|   - an Admin's edit is audit-logged with old AND new values, and cannot be
|     made without a reason.
|
| 2026-09-14 is a Monday and 2026-09-18 a Friday, both in the past.
|
*/

const ENDPOINT_MONDAY = '2026-09-14';
const ENDPOINT_FRIDAY = '2026-09-18';

beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->yaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();
    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();
    $this->accountant = User::where('email', 'accountant@goodtechies.test')->firstOrFail();
    $this->manager = Employee::factory()->forRole(RoleName::MANAGER)->create()->user;
});

// -------------------------------------------------------------------------
// The self page, and the 404 that is the whole privacy rule
// -------------------------------------------------------------------------

it('shows an employee their own month', function () {
    $this->actingAs($this->yaseen)
        ->get('/attendance?month=2026-09')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Shared/Attendance')
            ->where('subject.is_self', true)
            ->where('subject.name', $this->yaseen->name)
            ->where('month.value', '2026-09')
            ->has('days', 30)
            // The schedule is on the page so a status nobody expected can be read back to the
            // rule that produced it.
            ->where('schedule.start_time', '09:00')
            ->where('permissions.can_clock', true)
            ->where('permissions.can_edit', false));
});

it('is 404, never 403, when an employee asks for a colleague\'s attendance', function () {
    $tapuEmployee = $this->tapu->employee;

    $this->actingAs($this->yaseen)
        ->get("/attendance/{$tapuEmployee->id}")
        ->assertNotFound();

    // And the same request as himself is fine, so the 404 is about whose record it is and not
    // about the route taking a parameter at all.
    $this->actingAs($this->yaseen)
        ->get("/attendance/{$this->yaseen->employee->id}")
        ->assertOk();
});

it('lets an Admin read anybody\'s month, with the edit control resolved on the server', function () {
    $this->actingAs($this->admin)
        ->get("/attendance/{$this->yaseen->employee->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('subject.is_self', false)
            ->where('subject.name', $this->yaseen->name)
            ->where('permissions.can_edit', true)
            // An Admin reading somebody else's page may not clock in FOR them.
            ->where('permissions.can_clock', false));
});

it('is 404 for a Manager asking about somebody outside their team', function () {
    // The factory Manager has nobody reporting to them, so every other employee is absent to
    // them — Part C's 🟡 own-team cell, enforced by the scope and not by a role name.
    $this->actingAs($this->manager)
        ->get("/attendance/{$this->yaseen->employee->id}")
        ->assertNotFound();

    $this->actingAs($this->manager)
        ->get("/attendance/{$this->manager->employee->id}")
        ->assertOk();
});

// -------------------------------------------------------------------------
// The clock
// -------------------------------------------------------------------------

it('clocks Yaseen in and out and says what today is', function () {
    // A day his seeded schedule works, so the clock is open. The clock endpoints work on
    // "now", which is what a person at the door presses.
    $this->travelTo(Carbon::parse(ENDPOINT_MONDAY)->setTime(8, 58));

    $this->actingAs($this->yaseen)
        ->post('/attendance/clock-in')
        ->assertRedirect()
        ->assertSessionHas('success', fn (string $message): bool => str_contains($message, 'Clocked in at 08:58')
            && str_contains($message, 'Present'));

    $this->travelTo(Carbon::parse(ENDPOINT_MONDAY)->setTime(17, 30));

    $this->actingAs($this->yaseen)
        ->post('/attendance/clock-out')
        ->assertRedirect()
        ->assertSessionHas('success', fn (string $message): bool => str_contains($message, 'Clocked out at 17:30'));

    $record = AttendanceRecord::where('employee_id', $this->yaseen->employee->id)->sole();

    expect($record->status)->toBe(AttendanceStatus::Present)
        ->and($record->workedMinutes())->toBe(512);
});

it('answers a second clock-in with a sentence, not a status code', function () {
    $this->travelTo(Carbon::parse(ENDPOINT_MONDAY)->setTime(8, 58));

    $this->actingAs($this->yaseen)->post('/attendance/clock-in')->assertRedirect();

    $this->travelTo(Carbon::parse(ENDPOINT_MONDAY)->setTime(9, 30));

    $this->actingAs($this->yaseen)
        ->post('/attendance/clock-in')
        ->assertRedirect()
        ->assertSessionHas('error', fn (string $message): bool => str_contains($message, '08:58'));

    expect(AttendanceRecord::where('employee_id', $this->yaseen->employee->id)->count())->toBe(1);
});

it('records Late for an arrival past the grace window', function () {
    // 09:00 start, 15 minutes grace (seeded): 09:20 is late.
    $this->travelTo(Carbon::parse(ENDPOINT_MONDAY)->setTime(9, 20));

    $this->actingAs($this->yaseen)
        ->post('/attendance/clock-in')
        ->assertSessionHas('success', fn (string $message): bool => str_contains($message, 'Late'));

    expect(AttendanceRecord::where('employee_id', $this->yaseen->employee->id)->sole()->status)
        ->toBe(AttendanceStatus::Late);
});

it('refuses the clock to somebody the office clock does not track', function () {
    $this->travelTo(Carbon::parse(ENDPOINT_MONDAY)->setTime(9, 0));

    // Tapu is remote_timer and the Accountant is none. Neither is named in the policy: it asks
    // the tracking mode.
    $this->actingAs($this->tapu)->post('/attendance/clock-in')->assertForbidden();
    $this->actingAs($this->accountant)->post('/attendance/clock-in')->assertForbidden();

    expect(AttendanceRecord::count())->toBe(0);
});

it('gives both Admins the clock, because Part D §8 says office employees include them', function () {
    $this->travelTo(Carbon::parse(ENDPOINT_MONDAY)->setTime(9, 5));

    $this->actingAs($this->admin)->post('/attendance/clock-in')->assertRedirect();

    expect(AttendanceRecord::where('employee_id', $this->admin->employee->id)->sole()->status)
        ->toBe(AttendanceStatus::Present);
});

// -------------------------------------------------------------------------
// The roster
// -------------------------------------------------------------------------

it('shows the roster with Yaseen present and Tapu remote, never absent', function () {
    app(AttendanceService::class)->clockIn(
        $this->yaseen->employee,
        Carbon::parse(ENDPOINT_MONDAY)->setTime(8, 58),
    );

    $this->actingAs($this->admin)
        ->get('/admin/attendance?date='.ENDPOINT_MONDAY)
        ->assertOk()
        ->assertInertia(function ($page) {
            $rows = collect($page->toArray()['props']['rows']);
            $yaseen = $rows->firstWhere('employee.name', 'Yaseen');
            $tapu = $rows->firstWhere('employee.name', 'Tapu');

            expect($yaseen['status'])->toBe('present')
                ->and($yaseen['status_label'])->toBe('Present')
                ->and($yaseen['clock_in'])->toBe('08:58')
                ->and($tapu['status'])->toBe('remote')
                ->and($tapu['status_label'])->toBe('Remote')
                // The seam is wired (slice 3). It is 0 rather than null now, and the
                // difference is the whole point: null meant "nobody measured this", 0 means
                // "measured, and nothing was tracked" — which is the true answer for a Monday
                // nobody ran a timer on. The roster prints "Remote — 0m tracked".
                // `tests/Feature/Admin/TrackedMinutesTest.php` asserts the non-zero case.
                ->and($tapu['tracked_minutes'])->toBe(0)
                // The Accountant is tracked by neither clock nor timer, so the roster has
                // nothing to say about them and does not invent a row.
                ->and($rows->firstWhere('employee.name', 'Accountant'))->toBeNull();
        });
});

it('shows a Friday as everybody\'s off day, from their schedules', function () {
    $this->actingAs($this->admin)
        ->get('/admin/attendance?date='.ENDPOINT_FRIDAY)
        ->assertOk()
        ->assertInertia(function ($page) {
            $statuses = collect($page->toArray()['props']['rows'])->pluck('status')->unique()->values();

            expect($statuses->all())->toBe(['off_day']);
        });
});

it('keeps everybody but an Admin off the roster', function () {
    foreach ([$this->yaseen, $this->tapu, $this->accountant, $this->manager] as $user) {
        $this->actingAs($user)->get('/admin/attendance')->assertForbidden();
    }
});

// -------------------------------------------------------------------------
// The Admin's edit
// -------------------------------------------------------------------------

it('records an Admin edit in the audit log with old and new values', function () {
    $employee = $this->yaseen->employee;

    app(AttendanceService::class)->clockIn($employee, Carbon::parse(ENDPOINT_MONDAY)->setTime(9, 40));

    $this->actingAs($this->admin)
        ->put("/admin/attendance/{$employee->id}/".ENDPOINT_MONDAY, [
            'status' => 'present',
            'clock_in' => '09:00',
            'clock_out' => '17:00',
            'note' => 'Fingerprint reader was down; arrival confirmed by Faruk.',
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $record = AttendanceRecord::where('employee_id', $employee->id)->sole();

    expect($record->status)->toBe(AttendanceStatus::Present)
        ->and($record->clock_in->format('H:i'))->toBe('09:00')
        ->and($record->note)->toBe('Fingerprint reader was down; arrival confirmed by Faruk.')
        ->and($record->edited_by)->toBe($this->admin->id);

    $audit = AuditLog::where('event', AuditEvent::AttendanceEdited->value)->sole();

    expect($audit->actor_id)->toBe($this->admin->id)
        ->and($audit->target_id)->toBe($record->id)
        // Old AND new, in the same shape, so a reader diffs them by eye.
        ->and($audit->old_value['status'])->toBe('late')
        ->and($audit->new_value['status'])->toBe('present')
        ->and($audit->old_value['note'])->toBeNull()
        ->and($audit->new_value['note'])->toBe('Fingerprint reader was down; arrival confirmed by Faruk.');
});

it('refuses an edit with no reason', function () {
    $employee = $this->yaseen->employee;

    $this->actingAs($this->admin)
        ->put("/admin/attendance/{$employee->id}/".ENDPOINT_MONDAY, [
            'status' => 'absent',
            'clock_in' => null,
            'clock_out' => null,
            'note' => '',
        ])
        ->assertSessionHasErrors('note');

    expect(AttendanceRecord::count())->toBe(0)
        ->and(AuditLog::where('event', AuditEvent::AttendanceEdited->value)->exists())->toBeFalse();
});

it('creates the day when there is no row yet, and logs that there was none', function () {
    $employee = $this->yaseen->employee;

    $this->actingAs($this->admin)
        ->put("/admin/attendance/{$employee->id}/".ENDPOINT_MONDAY, [
            'status' => 'half_day',
            'clock_in' => '09:00',
            'clock_out' => '13:00',
            'note' => 'Left at one for a dentist appointment.',
        ])
        ->assertRedirect();

    $audit = AuditLog::where('event', AuditEvent::AttendanceEdited->value)->sole();

    // No old value, because there was no day. That is a different row from a correction, and
    // the log says so.
    expect($audit->old_value)->toBeNull()
        ->and($audit->new_value['status'])->toBe('half_day')
        ->and(AttendanceRecord::where('employee_id', $employee->id)->sole()->status)
        ->toBe(AttendanceStatus::HalfDay);
});

it('refuses to write a derived status, at the request and at the database', function () {
    $employee = $this->yaseen->employee;

    foreach (['off_day', 'remote', 'leave', 'holiday'] as $status) {
        $this->actingAs($this->admin)
            ->put("/admin/attendance/{$employee->id}/".ENDPOINT_MONDAY, [
                'status' => $status,
                'clock_in' => null,
                'clock_out' => null,
                'note' => 'Trying to set '.$status,
            ])
            ->assertSessionHasErrors('status');
    }

    // And the column itself refuses the two that are never storable, whatever asks.
    expect(fn () => AttendanceRecord::query()->insert([
        'employee_id' => $employee->id,
        'date' => ENDPOINT_MONDAY,
        'status' => 'off_day',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('cannot write an attendance record for a remote employee, by any path', function () {
    // "Tapu is never Absent" made structural. The sweep skips him and Remote is derived for
    // him — and there is no hand that can write him a row either, so there is no path left by
    // which the word could appear on his month.
    $tapu = $this->tapu->employee;

    $this->actingAs($this->admin)
        ->put("/admin/attendance/{$tapu->id}/".ENDPOINT_MONDAY, [
            'status' => 'absent',
            'clock_in' => null,
            'clock_out' => null,
            'note' => 'Trying to mark the remote employee absent.',
        ])
        ->assertForbidden();

    // The service refuses it too, so a command or a seeder cannot slip past the policy.
    expect(fn () => app(AttendanceService::class)->edit(
        $tapu,
        Carbon::parse(ENDPOINT_MONDAY),
        ['status' => AttendanceStatus::Absent, 'clock_in' => null, 'clock_out' => null, 'note' => 'nope'],
        $this->admin,
    ))->toThrow(AttendanceStateException::class, 'not tracked by clocking in and out');

    expect(AttendanceRecord::where('employee_id', $tapu->id)->exists())->toBeFalse();

    // And the roster draws no Edit control on his row rather than one that would be refused.
    $this->actingAs($this->admin)
        ->get('/admin/attendance?date='.ENDPOINT_MONDAY)
        ->assertInertia(function ($page) {
            $rows = collect($page->toArray()['props']['rows']);

            expect($rows->firstWhere('employee.name', 'Tapu')['can_edit'])->toBeFalse()
                ->and($rows->firstWhere('employee.name', 'Yaseen')['can_edit'])->toBeTrue();
        });
});

it('refuses an edit to a day that has not happened yet', function () {
    $employee = $this->yaseen->employee;

    $this->actingAs($this->admin)
        ->put("/admin/attendance/{$employee->id}/".Carbon::tomorrow()->toDateString(), [
            'status' => 'present',
            'clock_in' => '09:00',
            'clock_out' => '17:00',
            'note' => 'Booking tomorrow in advance.',
        ])
        ->assertSessionHas('error');

    expect(AttendanceRecord::count())->toBe(0);
});

it('is 404 for an edit aimed at an employee id that does not exist', function () {
    $this->actingAs($this->admin)
        ->put('/admin/attendance/999999/'.ENDPOINT_MONDAY, [
            'status' => 'present',
            'clock_in' => '09:00',
            'clock_out' => '17:00',
            'note' => 'A reason for a person who is not there.',
        ])
        ->assertNotFound();
});
