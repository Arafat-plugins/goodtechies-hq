<?php

use App\Exceptions\SelfModificationException;
use App\Models\ActivityLog;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\EmployeeAdministrationService;
use App\Support\RoleName;
use App\Support\TrackingMode;
use App\Support\UserStatus;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    $this->seed();
    $this->service = app(EmployeeAdministrationService::class);
    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->yaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();
    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();
});

function insertSession(string $id, ?int $userId, int $lastActivity): void
{
    DB::table('sessions')->insert([
        'id' => $id,
        'user_id' => $userId,
        'ip_address' => '198.51.100.1',
        'user_agent' => 'PestAgent',
        'payload' => base64_encode('a:0:{}'),
        'last_activity' => $lastActivity,
    ]);
}

it('lets an admin change another employee\'s role with audit and activity rows', function () {
    $employee = $this->yaseen->employee;

    $this->service->changeRole($this->admin, $employee, RoleName::ACCOUNTANT);

    $employee->refresh();
    $audit = AuditLog::where('event', 'role.changed')->sole();
    $activity = ActivityLog::where('object_id', $employee->id)->sole();

    expect($employee->role->name)->toBe(RoleName::ACCOUNTANT)
        ->and($employee->tracking_mode)->toBe(TrackingMode::OfficeAttendance)
        ->and($audit->actor_id)->toBe($this->admin->id)
        ->and($audit->target_type)->toBe($employee->getMorphClass())
        ->and($audit->target_id)->toBe($employee->id)
        ->and($audit->old_value)->toBe(['role' => 'EMPLOYEE'])
        ->and($audit->new_value)->toBe(['role' => 'ACCOUNTANT'])
        ->and($activity->description)->toBe('Role changed from EMPLOYEE to ACCOUNTANT')
        ->and($activity->actor_id)->toBe($this->admin->id);
})->group('phase0');

it('refuses an admin changing their own role', function () {
    $employee = $this->admin->employee;

    expect(fn () => $this->service->changeRole($this->admin, $employee, RoleName::EMPLOYEE))
        ->toThrow(SelfModificationException::class);

    expect($employee->fresh()->role->name)->toBe(RoleName::ADMIN)
        ->and(AuditLog::count())->toBe(0)
        ->and(ActivityLog::count())->toBe(0);
})->group('phase0');

it('refuses an admin deactivating themselves', function () {
    expect(fn () => $this->service->deactivate($this->admin, $this->admin->employee))
        ->toThrow(SelfModificationException::class);

    expect($this->admin->fresh()->status)->toBe(UserStatus::Active)
        ->and(AuditLog::count())->toBe(0);
})->group('phase0');

it('refuses an employee changing someone else', function () {
    $employee = $this->tapu->employee;

    expect(fn () => $this->service->changeRole($this->yaseen, $employee, RoleName::ADMIN))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => $this->service->deactivate($this->yaseen, $employee))
        ->toThrow(AuthorizationException::class);

    expect($employee->fresh()->role->name)->toBe(RoleName::REMOTE_EMPLOYEE)
        ->and($this->tapu->fresh()->status)->toBe(UserStatus::Active)
        ->and(AuditLog::count())->toBe(0);
})->group('phase0');

it('deactivates employee and user, ends their sessions and keeps the user row', function () {
    insertSession('tapu-1', $this->tapu->id, now()->timestamp);
    insertSession('tapu-2', $this->tapu->id, now()->timestamp);
    insertSession('admin-1', $this->admin->id, now()->timestamp);

    $this->service->deactivate($this->admin, $this->tapu->employee);

    expect($this->tapu->employee->fresh()->status)->toBe(UserStatus::Inactive)
        ->and(User::find($this->tapu->id))->not->toBeNull()
        ->and(User::find($this->tapu->id)->status)->toBe(UserStatus::Inactive)
        ->and(DB::table('sessions')->where('user_id', $this->tapu->id)->count())->toBe(0)
        ->and(DB::table('sessions')->where('user_id', $this->admin->id)->count())->toBe(1)
        ->and(AuditLog::where('event', 'employee.deactivated')->sole()->target_id)->toBe($this->tapu->employee->id);
})->group('phase0');

it('answers the employee policy for the same cases', function () {
    $adminEmployee = $this->admin->employee;
    $tapuEmployee = $this->tapu->employee;

    expect(Gate::forUser($this->admin)->allows('changeRole', $tapuEmployee))->toBeTrue()
        ->and(Gate::forUser($this->admin)->allows('deactivate', $tapuEmployee))->toBeTrue()
        ->and(Gate::forUser($this->admin)->allows('changeRole', $adminEmployee))->toBeFalse()
        ->and(Gate::forUser($this->admin)->allows('deactivate', $adminEmployee))->toBeFalse()
        ->and(Gate::forUser($this->admin)->allows('view', $tapuEmployee))->toBeTrue()
        ->and(Gate::forUser($this->yaseen)->allows('changeRole', $tapuEmployee))->toBeFalse()
        ->and(Gate::forUser($this->yaseen)->allows('deactivate', $tapuEmployee))->toBeFalse()
        ->and(Gate::forUser($this->yaseen)->allows('view', $tapuEmployee))->toBeFalse()
        ->and(Gate::forUser($this->yaseen)->allows('view', $this->yaseen->employee))->toBeTrue();
})->group('phase0');
