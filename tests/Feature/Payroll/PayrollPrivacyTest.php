<?php

use App\Http\Resources\PayrollItemResource;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\PayrollItem;
use App\Models\PayrollPeriod;
use App\Models\User;
use App\Services\PayrollService;
use App\Support\AuditEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;

/*
|--------------------------------------------------------------------------
| Payroll privacy — the sharpest rule in the phase
|--------------------------------------------------------------------------
|
| Master prompt Part B §3 rule 1 and Phase 9's own security list:
|
|   "Employee requesting any other employee's payroll item — list endpoints
|    OMIT it, a direct id returns 404, and the attempt is AUDIT-LOGGED."
|
| Three separate things, and this file has a test per thing, named so that a
| failure says which of the three broke:
|
|   1. omission  — PayrollItem::scopeVisibleTo(), via PayrollService::itemsFor()
|   2. 404       — PayrollService::findItemFor(), never a 403
|   3. audit row — access.restricted_attempt, the existing event
|
| Plus Part D §14's field rule: "admin_notes is the 'personal notes' column
| the Accountant never receives" — ABSENT, not null and not masked (Part C).
| Asserted with array_key_exists, because `=== null` passes for both the right
| answer and the wrong one, and paired with a recursive forbidden-key walk in
| the shape tests/Feature/Finance/AccountantProjectEndpointTest.php uses.
|
| Every constant and helper here is prefixed PAYROLL_ / payrollPrivacy*,
| because Pest declares both globally across the whole suite (AGENTS.md).
|
*/

/** Every key `PayrollItemResource` sends to a reader who is not an Admin, and the whole of it. */
const PAYROLL_PRIVACY_ITEM_KEYS = [
    'id',
    'period',
    'employee',
    'base_salary',
    'allowance',
    'bonus',
    'deduction',
    'advance',
    'leave_impact',
    'net_salary',
    'permissions',
];

/** The same, plus the one key only an Admin gets. */
const PAYROLL_PRIVACY_ADMIN_KEYS = [...PAYROLL_PRIVACY_ITEM_KEYS, 'admin_notes'];

/**
 * Names that must never appear in a non-Admin's payload at ANY depth.
 *
 * The exact-key assertions are the real test; this is the second net, because a nested object
 * satisfies a top-level key list and still carries a forbidden field underneath. `admin_notes`
 * heads the list, and the variants beside it are the point of the walk: a test that checked one
 * key passes the day somebody adds `notes_internal`.
 */
const PAYROLL_PRIVACY_FORBIDDEN_KEYS = [
    'admin_notes',
    'notes',
    'notes_internal',
    'internal_notes',
    'personal_notes',
    'private_notes',
    'admin_note',
    'comment',
    'comments',
    // Facts about a person that a payslip is not a directory for.
    'email',
    'phone',
    'employee_number',
    'joining_date',
    'employment_type',
    'tracking_mode',
    'manager',
    'manager_id',
    'role',
    'role_id',
    'user_id',
    // NOT 'status': `period.status` is a deliberate part of this payload — a payslip has to
    // say whether the month is a draft or paid. The employee's EMPLOYMENT status is kept out
    // by the exact-key assertion on the `employee` block instead, which is the tighter net
    // for a name that means two different things at two depths.
    'schedule',
    'salary_history',
    'salaries',
    'base_salary_history',
    // Somebody else's money, and the company's.
    'items',
    'net_total',
    'total',
    'totals',
    'lock_reversal',
];

/**
 * Every key at every depth of a payload, flattened.
 *
 * @param  array<mixed>  $payload
 * @return list<string>
 */
function payrollPrivacyKeysDeep(array $payload): array
{
    $found = [];

    foreach ($payload as $key => $value) {
        if (is_string($key)) {
            $found[] = $key;
        }

        if (is_array($value)) {
            $found = [...$found, ...payrollPrivacyKeysDeep($value)];
        }
    }

    return $found;
}

/** The item as this viewer receives it — the only way a payroll item leaves the server. */
function payrollPrivacyPayload(PayrollItem $item, User $viewer): array
{
    $request = Request::create('/payroll/items/'.$item->getKey());
    $request->setUserResolver(fn (): User => $viewer);

    return PayrollItemResource::make($item->load(['period', 'employee.user']))->toArray($request);
}

function payrollPrivacyEmployee(string $email): Employee
{
    return User::where('email', $email)->firstOrFail()->employee;
}

beforeEach(function () {
    $this->seed();

    $this->service = app(PayrollService::class);

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->accountant = User::where('email', 'accountant@goodtechies.test')->firstOrFail();
    $this->yaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();
    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();

    // The seeded September draft, with one line per active employee — the real shape.
    $this->period = PayrollPeriod::query()->firstOrFail();

    $this->yaseensItem = PayrollItem::where('employee_id', $this->yaseen->employee->getKey())->firstOrFail();
    $this->tapusItem = PayrollItem::where('employee_id', $this->tapu->employee->getKey())->firstOrFail();
    $this->accountantsItem = PayrollItem::where('employee_id', $this->accountant->employee->getKey())->firstOrFail();

    // A personal note, so the absence tests are about a real value rather than a null.
    $this->service->annotate($this->admin, $this->yaseensItem, 'Advance to be recovered in October.');
    $this->yaseensItem->refresh();
});

/*
|--------------------------------------------------------------------------
| 1 of 3 — omission from lists
|--------------------------------------------------------------------------
*/

it('omits every other employee from an employee list of payroll items', function () {
    $ids = $this->service->itemsFor($this->yaseen)->pluck('id')->all();

    expect($ids)->toBe([(int) $this->yaseensItem->getKey()])
        ->and($ids)->not->toContain((int) $this->tapusItem->getKey());
})->group('phase9');

it('gives the admin and the accountant every line in the month', function (string $who) {
    $viewer = $who === 'admin' ? $this->admin : $this->accountant;

    expect($this->service->itemsFor($viewer, $this->period)->count())
        ->toBe($this->period->items()->count())
        ->toBeGreaterThan(1);
})->with(['admin', 'accountant'])->group('phase9');

it('shows the accountant their own payslip — they are an employee too', function () {
    // Part C §1 gives the ACCOUNTANT "view own payslip", and the Accountant shell carries
    // My Payslip because of it (Part D §2, decision 24).
    $ids = $this->service->itemsFor($this->accountant)->pluck('id')->all();

    expect($ids)->toContain((int) $this->accountantsItem->getKey())
        ->and($this->service->findItemFor($this->accountant, (int) $this->accountantsItem->getKey())->id)
        ->toBe($this->accountantsItem->id);
})->group('phase9');

it('gives a deactivated user nothing at all', function () {
    $this->yaseen->employee->update(['status' => 'inactive']);
    $this->yaseen->update(['status' => 'inactive']);

    expect($this->service->itemsFor($this->yaseen->refresh())->count())->toBe(0);
})->group('phase9');

/*
|--------------------------------------------------------------------------
| 2 of 3 — a direct id is 404, never 403
|--------------------------------------------------------------------------
*/

it('answers 404 and not 403 for another employee payroll item by id', function () {
    expect(fn () => $this->service->findItemFor($this->yaseen, (int) $this->tapusItem->getKey()))
        ->toThrow(ModelNotFoundException::class);
})->group('phase9');

it('answers the same 404 for an id that does not exist at all', function () {
    // Byte-identical to the refusal above, so nothing about what is logged is observable.
    expect(fn () => $this->service->findItemFor($this->yaseen, 99999))
        ->toThrow(ModelNotFoundException::class);
})->group('phase9');

it('finds an employee own payroll item by id', function () {
    expect($this->service->findItemFor($this->yaseen, (int) $this->yaseensItem->getKey())->id)
        ->toBe($this->yaseensItem->id);
})->group('phase9');

/*
|--------------------------------------------------------------------------
| 3 of 3 — the attempt is audit-logged
|--------------------------------------------------------------------------
*/

it('audit-logs an attempt on another employee payroll item', function () {
    expect(AuditLog::where('event', AuditEvent::RestrictedAccessAttempt->value)->count())->toBe(0);

    try {
        $this->service->findItemFor($this->yaseen, (int) $this->tapusItem->getKey());
    } catch (ModelNotFoundException) {
        // The refusal is the point; the row it left behind is what this test is about.
    }

    $row = AuditLog::where('event', AuditEvent::RestrictedAccessAttempt->value)->sole();

    expect((int) $row->actor_id)->toBe((int) $this->yaseen->getKey())
        ->and($row->target_id)->toBe((int) $this->tapusItem->getKey())
        ->and($row->new_value['resource'])->toBe('payroll_item')
        ->and($row->new_value['employee_id'])->toBe((int) $this->tapu->employee->getKey());

    // And the row does NOT carry the figures the read was refused.
    expect($row->new_value)->not->toHaveKey('net_salary')
        ->and($row->new_value)->not->toHaveKey('base_salary');
})->group('phase9');

it('writes no audit row when somebody looks up their own item or a missing id', function () {
    $this->service->findItemFor($this->yaseen, (int) $this->yaseensItem->getKey());

    try {
        $this->service->findItemFor($this->yaseen, 99999);
    } catch (ModelNotFoundException) {
    }

    expect(AuditLog::where('event', AuditEvent::RestrictedAccessAttempt->value)->count())->toBe(0);
})->group('phase9');

/*
|--------------------------------------------------------------------------
| admin_notes — absent for everybody but an Admin
|--------------------------------------------------------------------------
*/

it('sends admin_notes to an admin', function () {
    $payload = payrollPrivacyPayload($this->yaseensItem, $this->admin);

    expect(array_key_exists('admin_notes', $payload))->toBeTrue()
        ->and($payload['admin_notes'])->toBe('Advance to be recovered in October.')
        ->and(array_keys($payload))->toEqualCanonicalizing(PAYROLL_PRIVACY_ADMIN_KEYS);
})->group('phase9');

it('leaves admin_notes ABSENT for the accountant, not null and not masked', function () {
    $payload = payrollPrivacyPayload($this->yaseensItem, $this->accountant);

    // array_key_exists, not `=== null`: `=== null` passes for the right answer and the wrong
    // one alike, and the wrong one is the bug this rule exists to prevent.
    expect(array_key_exists('admin_notes', $payload))->toBeFalse();
})->group('phase9');

it('leaves admin_notes absent for the employee the note is about', function () {
    $payload = payrollPrivacyPayload($this->yaseensItem, $this->yaseen);

    expect(array_key_exists('admin_notes', $payload))->toBeFalse();
})->group('phase9');

it('sends exactly eleven keys to a non-admin and not one more', function (string $who) {
    $viewer = $who === 'accountant' ? $this->accountant : $this->yaseen;

    $payload = payrollPrivacyPayload($this->yaseensItem, $viewer);

    expect(array_keys($payload))->toEqualCanonicalizing(PAYROLL_PRIVACY_ITEM_KEYS);
})->with(['accountant', 'own employee'])->group('phase9');

it('sends exactly two keys about the person and five about the month', function (string $who) {
    $viewer = $who === 'accountant' ? $this->accountant : $this->yaseen;

    $payload = payrollPrivacyPayload($this->yaseensItem, $viewer);

    // A payslip is not a staff directory and it is not the company's payroll summary.
    expect(array_keys($payload['employee']))->toEqualCanonicalizing(['id', 'name'])
        ->and(array_keys($payload['period']))
        ->toEqualCanonicalizing(['id', 'month', 'label', 'status', 'status_label', 'state']);
})->with(['accountant', 'own employee'])->group('phase9');

it('carries no forbidden key at any depth for a non-admin', function (string $who) {
    $viewer = $who === 'accountant' ? $this->accountant : $this->yaseen;

    $keys = payrollPrivacyKeysDeep(payrollPrivacyPayload($this->yaseensItem, $viewer));

    expect(array_values(array_intersect($keys, PAYROLL_PRIVACY_FORBIDDEN_KEYS)))->toBe([]);
})->with(['accountant', 'own employee'])->group('phase9');

it('still sends the accountant every amount', function () {
    // Part C §1's cell is "🟡 amounts, no personal notes" — the notes go, the money stays.
    $payload = payrollPrivacyPayload($this->yaseensItem, $this->accountant);

    expect($payload['base_salary'])->toBe($this->yaseensItem->base_salary)
        ->and($payload['allowance'])->toBe($this->yaseensItem->allowance)
        ->and($payload['net_salary'])->toBe($this->yaseensItem->net_salary)
        ->and($payload['employee']['name'])->toBe($this->yaseen->name);
})->group('phase9');

it('lets only an admin write the personal notes column', function () {
    foreach ([$this->accountant, $this->yaseen, $this->tapu] as $actor) {
        expect(fn () => $this->service->annotate($actor, $this->tapusItem, 'Mine now.'))
            ->toThrow(AuthorizationException::class);
    }

    expect($this->tapusItem->refresh()->admin_notes)->toBeNull();
})->group('phase9');
