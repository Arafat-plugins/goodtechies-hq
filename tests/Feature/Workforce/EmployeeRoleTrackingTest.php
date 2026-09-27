<?php

use App\Exceptions\SelfModificationException;
use App\Models\ActivityLog;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\EmployeeAdministrationService;
use App\Support\AuditEvent;
use App\Support\RoleName;
use App\Support\TrackingMode;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

/*
|--------------------------------------------------------------------------
| Admin → Users & Roles: changing a role and a tracking mode (Phase 12)
|--------------------------------------------------------------------------
|
| Part D §2 calls this screen family "Employees list → employee detail →
| role/schedule/tracking_mode", and the ADMIN sidebar row is literally
| **Users & Roles**. Until these two endpoints existed the screen named after
| roles could not change one: `EmployeeAdministrationService::changeRole()`
| was written, audited and self-guarded and had no caller anywhere in the
| application, and `employees.tracking_mode` had no writer at all —
| `ScheduleService`'s docblock defers it here by name.
|
| What this file pins:
|
| **MANAGER is not assignable, by promotion either.** Part C §1: it "exists in
| the roles table from Phase 0 but is assigned to nobody and gets no dedicated
| UI in MVP". `StoreEmployeeRequest` refuses it on the way in and
| `UpdateEmployeeRoleRequest` refuses it on the way across, against the same
| `ASSIGNABLE_ROLES` list.
|
| **Nobody changes their own role or their own tracking mode**, and the rule is
| stated once — `EmployeeAdministrationService::guard()` — so it holds for a
| caller that is not an HTTP request as well as for one that is.
|
| **A tracking-mode switch migrates nothing** (decision 4-11: an
| office-attendance employee has `attendance_records` and no timer; a
| remote-timer employee has `time_entries` and no attendance row at all). The
| rows that existed before the switch are asserted to be exactly the rows that
| exist after it.
|
| Every constant and helper here is prefixed ROLETRACK_ / roleTrack*, because
| Pest declares both globally across the whole suite (AGENTS.md).
|
*/

beforeEach(function (): void {
    $this->seed();

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();
    $this->yaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();
    $this->accountant = User::where('email', 'accountant@goodtechies.test')->firstOrFail();

    $this->service = app(EmployeeAdministrationService::class);
});

function roleTrackUrl(Employee $employee, string $field): string
{
    return '/admin/employees/'.$employee->id.'/'.$field;
}

/*
|--------------------------------------------------------------------------
| Role
|--------------------------------------------------------------------------
*/

it('changes a role through the one service method, audited, with an activity line', function (): void {
    $employee = $this->yaseen->employee;

    $this->actingAs($this->admin)
        ->put(roleTrackUrl($employee, 'role'), ['role' => RoleName::REMOTE_EMPLOYEE->value])
        ->assertRedirect()
        ->assertSessionHas('success', fn (string $flash): bool => str_contains($flash, 'Remote employee')
            && str_contains($flash, 'next request'));

    expect($employee->fresh()->role->name)->toBe(RoleName::REMOTE_EMPLOYEE);

    // The audit row is `changeRole()`'s, unchanged — the endpoint reuses the one statement of
    // this rule rather than writing a second role-change path (Part C §4 names the event).
    $audit = AuditLog::where('event', AuditEvent::RoleChanged->value)->sole();

    expect($audit->actor_id)->toBe($this->admin->id)
        ->and($audit->target_type)->toBe($employee->getMorphClass())
        ->and($audit->target_id)->toBe($employee->id)
        ->and($audit->old_value)->toBe(['role' => 'EMPLOYEE'])
        ->and($audit->new_value)->toBe(['role' => 'REMOTE_EMPLOYEE']);

    expect(ActivityLog::query()
        ->where('object_type', $employee->getMorphClass())
        ->where('object_id', $employee->id)
        ->where('description', 'Role changed from EMPLOYEE to REMOTE_EMPLOYEE')
        ->exists())->toBeTrue();
});

it('writes nothing when the role sent is the one they already have', function (): void {
    $employee = $this->yaseen->employee;

    $this->actingAs($this->admin)
        ->put(roleTrackUrl($employee, 'role'), ['role' => RoleName::EMPLOYEE->value])
        ->assertRedirect();

    expect($employee->fresh()->role->name)->toBe(RoleName::EMPLOYEE)
        ->and(AuditLog::where('event', AuditEvent::RoleChanged->value)->count())->toBe(0);
});

it('refuses MANAGER on the way across, the same as on the way in', function (): void {
    $employee = $this->yaseen->employee;

    $this->actingAs($this->admin)
        ->put(roleTrackUrl($employee, 'role'), ['role' => RoleName::MANAGER->value])
        ->assertSessionHasErrors('role');

    expect($employee->fresh()->role->name)->toBe(RoleName::EMPLOYEE)
        ->and(AuditLog::count())->toBe(0);

    // And the screen never offers it either: the select's options are the server's.
    $options = $this->actingAs($this->admin)
        ->get('/admin/employees/'.$employee->id)
        ->viewData('page')['props']['roleOptions'];

    expect(array_column($options, 'value'))->toBe(['ADMIN', 'EMPLOYEE', 'REMOTE_EMPLOYEE', 'ACCOUNTANT']);
});

it('refuses a role that is not a role at all', function (): void {
    $this->actingAs($this->admin)
        ->put(roleTrackUrl($this->yaseen->employee, 'role'), ['role' => 'SUPERUSER'])
        ->assertSessionHasErrors('role');

    expect(AuditLog::count())->toBe(0);
});

it('leaves the tracking mode alone when the role moves', function (): void {
    // Part D §1: tracking mode "is a per-employee field, not a role rule". Making somebody a
    // REMOTE_EMPLOYEE does not start their timer, and nothing here infers one from the other.
    $employee = $this->yaseen->employee;

    $this->actingAs($this->admin)->put(roleTrackUrl($employee, 'role'), ['role' => RoleName::REMOTE_EMPLOYEE->value]);

    expect($employee->fresh()->tracking_mode)->toBe(TrackingMode::OfficeAttendance)
        ->and(AuditLog::where('event', AuditEvent::EmployeeTrackingModeChanged->value)->count())->toBe(0);
});

it('does not sign the person out, and applies to their next request', function (): void {
    $employee = $this->yaseen->employee;

    $this->actingAs($this->admin)->put(roleTrackUrl($employee, 'role'), ['role' => RoleName::ACCOUNTANT->value]);

    // A promotion is not a departure: `deactivate()` ends sessions because the person is gone,
    // and doing it here would sign somebody out mid-sentence. What changes is what their NEXT
    // request may do — the role is read per request, so the Employee shell that was theirs a
    // moment ago stops answering.
    expect(User::find($this->yaseen->id)->surface()?->value)->toBe('accountant');

    // Their own account is untouched apart from the role, and their sessions were never ended.
    expect(User::find($this->yaseen->id)->status->value)->toBe('active');

    // Neither shell simply opens, and that is Part C §3 rather than a gap here: 2FA is required
    // for ADMIN and ACCOUNTANT from day one, and `two-factor` runs before `EnsureSurface` — so
    // somebody promoted into either role is walked through enrolment before they reach anything
    // at all, including the shell they have just left.
    $this->actingAs(User::find($this->yaseen->id))
        ->get('/employee/dashboard')
        ->assertRedirect('/two-factor/enrol');
    $this->actingAs(User::find($this->yaseen->id))
        ->get('/accountant/dashboard')
        ->assertRedirect('/two-factor/enrol');
});

/*
|--------------------------------------------------------------------------
| Tracking mode
|--------------------------------------------------------------------------
*/

it('changes a tracking mode, audited, with an activity line', function (): void {
    $employee = $this->yaseen->employee;

    $this->actingAs($this->admin)
        ->put(roleTrackUrl($employee, 'tracking-mode'), ['tracking_mode' => TrackingMode::RemoteTimer->value])
        ->assertRedirect()
        ->assertSessionHas('success', fn (string $flash): bool => str_contains($flash, 'remote timer')
            && str_contains($flash, 'stay where they were recorded'));

    expect($employee->fresh()->tracking_mode)->toBe(TrackingMode::RemoteTimer);

    $audit = AuditLog::where('event', AuditEvent::EmployeeTrackingModeChanged->value)->sole();

    expect($audit->actor_id)->toBe($this->admin->id)
        ->and($audit->target_type)->toBe($employee->getMorphClass())
        ->and($audit->target_id)->toBe($employee->id)
        ->and($audit->old_value)->toBe(['tracking_mode' => 'office_attendance'])
        ->and($audit->new_value)->toBe(['tracking_mode' => 'remote_timer']);

    expect(ActivityLog::query()
        ->where('object_type', $employee->getMorphClass())
        ->where('object_id', $employee->id)
        ->where('description', 'Tracking mode changed from office_attendance to remote_timer')
        ->exists())->toBeTrue();
});

it('accepts all three modes, `none` included — it is the Accountant\'s real state', function (): void {
    $employee = $this->yaseen->employee;

    foreach ([TrackingMode::RemoteTimer, TrackingMode::None, TrackingMode::OfficeAttendance] as $mode) {
        $this->actingAs($this->admin)
            ->put(roleTrackUrl($employee, 'tracking-mode'), ['tracking_mode' => $mode->value])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        expect($employee->fresh()->tracking_mode)->toBe($mode);
    }

    expect(AuditLog::where('event', AuditEvent::EmployeeTrackingModeChanged->value)->count())->toBe(3);
});

it('refuses a mode that is not one of the three, and writes nothing', function (): void {
    $employee = $this->yaseen->employee;

    $this->actingAs($this->admin)
        ->put(roleTrackUrl($employee, 'tracking-mode'), ['tracking_mode' => 'gps'])
        ->assertSessionHasErrors('tracking_mode');

    expect($employee->fresh()->tracking_mode)->toBe(TrackingMode::OfficeAttendance)
        ->and(AuditLog::count())->toBe(0);
});

it('writes nothing when the mode sent is the one they already have', function (): void {
    $employee = $this->tapu->employee;

    $this->actingAs($this->admin)
        ->put(roleTrackUrl($employee, 'tracking-mode'), ['tracking_mode' => TrackingMode::RemoteTimer->value])
        ->assertRedirect();

    expect(AuditLog::where('event', AuditEvent::EmployeeTrackingModeChanged->value)->count())->toBe(0);
});

it('migrates nothing: the days already recorded are untouched by the switch', function (): void {
    // Decision 4-11. Yaseen is office_attendance and has attendance rows; Tapu is remote_timer
    // and has time entries and NO attendance row at all. Switching either one moves the line for
    // the days from here on and leaves every recorded day exactly where it was — which is why the
    // confirmation says so in words instead of promising a conversion nobody could make.
    $yaseen = $this->yaseen->employee;
    $tapu = $this->tapu->employee;

    $attendanceBefore = $yaseen->attendanceRecords()->orderBy('id')->pluck('id')->all();
    $tapuAttendanceBefore = $tapu->attendanceRecords()->count();
    $timeBefore = TimeEntry::where('employee_id', $tapu->id)->orderBy('id')->pluck('id')->all();

    expect($attendanceBefore)->not->toBeEmpty()
        ->and($tapuAttendanceBefore)->toBe(0)
        ->and($timeBefore)->not->toBeEmpty();

    $this->actingAs($this->admin)
        ->put(roleTrackUrl($yaseen, 'tracking-mode'), ['tracking_mode' => TrackingMode::RemoteTimer->value]);
    $this->actingAs($this->admin)
        ->put(roleTrackUrl($tapu, 'tracking-mode'), ['tracking_mode' => TrackingMode::OfficeAttendance->value]);

    expect($yaseen->attendanceRecords()->orderBy('id')->pluck('id')->all())->toBe($attendanceBefore)
        ->and(TimeEntry::where('employee_id', $yaseen->id)->count())->toBe(0)
        ->and(TimeEntry::where('employee_id', $tapu->id)->orderBy('id')->pluck('id')->all())->toBe($timeBefore)
        // And the switch does not retroactively give Tapu the attendance rows he never had.
        ->and($tapu->attendanceRecords()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| The self-guard, stated once
|--------------------------------------------------------------------------
*/

it('refuses a self-change over HTTP with 403 for both fields', function (): void {
    $own = $this->admin->employee;

    $this->actingAs($this->admin)
        ->put(roleTrackUrl($own, 'role'), ['role' => RoleName::EMPLOYEE->value])
        ->assertForbidden();
    $this->actingAs($this->admin)
        ->put(roleTrackUrl($own, 'tracking-mode'), ['tracking_mode' => TrackingMode::None->value])
        ->assertForbidden();

    expect($own->fresh()->role->name)->toBe(RoleName::ADMIN)
        ->and($own->fresh()->tracking_mode)->toBe(TrackingMode::OfficeAttendance)
        ->and(AuditLog::count())->toBe(0);
});

it('refuses a self-change to a caller that is not a request either', function (): void {
    // The service is the single statement of the rule, so a console command, a job or a future
    // API caller is refused by the same line the controller is.
    $own = $this->admin->employee;

    expect(fn () => $this->service->changeTrackingMode($this->admin, $own, TrackingMode::None))
        ->toThrow(SelfModificationException::class);

    expect(fn () => $this->service->changeRole($this->admin, $own, RoleName::EMPLOYEE))
        ->toThrow(SelfModificationException::class);

    // …and somebody without `roles.manage` is refused for the other half of the same guard.
    expect(fn () => $this->service->changeTrackingMode($this->yaseen, $this->tapu->employee, TrackingMode::None))
        ->toThrow(AuthorizationException::class);

    expect($own->fresh()->tracking_mode)->toBe(TrackingMode::OfficeAttendance)
        ->and($this->tapu->employee->fresh()->tracking_mode)->toBe(TrackingMode::RemoteTimer)
        ->and(AuditLog::count())->toBe(0);
});

it('answers the policy the same way for both fields', function (): void {
    $tapu = $this->tapu->employee;
    $own = $this->admin->employee;

    expect(Gate::forUser($this->admin)->allows('changeTrackingMode', $tapu))->toBeTrue()
        ->and(Gate::forUser($this->admin)->allows('changeTrackingMode', $own))->toBeFalse()
        ->and(Gate::forUser($this->yaseen)->allows('changeTrackingMode', $tapu))->toBeFalse()
        ->and(Gate::forUser($this->accountant)->allows('changeTrackingMode', $tapu))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Who may reach the two endpoints
|--------------------------------------------------------------------------
*/

it('404s an employee outside the requester\'s scope rather than refusing', function (): void {
    $this->actingAs($this->admin)
        ->put('/admin/employees/999999/role', ['role' => RoleName::EMPLOYEE->value])
        ->assertNotFound();

    $this->actingAs($this->admin)
        ->put('/admin/employees/999999/tracking-mode', ['tracking_mode' => TrackingMode::None->value])
        ->assertNotFound();
});

it('sends a guest to login on both', function (): void {
    $employee = $this->tapu->employee;

    $this->put(roleTrackUrl($employee, 'role'), ['role' => RoleName::EMPLOYEE->value])->assertRedirect('/login');
    $this->put(roleTrackUrl($employee, 'tracking-mode'), ['tracking_mode' => TrackingMode::None->value])
        ->assertRedirect('/login');

    expect($employee->fresh()->role->name)->toBe(RoleName::REMOTE_EMPLOYEE)
        ->and($employee->fresh()->tracking_mode)->toBe(TrackingMode::RemoteTimer);
});
