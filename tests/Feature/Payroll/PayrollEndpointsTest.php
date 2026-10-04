<?php

use App\Exceptions\FinanceStateException;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\FinanceCategory;
use App\Models\PayrollItem;
use App\Models\PayrollPeriod;
use App\Models\User;
use App\Services\FinanceService;
use App\Services\PayrollService;
use App\Support\AuditEvent;
use App\Support\FinanceCategoryKind;
use App\Support\PayrollStatus;
use App\Support\RoleName;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

/*
|--------------------------------------------------------------------------
| The payroll workbench — the screens, and every move the month can make
|--------------------------------------------------------------------------
|
| Master prompt Part D §14 and Phase 9. Ten shared routes behind
| `can:payroll.draft`, which ADMIN and ACCOUNTANT hold and nobody else does,
| so Employees, Remote employees and the Manager are refused 403 at the gate.
|
| What this file is about, in the order the sections come:
|
|   1. Who may reach the two screens, and who is refused.
|   2. What the two payloads say — including the one key that must be ABSENT
|      for the Accountant rather than null (Part D §14, decision 9-10).
|   3. Writing a line: the net is recomputed BY THE DATABASE, a posted net is
|      refused rather than dropped, and the Accountant cannot write a note.
|   4. The state machine: every move allowed for the right role in the right
|      state, and refused WITH A SENTENCE for the wrong role or the wrong
|      state — never a silent no-op.
|   5. The lock reversal: a reason is required, and it reaches the audit log.
|   6. The two halves of Phase 9 meeting: locking September blocks a finance
|      write dated in it, and reversing the lock unblocks it — asserted
|      through `FinanceService`, the service the Accountant actually uses.
|
| Constants and helpers are prefixed PAYROLL_ENDPOINTS_ / payrollEndpoints*,
| because Pest declares both globally across the whole suite (AGENTS.md).
|
*/

/** The month `PayrollSeeder` drafts and `FinanceSeeder` fills — Part D §13's example. */
const PAYROLL_ENDPOINTS_MONTH = '2026-09-01';

/** A date inside that month, for the finance write the lock has to block. */
const PAYROLL_ENDPOINTS_MONTH_DAY = '2026-09-18';

/** The seeded September draft's five lines, at their seeded figures, come to this. */
const PAYROLL_ENDPOINTS_NET_TOTAL = '7400.00';

/** Every route in the group, as method + path. */
function payrollEndpointsRoutes(int $period, int $item): array
{
    return [
        ['get', '/payroll'],
        ['post', '/payroll'],
        ['get', "/payroll/{$period}"],
        ['put', "/payroll/{$period}/items/{$item}"],
        ['post', "/payroll/{$period}/calculate"],
        ['post', "/payroll/{$period}/review"],
        ['post', "/payroll/{$period}/approve"],
        ['post', "/payroll/{$period}/lock"],
        ['post', "/payroll/{$period}/reverse-lock"],
        ['post', "/payroll/{$period}/paid"],
    ];
}

/** The five state-machine verbs an ACCOUNTANT must never reach, as method + path. */
function payrollEndpointsAdminOnlyRoutes(int $period): array
{
    return [
        ['post', "/payroll/{$period}/review"],
        ['post', "/payroll/{$period}/approve"],
        ['post', "/payroll/{$period}/lock"],
        ['post', "/payroll/{$period}/reverse-lock"],
        ['post', "/payroll/{$period}/paid"],
    ];
}

/**
 * Walk the seeded September draft to a status through the real service.
 *
 * Deliberately not `->update(['status' => …])`: `PayrollPeriod` throws if `status` goes dirty
 * outside `applyTransition()`, and a test that set up its state by a route the application does
 * not have would be testing a state the application cannot produce.
 */
function payrollEndpointsWalkTo(PayrollStatus $status): PayrollPeriod
{
    $admin = payrollEndpointsUser('shahadat@goodtechies.test');
    $accountant = payrollEndpointsUser('accountant@goodtechies.test');
    $service = app(PayrollService::class);

    $period = PayrollPeriod::query()->forMonth(PAYROLL_ENDPOINTS_MONTH)->firstOrFail();

    if ($status === PayrollStatus::Draft) {
        return $period;
    }

    $service->calculate($accountant, $period);

    if ($status === PayrollStatus::Calculated) {
        return $period->refresh();
    }

    $service->review($admin, $period);

    if ($status === PayrollStatus::Reviewed) {
        return $period->refresh();
    }

    $service->approve($admin, $period);

    if ($status === PayrollStatus::Approved) {
        return $period->refresh();
    }

    $service->lock($admin, $period);

    if ($status === PayrollStatus::Paid) {
        $service->markPaid($admin, $period);
    }

    return $period->refresh();
}

function payrollEndpointsUser(string $email): User
{
    return User::where('email', $email)->firstOrFail();
}

/** The Inertia props of a rendered payroll page, as arrays — so key ABSENCE is testable. */
function payrollEndpointsProps(TestResponse $response): array
{
    return $response->viewData('page')['props'];
}

/** One line of the seeded September draft, by the employee's name. */
function payrollEndpointsItemFor(string $name): PayrollItem
{
    return PayrollItem::query()
        ->whereHas('employee.user', fn ($query) => $query->where('name', $name))
        ->whereHas('period', fn ($query) => $query->forMonth(PAYROLL_ENDPOINTS_MONTH))
        ->firstOrFail();
}

/** `net_salary` straight out of PostgreSQL — the generated column, not a model attribute. */
function payrollEndpointsNetInDatabase(int $itemId): string
{
    return (string) DB::table('payroll_items')->where('id', $itemId)->value('net_salary');
}

/** A complete, valid September income, for the finance half of the lock test. */
function payrollEndpointsIncomeBody(): array
{
    return [
        'category_id' => (int) FinanceCategory::query()
            ->ofKind(FinanceCategoryKind::Income)
            ->orderBy('id')
            ->firstOrFail()
            ->getKey(),
        'amount' => '400.00',
        'date' => PAYROLL_ENDPOINTS_MONTH_DAY,
        'notes' => 'A September receipt',
    ];
}

beforeEach(function () {
    $this->seed();

    // Pinned, so that "the current month" on the period list is September whatever day the
    // suite is run on — the Create-draft control and the empty state both hang off it, and a
    // test that passed only in September would be one nobody could reproduce in October.
    Carbon::setTestNow(Carbon::parse('2026-09-20 10:00:00', config('app.timezone')));

    $this->admin = payrollEndpointsUser('shahadat@goodtechies.test');
    $this->accountant = payrollEndpointsUser('accountant@goodtechies.test');
    $this->employee = payrollEndpointsUser('yaseen@goodtechies.test');
    $this->remote = payrollEndpointsUser('tapu@goodtechies.test');
    $this->manager = Employee::factory()->forRole(RoleName::MANAGER)->create()->user;

    $this->period = PayrollPeriod::query()->forMonth(PAYROLL_ENDPOINTS_MONTH)->firstOrFail();
    $this->item = payrollEndpointsItemFor('Yaseen');
    $this->payroll = app(PayrollService::class);
    $this->finance = app(FinanceService::class);
});

afterEach(function () {
    Carbon::setTestNow();
});

/*
|--------------------------------------------------------------------------
| 1. Who may reach it
|--------------------------------------------------------------------------
*/

it('renders the payroll period list for the admin and for the accountant', function (string $role) {
    $this->actingAs($this->{$role})
        ->get('/payroll')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Shared/Payroll/Index')
            ->has('periods', 1)
            ->where('periods.0.label', 'September 2026')
            ->where('periods.0.status', 'draft')
            ->where('periods.0.items_count', 5)
            ->where('periods.0.net_total', PAYROLL_ENDPOINTS_NET_TOTAL)
            // September already has a period, so the Create-draft control must not be offered.
            ->where('current_month.value', '2026-09')
            ->where('current_month.has_period', true)
            ->where('permissions.can_create', true));
})->with(['admin', 'accountant'])->group('phase9', 'payroll');

it('renders the payroll period detail for the admin and for the accountant', function (string $role) {
    $this->actingAs($this->{$role})
        ->get("/payroll/{$this->period->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Shared/Payroll/Show')
            ->where('period.label', 'September 2026')
            ->where('period.status', 'draft')
            ->where('period.net_total', PAYROLL_ENDPOINTS_NET_TOTAL)
            // Calculate is not a transition, so the screen is told about it separately.
            ->where('period.allows_calculation', true)
            ->has('items', 5)
            ->has('leave')
            // The six rungs of the ladder, resolved on the server so no Vue file holds a
            // second copy of the transition map.
            ->has('statuses', 5)
            ->where('statuses.0.value', 'draft')
            ->where('statuses.4.value', 'paid'));
})->with(['admin', 'accountant'])->group('phase9', 'payroll');

it('refuses every payroll route to a role holding no payroll.draft key', function (string $role) {
    foreach (payrollEndpointsRoutes($this->period->id, $this->item->id) as [$method, $path]) {
        $this->actingAs($this->{$role})->{$method}($path)->assertForbidden();
    }
})->with(['employee', 'remote', 'manager'])->group('phase9', 'payroll');

it('sends a guest to log in rather than refusing them on the payroll routes', function () {
    foreach (payrollEndpointsRoutes($this->period->id, $this->item->id) as [$method, $path]) {
        $this->{$method}($path)->assertRedirect('/login');
    }
})->group('phase9', 'payroll');

/*
|--------------------------------------------------------------------------
| 2. What the list and the detail say
|--------------------------------------------------------------------------
*/

it('offers the empty state, and the create-draft control, when no month has been drafted', function (string $role) {
    // The reachable empty state: `hq:create-payroll-draft` runs on the 1st, so a list with
    // nothing in it is the ordinary state of a fresh installation rather than a fault.
    PayrollItem::query()->delete();
    PayrollPeriod::query()->delete();

    $this->actingAs($this->{$role})
        ->get('/payroll')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('periods', 0)
            ->where('current_month.has_period', false)
            ->where('current_month.label', 'September 2026')
            ->where('permissions.can_create', true));
})->with(['admin', 'accountant'])->group('phase9', 'payroll');

it('drafts the current month from the list and lands on it', function () {
    PayrollItem::query()->delete();
    PayrollPeriod::query()->delete();

    // The Manager this file creates has no salary on record, and somebody with no salary is
    // SKIPPED rather than drafted at zero — a zero payslip is a statement, a missing line is a
    // question. So the draft succeeds with five lines and the answer names the sixth person,
    // which is why this lands on the error channel: it is the only one that gets read.
    $this->actingAs($this->accountant)
        ->post('/payroll')
        ->assertRedirectContains('/payroll/')
        ->assertSessionHas('error', fn (string $message) => str_contains($message, 'is drafted with 5 lines')
            && str_contains($message, 'no salary on record')
            && str_contains($message, (string) $this->manager->name));

    $period = PayrollPeriod::query()->forMonth(PAYROLL_ENDPOINTS_MONTH)->firstOrFail();

    expect($period->status)->toBe(PayrollStatus::Draft)
        ->and($period->items()->count())->toBe(5);
})->group('phase9', 'payroll');

it('says plainly that the month is drafted when everybody has a salary on record', function () {
    PayrollItem::query()->delete();
    PayrollPeriod::query()->delete();
    // Off the payroll entirely, which is what Part D §21 makes leaving the company.
    $this->manager->employee->forceFill(['status' => 'inactive'])->save();

    $this->actingAs($this->accountant)
        ->post('/payroll')
        ->assertSessionHas('success', fn (string $message) => str_contains($message, 'is drafted with 5 lines'));
})->group('phase9', 'payroll');

it('refuses a second draft for a month that already has one, with a sentence', function () {
    $this->actingAs($this->accountant)
        ->from('/payroll')
        ->post('/payroll')
        ->assertRedirect('/payroll')
        ->assertSessionHas('error', fn (string $message) => str_contains($message, 'already has a payroll period'));

    expect(PayrollPeriod::count())->toBe(1);
})->group('phase9', 'payroll');

it('sends every line with the day counts its leave impact was worked out from', function () {
    $props = payrollEndpointsProps(
        $this->actingAs($this->admin)->get("/payroll/{$this->period->id}")->assertOk()
    );

    expect($props['items'])->toHaveCount(5);

    $breakdown = $props['leave'][(string) $this->item->id];

    // The two numbers `PayrollService::leaveImpactCents()` divides with, so the person reading
    // the deduction can check the arithmetic instead of raising a ticket about it.
    expect(array_keys($breakdown))->toEqualCanonicalizing(['unpaid_days', 'payable_days'])
        ->and($breakdown['unpaid_days'])->toBe(0)
        ->and($breakdown['payable_days'])->toBe(22);
})->group('phase9', 'payroll');

it('leaves admin_notes ABSENT from the accountant payload and sends it to an admin', function () {
    $this->payroll->annotate($this->admin, $this->item, 'Advance to be recovered in October.');

    $forAccountant = payrollEndpointsProps(
        $this->actingAs($this->accountant)->get("/payroll/{$this->period->id}")->assertOk()
    );

    $line = collect($forAccountant['items'])->firstWhere('id', $this->item->id);

    // array_key_exists, not `=== null`: `=== null` passes for the right answer and the wrong
    // one alike, and the wrong one is the bug this rule exists to prevent (decision 9-10).
    expect(array_key_exists('admin_notes', $line))->toBeFalse();

    $forAdmin = payrollEndpointsProps(
        $this->actingAs($this->admin)->get("/payroll/{$this->period->id}")->assertOk()
    );

    $adminLine = collect($forAdmin['items'])->firstWhere('id', $this->item->id);

    expect(array_key_exists('admin_notes', $adminLine))->toBeTrue()
        ->and($adminLine['admin_notes'])->toBe('Advance to be recovered in October.');
})->group('phase9', 'payroll');

/*
|--------------------------------------------------------------------------
| 3. Writing one line
|--------------------------------------------------------------------------
*/

it('recalculates the net IN THE DATABASE when a figure is adjusted', function () {
    // Yaseen: base 800 + allowance 100. A 250 bonus and a 50 deduction make the net 1,100.
    expect(payrollEndpointsNetInDatabase($this->item->id))->toBe('900.00');

    $this->actingAs($this->accountant)
        ->from("/payroll/{$this->period->id}")
        ->put("/payroll/{$this->period->id}/items/{$this->item->id}", [
            'base_salary' => '800.00',
            'allowance' => '100.00',
            'bonus' => '250.00',
            'deduction' => '50.00',
            'advance' => '0.00',
        ])
        ->assertRedirect("/payroll/{$this->period->id}")
        ->assertSessionHas('success');

    // The DATABASE's value, because `net_salary` is `GENERATED ALWAYS … STORED` — nothing in
    // PHP computes it and nothing in Vue does either (decision 9-3).
    expect(payrollEndpointsNetInDatabase($this->item->id))->toBe('1100.00');
})->group('phase9', 'payroll');

it('refuses a posted net salary or leave impact rather than dropping it in silence', function (string $field) {
    $before = payrollEndpointsNetInDatabase($this->item->id);

    $this->actingAs($this->accountant)
        ->from("/payroll/{$this->period->id}")
        ->put("/payroll/{$this->period->id}/items/{$this->item->id}", [
            'bonus' => '10.00',
            $field => '99999.00',
        ])
        ->assertSessionHasErrors($field);

    // Nothing was written at all — not the prohibited field, and not the legitimate one
    // beside it.
    expect(payrollEndpointsNetInDatabase($this->item->id))->toBe($before);
})->with(['net_salary', 'leave_impact'])->group('phase9', 'payroll');

it('refuses admin_notes from the accountant and writes nothing at all', function () {
    $before = payrollEndpointsNetInDatabase($this->item->id);

    $this->actingAs($this->accountant)
        ->put("/payroll/{$this->period->id}/items/{$this->item->id}", [
            'bonus' => '500.00',
            'admin_notes' => 'The Accountant should not be able to write this.',
        ])
        ->assertForbidden();

    // The check runs BEFORE anything is written, so the figures beside the refused note are
    // untouched too.
    expect(payrollEndpointsNetInDatabase($this->item->id))->toBe($before)
        ->and($this->item->fresh()->admin_notes)->toBeNull();
})->group('phase9', 'payroll');

it('lets an admin write the personal note', function () {
    $this->actingAs($this->admin)
        ->from("/payroll/{$this->period->id}")
        ->put("/payroll/{$this->period->id}/items/{$this->item->id}", [
            'admin_notes' => 'Held back pending the client’s payment.',
        ])
        ->assertSessionHas('success');

    expect($this->item->fresh()->admin_notes)->toBe('Held back pending the client’s payment.');
})->group('phase9', 'payroll');

it('answers 404 for an item that is not on the period in the URL', function () {
    $october = $this->payroll->createDraft($this->admin, '2026-10-01');
    $strayItem = $october->items()->firstOrFail();

    $this->actingAs($this->admin)
        ->put("/payroll/{$this->period->id}/items/{$strayItem->id}", ['bonus' => '10.00'])
        ->assertNotFound();
})->group('phase9', 'payroll');

it('refuses an edit once the month is locked, with a sentence rather than a 403', function () {
    $period = payrollEndpointsWalkTo(PayrollStatus::Locked);

    $this->actingAs($this->admin)
        ->from("/payroll/{$period->id}")
        ->put("/payroll/{$period->id}/items/{$this->item->id}", ['bonus' => '10.00'])
        ->assertRedirect("/payroll/{$period->id}")
        ->assertSessionHas('error', fn (string $message) => str_contains($message, 'locked'));
})->group('phase9', 'payroll');

/*
|--------------------------------------------------------------------------
| 4. The state machine
|--------------------------------------------------------------------------
*/

it('walks September through every state, each verb by the role that holds it', function () {
    $id = $this->period->id;

    $this->actingAs($this->accountant)->from("/payroll/{$id}")->post("/payroll/{$id}/calculate")
        ->assertSessionHas('success');
    expect($this->period->fresh()->status)->toBe(PayrollStatus::Calculated);

    $this->actingAs($this->admin)->from("/payroll/{$id}")->post("/payroll/{$id}/review")
        ->assertSessionHas('success');
    expect($this->period->fresh()->status)->toBe(PayrollStatus::Reviewed);

    $this->actingAs($this->admin)->from("/payroll/{$id}")->post("/payroll/{$id}/approve")
        ->assertSessionHas('success');
    expect($this->period->fresh()->status)->toBe(PayrollStatus::Approved);

    $this->actingAs($this->admin)->from("/payroll/{$id}")->post("/payroll/{$id}/lock")
        ->assertSessionHas('success');
    expect($this->period->fresh()->status)->toBe(PayrollStatus::Locked);

    $this->actingAs($this->admin)->from("/payroll/{$id}")->post("/payroll/{$id}/paid")
        ->assertSessionHas('success');
    expect($this->period->fresh()->status)->toBe(PayrollStatus::Paid);

    // Part C §4 names `payroll approved`. The audit row carries what was approved, not just
    // that something was.
    $approval = AuditLog::where('event', AuditEvent::PayrollApproved->value)->latest('id')->firstOrFail();

    expect($approval->new_value['status'])->toBe('approved')
        ->and($approval->new_value['items'])->toBe(5);
})->group('phase9', 'payroll');

it('lets the accountant calculate again on a calculated month, with the same answer', function () {
    $period = payrollEndpointsWalkTo(PayrollStatus::Calculated);

    $before = payrollEndpointsNetInDatabase($this->item->id);

    $this->actingAs($this->accountant)
        ->from("/payroll/{$period->id}")
        ->post("/payroll/{$period->id}/calculate")
        ->assertSessionHas('success', fn (string $message) => str_contains($message, 'same answer'));

    expect($period->fresh()->status)->toBe(PayrollStatus::Calculated)
        ->and(payrollEndpointsNetInDatabase($this->item->id))->toBe($before);
})->group('phase9', 'payroll');

it('refuses review, approve, lock, reverse and paid to the accountant', function () {
    // At the status each verb would otherwise be legal from, so the refusal can only be about
    // the person — the Accountant holds `payroll.draft` and not `payroll.approve`.
    $states = [
        'review' => PayrollStatus::Calculated,
        'approve' => PayrollStatus::Reviewed,
        'lock' => PayrollStatus::Approved,
        'reverse-lock' => PayrollStatus::Locked,
        'paid' => PayrollStatus::Locked,
    ];

    foreach ($states as $verb => $status) {
        $period = payrollEndpointsWalkTo($status);

        $this->actingAs($this->accountant)
            ->post("/payroll/{$period->id}/{$verb}", ['reason' => 'The accountant should not reach this.'])
            ->assertForbidden();

        expect($period->fresh()->status)->toBe($status);

        // Back to the seeded draft for the next verb.
        $period->forceFill(['status' => PayrollStatus::Draft->value, 'locked_at' => null])->saveQuietly();
    }
})->group('phase9', 'payroll');

it('refuses every admin-only verb to the accountant in one sweep at the seeded draft too', function () {
    foreach (payrollEndpointsAdminOnlyRoutes($this->period->id) as [$method, $path]) {
        $this->actingAs($this->accountant)
            ->{$method}($path, ['reason' => 'Still not allowed.'])
            ->assertForbidden();
    }

    expect($this->period->fresh()->status)->toBe(PayrollStatus::Draft);
})->group('phase9', 'payroll');

it('refuses a move the month is not in a state for, with a sentence naming the state', function () {
    $id = $this->period->id;

    // Approve on a DRAFT: the Admin is entitled to press it, so this is a state problem and a
    // flash sentence, never a 403 and never a silent no-op.
    $this->actingAs($this->admin)
        ->from("/payroll/{$id}")
        ->post("/payroll/{$id}/approve")
        ->assertRedirect("/payroll/{$id}")
        ->assertSessionHas('error', fn (string $message) => str_contains($message, 'Draft')
            && str_contains($message, 'Calculated'));

    expect($this->period->fresh()->status)->toBe(PayrollStatus::Draft);
})->group('phase9', 'payroll');

it('refuses a lock reversal on a month that is not locked, with a sentence', function () {
    $period = payrollEndpointsWalkTo(PayrollStatus::Approved);

    $this->actingAs($this->admin)
        ->from("/payroll/{$period->id}")
        ->post("/payroll/{$period->id}/reverse-lock", ['reason' => 'There is no lock here.'])
        ->assertSessionHas('error', fn (string $message) => str_contains($message, 'not locked'));

    expect($period->fresh()->status)->toBe(PayrollStatus::Approved);
})->group('phase9', 'payroll');

it('refuses every transition once the month is PAID', function (string $verb) {
    $period = payrollEndpointsWalkTo(PayrollStatus::Paid);

    $this->actingAs($this->admin)
        ->from("/payroll/{$period->id}")
        ->post("/payroll/{$period->id}/{$verb}", ['reason' => 'A paid month is final.'])
        ->assertRedirect("/payroll/{$period->id}")
        ->assertSessionHas('error');

    // Paid is terminal: `PayrollStatus::TRANSITIONS['paid']` is empty and nothing moves it.
    expect($period->fresh()->status)->toBe(PayrollStatus::Paid);
})->with(['calculate', 'review', 'approve', 'lock', 'reverse-lock', 'paid'])->group('phase9', 'payroll');

/*
|--------------------------------------------------------------------------
| 5. Reversing a lock
|--------------------------------------------------------------------------
*/

it('refuses a lock reversal with no reason', function () {
    $period = payrollEndpointsWalkTo(PayrollStatus::Locked);

    $this->actingAs($this->admin)
        ->from("/payroll/{$period->id}")
        ->post("/payroll/{$period->id}/reverse-lock", [])
        ->assertSessionHasErrors('reason');

    expect($period->fresh()->status)->toBe(PayrollStatus::Locked);
})->group('phase9', 'payroll');

it('answers 422 on the reason field to a caller that asked for JSON', function () {
    $period = payrollEndpointsWalkTo(PayrollStatus::Locked);

    $this->actingAs($this->admin)
        ->postJson("/payroll/{$period->id}/reverse-lock", ['reason' => '   '])
        ->assertStatus(422)
        ->assertJsonValidationErrors('reason');

    expect($period->fresh()->status)->toBe(PayrollStatus::Locked);
})->group('phase9', 'payroll');

it('reverses the lock with a reason and writes the reason to the audit log', function () {
    $period = payrollEndpointsWalkTo(PayrollStatus::Locked);

    $this->actingAs($this->admin)
        ->from("/payroll/{$period->id}")
        ->post("/payroll/{$period->id}/reverse-lock", ['reason' => 'September invoice arrived after the close.'])
        ->assertSessionHas('success');

    $period->refresh();

    expect($period->status)->toBe(PayrollStatus::Approved)
        ->and($period->locked_at)->toBeNull()
        ->and($period->lock_reversal_reason)->toBe('September invoice arrived after the close.')
        ->and($period->lock_reversed_by)->toBe($this->admin->id);

    // Part C §4's "payroll lock reversed". The row is in the one table hq_app can neither
    // UPDATE nor DELETE, and the reason is in it.
    $audit = AuditLog::where('event', AuditEvent::PayrollLockReversed->value)->latest('id')->firstOrFail();

    expect($audit->new_value['reason'])->toBe('September invoice arrived after the close.')
        ->and((int) $audit->actor_id)->toBe($this->admin->id);
})->group('phase9', 'payroll');

/*
|--------------------------------------------------------------------------
| 6. Where the two halves of Phase 9 meet
|--------------------------------------------------------------------------
*/

it('blocks a September finance write once the month is locked, and unblocks it when the lock is reversed', function () {
    // Open: the seeded draft closes nothing, so the Accountant can file a September receipt.
    $this->finance->recordIncome($this->accountant, payrollEndpointsIncomeBody());

    $id = $this->period->id;
    $accountant = $this->accountant;
    $finance = $this->finance;

    payrollEndpointsWalkTo(PayrollStatus::Approved);

    // Approved does NOT close the month — Part D §13 names `locked` and `paid` and exactly
    // those two (decision 9-13).
    $finance->recordIncome($accountant, payrollEndpointsIncomeBody());

    $this->actingAs($this->admin)->from("/payroll/{$id}")->post("/payroll/{$id}/lock")
        ->assertSessionHas('success');

    expect(fn () => $finance->recordIncome($accountant, payrollEndpointsIncomeBody()))
        ->toThrow(FinanceStateException::class);

    $this->actingAs($this->admin)
        ->from("/payroll/{$id}")
        ->post("/payroll/{$id}/reverse-lock", ['reason' => 'A September invoice arrived late.'])
        ->assertSessionHas('success');

    // Open again, through the same service the Accountant actually uses.
    $income = $finance->recordIncome($accountant, payrollEndpointsIncomeBody());

    expect($income->date->toDateString())->toBe(PAYROLL_ENDPOINTS_MONTH_DAY);
})->group('phase9', 'payroll');

it('pays an approved month in one press, closing it on the way (polish 002)', function () {
    $period = payrollEndpointsWalkTo(PayrollStatus::Approved);

    $this->actingAs($this->admin)->get("/payroll/{$period->id}")
        ->assertInertia(fn ($page) => $page->where('period.available_transitions', ['locked', 'paid']));

    $this->actingAs($this->admin)->from("/payroll/{$period->id}")->post("/payroll/{$period->id}/paid")
        ->assertSessionHas('success');

    $fresh = $period->fresh();
    expect($fresh->status)->toBe(PayrollStatus::Paid)
        ->and($fresh->locked_at)->not->toBeNull();
})->group('phase9', 'payroll');

it('still refuses paid to the accountant on an approved month (polish 002)', function () {
    $period = payrollEndpointsWalkTo(PayrollStatus::Approved);

    $this->actingAs($this->accountant)->post("/payroll/{$period->id}/paid")->assertForbidden();

    expect($period->fresh()->status)->toBe(PayrollStatus::Approved);
})->group('phase9', 'payroll');
