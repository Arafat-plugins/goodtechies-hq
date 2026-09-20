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
    expect(AuditEvent::cases())->toHaveCount(22)
        ->and(AuditEvent::TwoFactorDisabled->value)->toBe('user.two_factor_disabled')
        ->and(AuditEvent::RestrictedAccessAttempt->value)->toBe('access.restricted_attempt');
})->group('phase0');
