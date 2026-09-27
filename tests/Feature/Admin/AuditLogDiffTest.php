<?php

use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\AuditEvent;

/*
|--------------------------------------------------------------------------
| The old/new diff (Phase 12, Admin → Audit Log)
|--------------------------------------------------------------------------
|
| Part E, Phase 12 asks for an "old/new diff". Two blobs of JSON side by side
| are not one: a reader looking at an eleven-key payload with one change has to
| read all eleven twice to find it. So `AuditLogResource` reads the pair into
| which field moved, from what, to what — and this file is that reading.
|
| The distinctions it has to get right, each of which is a different fact about
| a record and each of which has its own test below:
|
|   * A **created** record has no earlier value. Every field is new, and that is
|     not the same as every field being unchanged.
|   * A **deleted** record has no later value, and the audit row is all that is
|     left of it (`income` and `expenses` are hard deletes, which is why
|     `Income::auditValues()` records every column).
|   * A key **absent** from one side was never recorded there. A key present
|     with the value **null** was recorded as empty. Those are two different
|     answers to "when did her allowance stop being set".
|   * A payload that is not a map of fields cannot be diffed field by field.
|     Nothing in this build writes one; `jsonb` allows it.
|
| **The values are not redacted, and that is the rule.** Part C §4 requires
| `salary.changed` and `project.price_changed` to be recorded *with old and new
| values*; a viewer that stripped them would leave a compliance table nobody can
| audit. What keeps it safe is that `AuditLogResource` has exactly one caller and
| that caller is behind `can:audit.view`, which is ADMIN alone — asserted in
| tests/Feature/Admin/AuditLogViewerTest.php.
|
| Every helper is prefixed auditDiff*, because Pest declares them globally
| across the whole suite (AGENTS.md).
|
*/

beforeEach(function (): void {
    $this->seed();

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
});

/**
 * The resolved payload for one unsaved audit row.
 *
 * Unsaved on purpose: the diff is a pure reading of the two values and nothing about it depends
 * on the row existing, so there is no reason to spend an INSERT per case.
 *
 * @return array<string, mixed>
 */
function auditDiffOf(mixed $old, mixed $new, AuditEvent|string $event = AuditEvent::SalaryChanged): array
{
    $log = new AuditLog([
        'event' => $event instanceof AuditEvent ? $event->value : $event,
        'target_type' => 'App\Models\EmployeeSalary',
        'target_id' => 4,
        'old_value' => $old,
        'new_value' => $new,
    ]);

    return AuditLogResource::make($log)->resolve()['diff'];
}

/**
 * One field out of a resolved diff.
 *
 * @param  array<string, mixed>  $diff
 * @return array<string, mixed>
 */
function auditDiffField(array $diff, string $key): array
{
    $field = collect($diff['fields'])->firstWhere('key', $key);

    expect($field)->not->toBeNull("the diff has no field [{$key}]");

    return $field;
}

/*
|--------------------------------------------------------------------------
| The four kinds
|--------------------------------------------------------------------------
*/

it('reads a created record as created, with every field set', function (): void {
    $diff = auditDiffOf(null, [
        'id' => 12,
        'employee_id' => 4,
        'employee_name' => 'Nadia Rahman',
        'base_salary' => '30000.00',
        'allowance' => null,
    ]);

    expect($diff['kind'])->toBe('created')
        ->and($diff['fields'])->toHaveCount(5)
        ->and($diff['changed_count'])->toBe(5)
        ->and($diff['unchanged_count'])->toBe(0)
        ->and(collect($diff['fields'])->pluck('state')->unique()->all())->toBe(['added']);

    // A field that was recorded AS NULL on a created record is still `added` — it exists, and it
    // is empty. That is not "not recorded".
    $allowance = auditDiffField($diff, 'allowance');

    expect($allowance['in_old'])->toBeFalse()
        ->and($allowance['in_new'])->toBeTrue()
        ->and($allowance['new'])->toBeNull()
        ->and($allowance['label'])->toBe('Allowance');
});

it('reads a deleted record as deleted, with every field removed', function (): void {
    $diff = auditDiffOf([
        'id' => 9,
        'amount' => '4500.00',
        'notes' => 'September retainer',
    ], null, AuditEvent::FinanceRecordDeleted);

    expect($diff['kind'])->toBe('deleted')
        ->and($diff['changed_count'])->toBe(3)
        ->and($diff['unchanged_count'])->toBe(0)
        ->and(collect($diff['fields'])->pluck('state')->unique()->all())->toBe(['removed']);

    $amount = auditDiffField($diff, 'amount');

    expect($amount['in_old'])->toBeTrue()
        ->and($amount['in_new'])->toBeFalse()
        ->and($amount['old'])->toBe('4500.00');
});

it('separates the fields that moved from the fields that did not', function (): void {
    $diff = auditDiffOf(
        [
            'id' => 12,
            'employee_id' => 4,
            'employee_name' => 'Nadia Rahman',
            'base_salary' => '25000.00',
            'allowance' => '1000.00',
            'effective_from' => '2026-09-01',
        ],
        [
            'id' => 12,
            'employee_id' => 4,
            'employee_name' => 'Nadia Rahman',
            'base_salary' => '30000.00',
            'allowance' => '1000.00',
            'effective_from' => '2026-10-01',
        ],
    );

    expect($diff['kind'])->toBe('updated')
        ->and($diff['changed_count'])->toBe(2)
        ->and($diff['unchanged_count'])->toBe(4);

    $salary = auditDiffField($diff, 'base_salary');

    expect($salary['state'])->toBe('changed')
        ->and($salary['old'])->toBe('25000.00')
        ->and($salary['new'])->toBe('30000.00')
        ->and($salary['label'])->toBe('Base salary');

    expect(auditDiffField($diff, 'allowance')['state'])->toBe('unchanged')
        ->and(auditDiffField($diff, 'employee_name')['state'])->toBe('unchanged');
});

it('reads an update with nothing different as updated and unchanged', function (): void {
    $diff = auditDiffOf(['role' => 'admin'], ['role' => 'admin'], AuditEvent::RoleChanged);

    // Not "created", not "empty": something was written, and nothing about it moved. The screen
    // says so in words rather than showing an empty diff.
    expect($diff['kind'])->toBe('updated')
        ->and($diff['changed_count'])->toBe(0)
        ->and($diff['unchanged_count'])->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Null is not absent
|--------------------------------------------------------------------------
*/

it('tells a value that was cleared from a key that was never recorded', function (): void {
    $diff = auditDiffOf(
        ['allowance' => '1000.00', 'note' => 'annual review'],
        ['allowance' => null],
    );

    // Present on both sides, emptied: `changed`, with a null after.
    $allowance = auditDiffField($diff, 'allowance');

    expect($allowance['state'])->toBe('changed')
        ->and($allowance['in_old'])->toBeTrue()
        ->and($allowance['in_new'])->toBeTrue()
        ->and($allowance['new'])->toBeNull();

    // Present before, gone from the shape afterwards: `removed`, which is a different fact.
    $note = auditDiffField($diff, 'note');

    expect($note['state'])->toBe('removed')
        ->and($note['in_new'])->toBeFalse()
        ->and($note['old'])->toBe('annual review');
});

/**
 * The fields are ordered by their label, and that is a decision forced by the column.
 *
 * `old_value` and `new_value` are `jsonb`, and PostgreSQL's `jsonb` does not keep an object's
 * keys in the order they were written — it normalises them by key length, then bytewise. A
 * salary payload written as (id, employee_id, employee_name, base_salary, allowance,
 * effective_from, set_by) reads back as (id, set_by, allowance, base_salary, employee_id,
 * employee_name, effective_from), which is what a reader would see if the diff "kept the
 * payload's order". It cannot: that order no longer exists by the time anybody reads the row.
 *
 * So the fields are sorted by the label on screen. The test uses a saved row on purpose — an
 * unsaved model would keep PHP's array order and prove nothing about the column.
 */
it('orders the fields by name, because jsonb has already lost the writer order', function (): void {
    $log = AuditLog::create([
        'event' => AuditEvent::SalaryChanged->value,
        'target_type' => 'App\Models\EmployeeSalary',
        'target_id' => 3,
        'old_value' => [
            'id' => 3, 'employee_id' => 3, 'employee_name' => 'Tapu Ahmed',
            'base_salary' => '32000.00', 'allowance' => '2500.00',
            'effective_from' => '2026-07-01', 'set_by' => 1,
        ],
        'new_value' => [
            'id' => 3, 'employee_id' => 3, 'employee_name' => 'Tapu Ahmed',
            'base_salary' => '38000.00', 'allowance' => '2500.00',
            'effective_from' => '2026-10-01', 'set_by' => 1,
        ],
    ]);

    // What the column gives back is not what was written — the premise of the decision.
    expect(array_keys($log->fresh()->old_value))->toBe([
        'id', 'set_by', 'allowance', 'base_salary', 'employee_id', 'employee_name', 'effective_from',
    ]);

    $diff = AuditLogResource::make($log->fresh())->resolve()['diff'];

    expect(collect($diff['fields'])->pluck('label')->all())->toBe([
        'Allowance', 'Base salary', 'Effective from', 'Employee id', 'Employee name', 'Id', 'Set by',
    ]);
});

it('includes a key only one side has, in the same order', function (): void {
    $diff = auditDiffOf(
        ['status' => 'absent', 'note' => null],
        ['status' => 'present', 'note' => 'corrected', 'clock_in' => '2026-09-25T09:05:00+06:00'],
        AuditEvent::AttendanceEdited,
    );

    expect(collect($diff['fields'])->pluck('key')->all())->toBe(['clock_in', 'note', 'status'])
        ->and(auditDiffField($diff, 'clock_in')['state'])->toBe('added');
});

/*
|--------------------------------------------------------------------------
| Payloads that are not fields
|--------------------------------------------------------------------------
*/

it('reads a payload that is not a map of fields as opaque rather than crashing', function (): void {
    // A list, not an object. No writer in this build produces one; `jsonb` allows it and an
    // older build might have.
    $diff = auditDiffOf(['first', 'second'], ['first']);

    expect($diff['kind'])->toBe('opaque')
        ->and($diff['fields'])->toBe([])
        ->and($diff['changed_count'])->toBe(0);
});

it('reads a row with no values at all as empty', function (): void {
    $diff = auditDiffOf(null, null, AuditEvent::UserLogin);

    expect($diff['kind'])->toBe('empty')
        ->and($diff['fields'])->toBe([]);
});

it('handles a nested value by comparing and carrying it whole', function (): void {
    // `tag.deleted` carries the ids of the tasks it took a label off.
    $diff = auditDiffOf(
        ['name' => 'urgent', 'task_ids' => [4, 9, 11]],
        ['name' => 'urgent', 'task_ids' => [4, 9]],
        AuditEvent::TagDeleted,
    );

    $ids = auditDiffField($diff, 'task_ids');

    expect($diff['kind'])->toBe('updated')
        ->and($ids['state'])->toBe('changed')
        ->and($ids['old'])->toBe([4, 9, 11])
        ->and($ids['new'])->toBe([4, 9]);
});

/*
|--------------------------------------------------------------------------
| The rest of the row
|--------------------------------------------------------------------------
*/

/**
 * The one thing this resource must NOT do.
 *
 * `old_value` and `new_value` go out verbatim, salary and all — that is what the log is for
 * (Part C §4). The containment is the route's single gate and this resource's single caller, not
 * a redaction here.
 */
it('carries a restricted field through untouched', function (): void {
    $log = new AuditLog([
        'event' => AuditEvent::SalaryChanged->value,
        'target_type' => 'App\Models\EmployeeSalary',
        'target_id' => 4,
        'old_value' => ['base_salary' => '25000.00', 'allowance' => '1000.00'],
        'new_value' => ['base_salary' => '30000.00', 'allowance' => '1500.00'],
    ]);

    $payload = AuditLogResource::make($log)->resolve();

    expect($payload['old_value'])->toBe(['base_salary' => '25000.00', 'allowance' => '1000.00'])
        ->and($payload['new_value'])->toBe(['base_salary' => '30000.00', 'allowance' => '1500.00'])
        ->and($payload['event_label'])->toBe('Salary changed')
        ->and($payload['event_group'])->toBe(AuditEvent::GROUP_MONEY)
        ->and($payload['event_known'])->toBeTrue();
});

it('names the kind of record without exposing a second copy of the mapping', function (string $stored, string $label): void {
    expect(AuditLogResource::targetLabel($stored))->toBe($label);
})->with([
    ['App\Models\PayrollPeriod', 'Payroll period'],
    ['App\Models\Employee', 'Employee'],
    ['App\Models\EmployeeSalary', 'Employee salary'],
    // A value that is not a class name at all — an older build, or a target recorded by hand.
    ['settings', 'Settings'],
]);

it('labels an unknown event without pretending to know it', function (): void {
    $payload = AuditLogResource::make(new AuditLog([
        'event' => 'ancient.ritual_performed',
        'old_value' => null,
        'new_value' => ['ok' => true],
    ]))->resolve();

    expect($payload['event'])->toBe('ancient.ritual_performed')
        ->and($payload['event_label'])->toBe('Ancient ritual performed')
        ->and($payload['event_group'])->toBe(AuditEvent::GROUP_UNRECOGNISED)
        ->and($payload['event_known'])->toBeFalse()
        ->and($payload['diff']['kind'])->toBe('created');
});
