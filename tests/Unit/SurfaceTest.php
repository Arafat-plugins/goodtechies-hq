<?php

use App\Support\AuditEvent;
use App\Support\RoleName;
use App\Support\Surface;

it('maps every role to exactly one surface', function (?RoleName $role, ?Surface $surface) {
    expect(Surface::forRole($role))->toBe($surface);
})->with([
    'admin' => [RoleName::ADMIN, Surface::Admin],
    'employee' => [RoleName::EMPLOYEE, Surface::Employee],
    'remote employee' => [RoleName::REMOTE_EMPLOYEE, Surface::Employee],
    'manager (dormant, least privilege)' => [RoleName::MANAGER, Surface::Employee],
    'accountant' => [RoleName::ACCOUNTANT, Surface::Accountant],
    'no role' => [null, null],
])->group('phase0');

it('names the home route of each surface', function () {
    expect(Surface::Admin->homeRoute())->toBe('admin.dashboard')
        ->and(Surface::Employee->homeRoute())->toBe('employee.dashboard')
        ->and(Surface::Accountant->homeRoute())->toBe('accountant.dashboard');
})->group('phase0');

it('uses dotted audit event values', function () {
    // 27 since Phase 4 added the three events that move somebody's pay quietly on their own
    // record: `time_entry.edited`, `attendance.edited` and `schedule.changed`. Part C §4's
    // list does not name any of them, and that is an omission rather than a decision — each
    // one changes what Phase 9 will pay, so each is recorded with old and new values.
    // (It was 24 from Phase 2 slice 4, which added `tag.deleted`.)
    expect(AuditEvent::cases())->toHaveCount(27)
        ->and(AuditEvent::TwoFactorDisabled->value)->toBe('user.two_factor_disabled')
        ->and(AuditEvent::RestrictedAccessAttempt->value)->toBe('access.restricted_attempt');
})->group('phase0');
