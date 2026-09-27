<?php

use App\Models\AuditLog;
use App\Models\PayrollItem;
use App\Models\PayrollPeriod;
use App\Models\User;
use App\Services\PayrollService;
use App\Support\AuditEvent;
use App\Support\PayrollStatus;

/*
|--------------------------------------------------------------------------
| My Payslip — the three endpoints, and the rule underneath all three
|--------------------------------------------------------------------------
|
| Master prompt Part B §3 rule 1 and Phase 9's own security list:
|
|   "Employee requesting any other employee's payroll item — list endpoints
|    OMIT it, a direct id returns 404, and the attempt is AUDIT-LOGGED."
|
| tests/Feature/Payroll/PayrollPrivacyTest.php proves that of the SERVICE.
| This file proves it of the ENDPOINTS, over HTTP, once per endpoint — and
| the PDF gets its own copy of the 404 and its own copy of the audit-row
| assertion, because a download built without the scope is the likeliest
| hole in an otherwise airtight rule and "it works on the page" has never
| proved anything about the file beside it.
|
| Plus the two facts the screens rest on:
|
|   - `admin_notes` is ABSENT from a payslip payload, asserted with
|     array_key_exists and never with `=== null` — including for the very
|     employee the note is about (decision 9-10);
|   - the ACCOUNTANT reaches their own payslip in the ACCOUNTANT shell,
|     which is Part C §1's cell and the note printed under its matrix.
|
| Every constant and helper here is prefixed PAYSLIP_, because Pest declares
| a test file's constants and functions GLOBALLY across the whole suite
| (AGENTS.md) and a duplicate is a PHP warning, not an error.
|
*/

/** The month `PayrollSeeder` drafts — the one every payroll test shares. */
const PAYSLIP_MONTH = '2026-09-01';

/** How that month reads in a title, a filename and a sentence. */
const PAYSLIP_MONTH_FILE = '2026-09';

/**
 * Walk the seeded September draft all the way to Paid, through the real machine.
 *
 * `PayrollPeriod`'s guard throws if `status` is written outside `applyTransition()`, so there
 * is no shortcut here and there should not be: a "released" payslip in this application means a
 * period that actually went Draft → Calculated → Reviewed → Approved → Locked → Paid.
 */
function payslipPaySeptember(): PayrollPeriod
{
    $admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $accountant = User::where('email', 'accountant@goodtechies.test')->firstOrFail();
    $payroll = app(PayrollService::class);

    $period = PayrollPeriod::query()->forMonth(PAYSLIP_MONTH)->firstOrFail();

    $payroll->calculate($accountant, $period);
    $payroll->review($admin, $period);
    $payroll->approve($admin, $period);
    $payroll->lock($admin, $period);
    $payroll->markPaid($admin, $period);

    return $period->refresh();
}

/** This user's line in the seeded September period. */
function payslipItemFor(User $user): PayrollItem
{
    return PayrollItem::query()
        ->where('employee_id', $user->employee->getKey())
        ->whereHas('period', fn ($query) => $query->forMonth(PAYSLIP_MONTH))
        ->firstOrFail();
}

beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->yaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();
    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();
    $this->accountant = User::where('email', 'accountant@goodtechies.test')->firstOrFail();

    $this->yaseensItem = payslipItemFor($this->yaseen);
    $this->tapusItem = payslipItemFor($this->tapu);
});

/*
|--------------------------------------------------------------------------
| 1. The listing omits everybody else
|--------------------------------------------------------------------------
*/

it('lists only the signed-in employee own payroll items', function () {
    $response = $this->actingAs($this->yaseen)->get('/payslip')->assertOk();

    $items = $response->inertiaProps()['items'];

    // The seeded September has a line for every active employee — five of them — so a screen
    // that forgot to scope would show five rows here. One is the whole assertion.
    expect($items)->toHaveCount(1)
        ->and((int) $items[0]['id'])->toBe((int) $this->yaseensItem->getKey())
        ->and((int) $items[0]['employee']['id'])->toBe((int) $this->yaseen->employee->getKey());

    // And nobody else's id is reachable from this payload at any depth.
    expect(json_encode($items))->not->toContain('"id":'.$this->tapusItem->getKey().',');
})->group('phase9');

it('gives an employee with no payroll yet an empty list rather than an error', function () {
    // A new joiner has no payslips and that is not an error — an empty list, not a 500 and not
    // a 403. Deleting the one seeded line is the same state their first week is in.
    $this->yaseensItem->delete();

    $response = $this->actingAs($this->yaseen)->get('/payslip')->assertOk();

    expect($response->inertiaProps()['items'])->toBe([]);
})->group('phase9');

/*
|--------------------------------------------------------------------------
| 2. Somebody else's payslip is 404 — and the attempt is logged
|--------------------------------------------------------------------------
*/

it('answers 404 for another employee payslip, never 403', function () {
    $this->actingAs($this->yaseen)
        ->get('/payslip/'.$this->tapusItem->getKey())
        ->assertNotFound();
})->group('phase9');

it('audit-logs the attempt on another employee payslip', function () {
    expect(AuditLog::where('event', AuditEvent::RestrictedAccessAttempt->value)->count())->toBe(0);

    $this->actingAs($this->yaseen)
        ->get('/payslip/'.$this->tapusItem->getKey())
        ->assertNotFound();

    $row = AuditLog::where('event', AuditEvent::RestrictedAccessAttempt->value)->sole();

    expect((int) $row->actor_id)->toBe((int) $this->yaseen->getKey())
        ->and($row->target_id)->toBe((int) $this->tapusItem->getKey())
        ->and($row->new_value['resource'])->toBe('payroll_item')
        ->and($row->new_value['employee_id'])->toBe((int) $this->tapu->employee->getKey());

    // The row records WHOSE line was asked for, never what it said.
    expect($row->new_value)->not->toHaveKey('net_salary')
        ->and($row->new_value)->not->toHaveKey('base_salary');
})->group('phase9');

/*
|--------------------------------------------------------------------------
| 3. The same, for the PDF — the endpoint most likely to forget the scope
|--------------------------------------------------------------------------
*/

it('answers 404 for another employee payslip PDF, never 403', function () {
    $this->actingAs($this->yaseen)
        ->get('/payslip/'.$this->tapusItem->getKey().'/pdf')
        ->assertNotFound();
})->group('phase9');

it('audit-logs the attempt on another employee payslip PDF', function () {
    expect(AuditLog::where('event', AuditEvent::RestrictedAccessAttempt->value)->count())->toBe(0);

    $this->actingAs($this->yaseen)
        ->get('/payslip/'.$this->tapusItem->getKey().'/pdf')
        ->assertNotFound();

    $row = AuditLog::where('event', AuditEvent::RestrictedAccessAttempt->value)->sole();

    expect((int) $row->actor_id)->toBe((int) $this->yaseen->getKey())
        ->and($row->target_id)->toBe((int) $this->tapusItem->getKey())
        ->and($row->new_value['resource'])->toBe('payroll_item');
})->group('phase9');

it('does not log an attempt on an id that matches nothing', function () {
    $this->actingAs($this->yaseen)->get('/payslip/999999')->assertNotFound();
    $this->actingAs($this->yaseen)->get('/payslip/999999/pdf')->assertNotFound();

    // A stale bookmark or a typo is not an attempt on anybody's salary; logging those would
    // bury the rows Part C §3 actually asks for.
    expect(AuditLog::where('event', AuditEvent::RestrictedAccessAttempt->value)->count())->toBe(0);
})->group('phase9');

/*
|--------------------------------------------------------------------------
| 4. admin_notes is ABSENT — including for the employee it is about
|--------------------------------------------------------------------------
*/

it('leaves admin_notes out of a payslip payload entirely', function () {
    // A note written about Yaseen, by an Admin, on Yaseen's own line.
    $this->yaseensItem->forceFill(['admin_notes' => 'Advance to be recovered next month.'])->save();

    $show = $this->actingAs($this->yaseen)
        ->get('/payslip/'.$this->yaseensItem->getKey())
        ->assertOk();

    // array_key_exists, never `=== null`: `=== null` passes for the right answer AND for the
    // wrong one (Part C — the key is absent, not blanked).
    expect(array_key_exists('admin_notes', $show->inertiaProps()['payslip']))->toBeFalse();

    $index = $this->actingAs($this->yaseen)->get('/payslip')->assertOk();

    expect(array_key_exists('admin_notes', $index->inertiaProps()['items'][0]))->toBeFalse();

    // And the sentence itself never reaches the wire, at any depth.
    expect(json_encode($show->inertiaProps()))->not->toContain('Advance to be recovered');
})->group('phase9');

/*
|--------------------------------------------------------------------------
| 5. The Accountant, in the Accountant shell
|--------------------------------------------------------------------------
*/

it('gives the Accountant their own payslip in the Accountant shell', function () {
    $accountantsItem = payslipItemFor($this->accountant);

    $response = $this->actingAs($this->accountant)
        ->get('/payslip/'.$accountantsItem->getKey())
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Shared/Payslip/Show')
            // The shell is picked from this value by `defineOptions({ layout })`, so asserting
            // it is asserting that the Accountant does not land in the Admin chrome.
            ->where('auth.user.surface', 'accountant')
            ->where('payslip.employee.id', (int) $this->accountant->employee->getKey()));

    // …and the listing is theirs alone, holding `payroll.view_others` notwithstanding: My
    // Payslip is `itemsFor()`, which is self-scope, not the "view others" query.
    $list = $this->actingAs($this->accountant)->get('/payslip')->assertOk();

    expect($list->inertiaProps()['items'])->toHaveCount(1);

    $response->assertOk();
})->group('phase9');

it('keeps My Payslip to one line even for somebody who may see everybody', function (string $who) {
    // The ADMIN and the ACCOUNTANT both hold `payroll.view_others`, so
    // `PayrollItem::scopeVisibleTo()` returns the whole company to them — right for the payroll
    // workbench, wrong for a screen called My Payslip. Phase 9's test list: "the Accountant's
    // My Payslip shows only the Accountant's own item". This is the assertion that broke when
    // the narrowing was missing, and it listed all five people.
    $response = $this->actingAs($this->{$who})->get('/payslip')->assertOk();

    $items = $response->inertiaProps()['items'];

    expect($items)->toHaveCount(1)
        ->and((int) $items[0]['employee']['id'])->toBe((int) $this->{$who}->employee->getKey());
})->with([
    'admin' => ['admin'],
    'accountant' => ['accountant'],
])->group('phase9');

it('gives every role their own payslip page', function () {
    foreach ([$this->admin, $this->yaseen, $this->tapu, $this->accountant] as $user) {
        $this->actingAs($user)
            ->get('/payslip')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Shared/Payslip/Index'));
    }
})->group('phase9');

/*
|--------------------------------------------------------------------------
| 6. The PDF itself
|--------------------------------------------------------------------------
*/

it('returns a PDF named for the person and the month', function () {
    payslipPaySeptember();

    $response = $this->actingAs($this->yaseen)
        ->get('/payslip/'.$this->yaseensItem->getKey().'/pdf')
        ->assertOk();

    expect($response->headers->get('content-type'))->toContain('application/pdf');

    $disposition = (string) $response->headers->get('content-disposition');

    expect($disposition)->toContain('Payslip-')
        // The person …
        ->and($disposition)->toContain('yaseen')
        // … and the month.
        ->and($disposition)->toContain(PAYSLIP_MONTH_FILE);

    // A real PDF, not an HTML page with the wrong header on it.
    expect(substr((string) $response->getContent(), 0, 5))->toBe('%PDF-');
})->group('phase9');

it('marks an unpaid month provisional in the PDF filename', function () {
    // September is seeded as a DRAFT, so this is the pre-release case.
    $response = $this->actingAs($this->yaseen)
        ->get('/payslip/'.$this->yaseensItem->getKey().'/pdf')
        ->assertOk();

    expect((string) $response->headers->get('content-disposition'))
        ->toContain('Provisional-payslip-')
        ->toContain(PAYSLIP_MONTH_FILE);
})->group('phase9');

/*
|--------------------------------------------------------------------------
| 7. Released vs provisional, and the leave explanation
|--------------------------------------------------------------------------
*/

it('calls a month a payslip only once it has been paid', function () {
    $before = $this->actingAs($this->yaseen)
        ->get('/payslip/'.$this->yaseensItem->getKey())
        ->assertOk()
        ->inertiaProps();

    expect($before['payslip']['release']['released'])->toBeFalse()
        ->and($before['payslip']['release']['label'])->toBe('Provisional')
        ->and($before['payslip']['period']['status'])->toBe(PayrollStatus::Draft->value);

    payslipPaySeptember();

    $after = $this->actingAs($this->yaseen)
        ->get('/payslip/'.$this->yaseensItem->getKey())
        ->assertOk()
        ->inertiaProps();

    expect($after['payslip']['release']['released'])->toBeTrue()
        ->and($after['payslip']['release']['label'])->toBe('Payslip')
        ->and($after['payslip']['period']['status'])->toBe(PayrollStatus::Paid->value);
})->group('phase9');

it('explains the leave impact with the days it was divided from', function () {
    $props = $this->actingAs($this->yaseen)
        ->get('/payslip/'.$this->yaseensItem->getKey())
        ->assertOk()
        ->inertiaProps();

    expect($props['leave'])->toHaveKeys(['unpaid_days', 'payable_days', 'impact', 'has_impact'])
        // The divisor is a real count of this person's working days in the month, never 30.
        ->and($props['leave']['payable_days'])->toBeGreaterThan(0)
        // The money is the STORED figure, exactly as PostgreSQL holds it.
        ->and($props['leave']['impact'])->toBe($this->yaseensItem->fresh()->leave_impact);
})->group('phase9');

/*
|--------------------------------------------------------------------------
| 8. A guest reaches none of it
|--------------------------------------------------------------------------
*/

it('redirects a guest to login from every payslip route', function (string $path) {
    $this->get($path)->assertRedirect('/login');
})->with([
    'index' => ['/payslip'],
    'show' => ['/payslip/1'],
    'pdf' => ['/payslip/1/pdf'],
])->group('phase9');
