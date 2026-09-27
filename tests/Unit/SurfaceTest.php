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
    // **This asserted a COUNT until Phase 8, and the count was wrong three times** — once per
    // phase that added an event, each time as a red test in an unrelated file that told whoever
    // hit it nothing except a number to change. Decision 4-17 said it should assert the SET
    // instead; this is that, finally done, and the reason it is worth the lines is that a
    // count only ever catches "somebody added an event", which is not a defect. What IS a
    // defect is an event that does not look like the others: `AuditEvent` is written into
    // `audit_logs.event` — an indexed string with no CHECK behind it — and every report,
    // filter and future retention rule groups on that string's shape.
    //
    // So: every case is `subject.verb`, lower snake_case on both sides, and no two cases share
    // a value. Adding an event needs no edit here. Getting one's SHAPE wrong fails here, in
    // the file that is about shapes.
    $values = array_map(fn (AuditEvent $case): string => $case->value, AuditEvent::cases());

    expect($values)->not->toBeEmpty()
        ->and($values)->toEqual(array_unique($values))
        ->and(array_filter($values, fn (string $value): bool => preg_match('/^[a-z0-9_]+\\.[a-z0-9_]+$/', $value) !== 1))->toBe([]);

    // Three named ones, for the same reason a schema test names a column: these three are
    // quoted in Part C §4 and in decisions, so a rename has to be a deliberate act here too.
    expect(AuditEvent::TwoFactorDisabled->value)->toBe('user.two_factor_disabled')
        ->and(AuditEvent::LeaveBalanceAdjusted->value)->toBe('leave.balance_adjusted')
        ->and(AuditEvent::RestrictedAccessAttempt->value)->toBe('access.restricted_attempt');
})->group('phase0');
