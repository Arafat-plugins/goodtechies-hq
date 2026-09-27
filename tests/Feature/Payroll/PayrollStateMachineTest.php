<?php

use App\Exceptions\PayrollStateException;
use App\Models\AuditLog;
use App\Models\PayrollItem;
use App\Models\PayrollPeriod;
use App\Models\User;
use App\Services\PayrollService;
use App\Support\AuditEvent;
use App\Support\PayrollStatus;
use Illuminate\Auth\Access\AuthorizationException;

/*
|--------------------------------------------------------------------------
| The payroll state machine — every transition, allowed and forbidden
|--------------------------------------------------------------------------
|
| Master prompt Part D §14:
|
|   "DRAFT → CALCULATED → REVIEWED → APPROVED → LOCKED → PAID. Accountant
|    fills/adjusts …; Admin reviews and approves (read-only to Accountant
|    afterwards); Lock closes the period; only ADMIN can reverse a lock
|    (requires reason, audit-logged)."
|
| Two rules this file is really about:
|
|   1. **Every refusal is a sentence.** A refused move throws — an
|      AuthorizationException when it is about the person, a
|      PayrollStateException naming the status when it is about the period.
|      Never a silent no-op, which is the bug report nobody can debug.
|   2. **The status has one door.** PayrollPeriod::applyTransition(), guarded
|      at the model (decision 2-9, a fifth time).
|
| Every constant and helper here is prefixed PAYROLL_ / payrollState*,
| because Pest declares both globally across the whole suite (AGENTS.md).
|
*/

/** A period walked to this status through the real machine, with one line on it. */
function payrollStatePeriod(PayrollStatus $status): PayrollPeriod
{
    $period = PayrollPeriod::factory()->forMonth('2026-09-01')->at($status)->create();

    PayrollItem::factory()->in($period)->create();

    return $period->refresh();
}

beforeEach(function () {
    $this->seed();

    $this->service = app(PayrollService::class);

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->accountant = User::where('email', 'accountant@goodtechies.test')->firstOrFail();
    $this->employee = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();
    $this->remote = User::where('email', 'tapu@goodtechies.test')->firstOrFail();

    // The seeded September draft is in the way of a factory-made one — a month has exactly one
    // period, by unique index. Tests here make their own.
    PayrollPeriod::query()->delete();
});

/*
|--------------------------------------------------------------------------
| The forward path, and who may take each step
|--------------------------------------------------------------------------
*/

it('walks a month from draft to paid with the right role on each step', function () {
    $period = payrollStatePeriod(PayrollStatus::Draft);

    // Accountant: draft and calculate. Part C §1's "🟡 draft/calculate only".
    expect($this->service->calculate($this->accountant, $period)->status)->toBe(PayrollStatus::Calculated);

    // Admin: everything from review onwards.
    expect($this->service->review($this->admin, $period)->status)->toBe(PayrollStatus::Reviewed)
        ->and($this->service->approve($this->admin, $period)->status)->toBe(PayrollStatus::Approved)
        ->and($this->service->lock($this->admin, $period)->status)->toBe(PayrollStatus::Locked)
        ->and($this->service->markPaid($this->admin, $period)->status)->toBe(PayrollStatus::Paid);
})->group('phase9');

it('stamps locked_at on the lock and clears it on the reversal', function () {
    $period = payrollStatePeriod(PayrollStatus::Approved);

    expect($period->locked_at)->toBeNull();

    $this->service->lock($this->admin, $period);

    expect($period->refresh()->locked_at)->not->toBeNull();

    $this->service->reverseLock($this->admin, $period, 'September was closed a week early.');

    expect($period->refresh()->locked_at)->toBeNull()
        ->and($period->status)->toBe(PayrollStatus::Approved);
})->group('phase9');

/*
|--------------------------------------------------------------------------
| Forbidden by role — the Accountant cannot approve or lock
|--------------------------------------------------------------------------
*/

it('refuses the accountant every step from review onwards', function (string $method) {
    $period = payrollStatePeriod(match ($method) {
        'review' => PayrollStatus::Calculated,
        'approve' => PayrollStatus::Reviewed,
        'lock' => PayrollStatus::Approved,
        'markPaid' => PayrollStatus::Locked,
        default => PayrollStatus::Draft,
    });

    expect(fn () => $this->service->{$method}($this->accountant, $period))
        ->toThrow(AuthorizationException::class);

    // And nothing moved. A refusal that half-applied would be worse than one that threw.
    expect($period->refresh()->status)->not->toBe(PayrollStatus::Paid);
})->with(['review', 'approve', 'lock', 'markPaid'])->group('phase9');

it('refuses an ordinary employee and a remote employee every step of the machine', function (string $role) {
    $actor = $role === 'employee' ? $this->employee : $this->remote;
    $period = payrollStatePeriod(PayrollStatus::Draft);

    foreach (['calculate', 'review', 'approve', 'lock', 'markPaid'] as $method) {
        expect(fn () => $this->service->{$method}($actor, $period))
            ->toThrow(AuthorizationException::class);
    }

    expect($period->refresh()->status)->toBe(PayrollStatus::Draft);
})->with(['employee', 'remote'])->group('phase9');

it('lets the accountant calculate and lets the admin calculate too', function () {
    $period = payrollStatePeriod(PayrollStatus::Draft);

    expect($this->service->calculate($this->accountant, $period)->status)->toBe(PayrollStatus::Calculated);

    // The Admin holds `payroll.draft` as well — Part C §1 gives them ✅ on the whole row.
    $second = PayrollPeriod::factory()->forMonth('2026-10-01')->create();

    expect($this->service->calculate($this->admin, $second)->status)->toBe(PayrollStatus::Calculated);
})->group('phase9');

/*
|--------------------------------------------------------------------------
| Forbidden by state — a sentence, never a silent no-op
|--------------------------------------------------------------------------
*/

it('refuses a move the machine does not have, with a sentence naming the status', function () {
    $period = payrollStatePeriod(PayrollStatus::Draft);

    // Draft → Approved skips two steps.
    expect(fn () => $this->service->approve($this->admin, $period))
        ->toThrow(PayrollStateException::class, 'A Draft payroll period cannot be moved to Approved');

    expect($period->refresh()->status)->toBe(PayrollStatus::Draft);
})->group('phase9');

it('says what a period can become when it refuses a move', function () {
    $period = payrollStatePeriod(PayrollStatus::Reviewed);

    expect(fn () => $this->service->lock($this->admin, $period))
        ->toThrow(PayrollStateException::class, 'From here it can only become Approved.');
})->group('phase9');

it('calls a paid period final rather than offering it a next step', function () {
    $period = payrollStatePeriod(PayrollStatus::Paid);

    expect(fn () => $this->service->markPaid($this->admin, $period))
        ->toThrow(PayrollStateException::class, 'It is final: nothing follows it.');
})->group('phase9');

it('refuses calculate once a period has been reviewed', function () {
    $period = payrollStatePeriod(PayrollStatus::Reviewed);

    expect(fn () => $this->service->calculate($this->accountant, $period))
        ->toThrow(PayrollStateException::class, 'can no longer be calculated');
})->group('phase9');

it('guards the status against any write that does not go through the machine', function () {
    $period = payrollStatePeriod(PayrollStatus::Draft);

    expect(function () use ($period) {
        $period->status = PayrollStatus::Locked;
        $period->save();
    })->toThrow(PayrollStateException::class, 'PayrollPeriod::applyTransition()');

    expect(PayrollPeriod::find($period->getKey())->status)->toBe(PayrollStatus::Draft);
})->group('phase9');

/*
|--------------------------------------------------------------------------
| The lock reversal — ADMIN only, reason required, audit-logged
|--------------------------------------------------------------------------
*/

it('lets only an admin reverse a lock', function () {
    $period = payrollStatePeriod(PayrollStatus::Locked);

    foreach ([$this->accountant, $this->employee, $this->remote] as $actor) {
        expect(fn () => $this->service->reverseLock($actor, $period, 'A September invoice arrived late.'))
            ->toThrow(AuthorizationException::class, 'Only an Admin can reverse a payroll lock.');
    }

    expect($period->refresh()->status)->toBe(PayrollStatus::Locked);

    $this->service->reverseLock($this->admin, $period, 'A September invoice arrived late.');

    expect($period->refresh()->status)->toBe(PayrollStatus::Approved);
})->group('phase9');

it('refuses a lock reversal with no reason', function (string $reason) {
    $period = payrollStatePeriod(PayrollStatus::Locked);

    expect(fn () => $this->service->reverseLock($this->admin, $period, $reason))
        ->toThrow(PayrollStateException::class, 'Reversing a lock needs a reason.');

    expect($period->refresh()->status)->toBe(PayrollStatus::Locked)
        ->and($period->lock_reversal_reason)->toBeNull();
})->with(['empty' => '', 'blank' => '    '])->group('phase9');

it('refuses to reverse a lock on a period that is not locked', function () {
    $period = payrollStatePeriod(PayrollStatus::Approved);

    expect(fn () => $this->service->reverseLock($this->admin, $period, 'Anything.'))
        ->toThrow(PayrollStateException::class, 'not locked, so there is no lock to reverse');
})->group('phase9');

it('records the lock reversal with its reason, on the row and in the audit log', function () {
    $period = payrollStatePeriod(PayrollStatus::Locked);
    $reason = 'A September hosting invoice arrived after the month was closed.';

    $this->service->reverseLock($this->admin, $period, $reason);

    // On the row: the current state of the most recent reversal.
    expect($period->refresh()->lock_reversal_reason)->toBe($reason)
        ->and((int) $period->lock_reversed_by)->toBe((int) $this->admin->getKey());

    // In the audit log: the record that cannot be edited or deleted (Part C §4).
    $row = AuditLog::where('event', AuditEvent::PayrollLockReversed->value)->sole();

    expect((int) $row->actor_id)->toBe((int) $this->admin->getKey())
        ->and($row->target_id)->toBe((int) $period->getKey())
        ->and($row->new_value['reason'])->toBe($reason)
        ->and($row->old_value['status'])->toBe('locked')
        ->and($row->new_value['status'])->toBe('approved');
})->group('phase9');

it('records payroll approved with the month and what it is worth', function () {
    $period = payrollStatePeriod(PayrollStatus::Reviewed);

    $this->service->approve($this->admin, $period);

    $row = AuditLog::where('event', AuditEvent::PayrollApproved->value)->sole();

    expect((int) $row->actor_id)->toBe((int) $this->admin->getKey())
        ->and($row->new_value['month'])->toBe('2026-09-01')
        ->and($row->new_value['status'])->toBe('approved')
        ->and($row->new_value['items'])->toBe(1)
        // One factory line: 1000 base + 100 allowance.
        ->and($row->new_value['net_total'])->toBe('1100.00');
})->group('phase9');

/*
|--------------------------------------------------------------------------
| Item edits — the window each role has
|--------------------------------------------------------------------------
*/

it('lets the accountant adjust figures up to approval and no further', function (string $status, ?string $refusal) {
    $period = payrollStatePeriod(PayrollStatus::from($status));
    $item = $period->items()->sole();

    if ($refusal === null) {
        expect($this->service->adjustItem($this->accountant, $item, ['bonus' => '50.00'])->bonus)
            ->toBe('50.00');

        return;
    }

    // A sentence either way, and which sentence matters: after approval the Accountant is the
    // one refused (a permission answer), and from the lock onwards the PERIOD is (a state
    // answer, which an Admin would get too).
    expect(fn () => $this->service->adjustItem($this->accountant, $item, ['bonus' => '50.00']))
        ->toThrow($refusal);

    expect($item->refresh()->bonus)->toBe('0.00');
})->with([
    'draft' => ['draft', null],
    'calculated' => ['calculated', null],
    'reviewed' => ['reviewed', null],
    'approved — Part D\'s "read-only to Accountant afterwards"' => ['approved', 'You are not allowed to change this payroll item.'],
    'locked' => ['locked', 'This payroll period is locked, so its figures can no longer be changed.'],
    'paid' => ['paid', 'This payroll period is paid, so its figures can no longer be changed.'],
])->group('phase9');

it('lets an admin adjust figures up to the lock and no further', function (string $status, bool $allowed) {
    $period = payrollStatePeriod(PayrollStatus::from($status));
    $item = $period->items()->sole();

    if ($allowed) {
        expect($this->service->adjustItem($this->admin, $item, ['bonus' => '75.00'])->bonus)->toBe('75.00');
    } else {
        expect(fn () => $this->service->adjustItem($this->admin, $item, ['bonus' => '75.00']))
            ->toThrow(PayrollStateException::class, 'can no longer be changed');
    }
})->with([
    'draft' => ['draft', true],
    'approved' => ['approved', true],
    'locked' => ['locked', false],
    'paid' => ['paid', false],
])->group('phase9');

it('recomputes the net in the database on every adjustment', function () {
    $period = payrollStatePeriod(PayrollStatus::Draft);
    $item = $period->items()->sole();

    expect($item->net_salary)->toBe('1100.00');

    $adjusted = $this->service->adjustItem($this->accountant, $item, [
        'bonus' => '200.00',
        'deduction' => '50.00',
        'advance' => '100.00',
    ]);

    // 1000 + 100 + 200 - 50 - 100 = 1150. Computed by PostgreSQL, not by PHP and not by Vue.
    expect($adjusted->net_salary)->toBe('1150.00');
})->group('phase9');

it('refuses to let anybody write the net or the leave impact through an adjustment', function () {
    $period = payrollStatePeriod(PayrollStatus::Draft);
    $item = $period->items()->sole();

    $this->service->adjustItem($this->accountant, $item, [
        'net_salary' => '9999.00',
        'leave_impact' => '500.00',
    ]);

    expect($item->refresh()->net_salary)->toBe('1100.00')
        ->and($item->leave_impact)->toBe('0.00');
})->group('phase9');

it('names no role but ADMIN in the payroll policies', function (string $policy) {
    $source = file_get_contents(app_path('Policies/'.$policy.'.php'));

    expect($source)->not->toContain('RoleName::ACCOUNTANT')
        ->and($source)->not->toContain('RoleName::EMPLOYEE')
        ->and($source)->not->toContain('RoleName::REMOTE_EMPLOYEE')
        ->and($source)->not->toContain('RoleName::MANAGER');
})->with(['PayrollPeriodPolicy', 'PayrollItemPolicy', 'EmployeeSalaryPolicy'])->group('phase9');
