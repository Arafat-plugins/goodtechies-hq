<?php

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\FinanceCategory;
use App\Models\User;
use App\Services\FinanceService;
use App\Support\AuditEvent;
use App\Support\FinanceCategoryKind;
use App\Support\RoleName;
use Inertia\Testing\AssertableInertia as Assert;

/*
|--------------------------------------------------------------------------
| The expense ledger — the mirror of the income one, minus the project
|--------------------------------------------------------------------------
|
| Master prompt Part D §13 and Phase 8. The same six shared routes behind
| `can:finance.view`, the same page-per-three-GETs shape, the same month in
| the URL, and the same totals read from `FinanceService::monthlyRollup()`
| rather than summed in Vue. `IncomeEndpointsTest` carries the fuller notes.
|
| The two things asserted here that are NOT true of income:
|
|   - **An expense has no project link at all** (Part D §20; decision 8-17).
|     There is no `projects` prop, no `project` key on a row, and a
|     `project_id` in the body is ignored rather than stored — asserted,
|     because "Project Cost" being a category rather than a reference is
|     exactly the sort of thing a later phase would quietly reverse.
|   - Part C §4 names *"expense created · expense edited"* explicitly, so
|     the two audit rows here are the ones the plan asks for by name.
|
| Constants and helpers are prefixed FINANCE_LEDGER_ / financeLedger*,
| because Pest declares both globally across the whole suite (AGENTS.md).
|
*/

const FINANCE_LEDGER_EXPENSE_MONTH = '2026-09';

/** @return int a seeded expense category's id, by name */
function financeLedgerExpenseCategory(string $name): int
{
    return (int) FinanceCategory::query()
        ->ofKind(FinanceCategoryKind::Expense)
        ->where('name', $name)
        ->firstOrFail()
        ->getKey();
}

/** @return AuditLog the newest audit row for this event */
function financeLedgerExpenseAudit(AuditEvent $event): AuditLog
{
    return AuditLog::where('event', $event->value)->orderByDesc('id')->firstOrFail();
}

/** The six routes of this ledger, as method + path. */
function financeLedgerExpenseRoutes(int $id): array
{
    return [
        ['get', '/finance/expenses'],
        ['get', '/finance/expenses/create'],
        ['post', '/finance/expenses'],
        ['get', "/finance/expenses/{$id}/edit"],
        ['put', "/finance/expenses/{$id}"],
        ['delete', "/finance/expenses/{$id}"],
    ];
}

/** A complete, valid expense body. */
function financeLedgerExpenseBody(array $overrides = []): array
{
    return array_merge([
        'category_id' => financeLedgerExpenseCategory('Office'),
        'amount' => '75.00',
        'date' => '2026-09-22',
        'notes' => 'A test payment out',
    ], $overrides);
}

beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->accountant = User::where('email', 'accountant@goodtechies.test')->firstOrFail();
    $this->employee = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();
    $this->remote = User::where('email', 'tapu@goodtechies.test')->firstOrFail();
    $this->manager = Employee::factory()->forRole(RoleName::MANAGER)->create()->user;

    $this->expense = Expense::query()->orderBy('id')->firstOrFail();
    $this->service = app(FinanceService::class);
});

/*
|--------------------------------------------------------------------------
| Who may reach it
|--------------------------------------------------------------------------
*/

it('renders the expense ledger for the admin and for the accountant', function (string $role) {
    $this->actingAs($this->{$role})
        ->get('/finance/expenses?month='.FINANCE_LEDGER_EXPENSE_MONTH)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Shared/Finance/Expenses')
            ->where('month.value', FINANCE_LEDGER_EXPENSE_MONTH)
            ->where('month.label', 'September 2026')
            // The seven seeded September payments out.
            ->has('records', 7)
            ->where('permissions.can_create', true)
            ->where('form', null)
            // There is no project picker on this side, and no key for one.
            ->missing('projects'));
})->with(['admin', 'accountant'])->group('phase8', 'finance');

it('opens the expense form on its own address, for a new record and for an existing one', function (string $role) {
    $this->actingAs($this->{$role})
        ->get('/finance/expenses/create')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('form.mode', 'create')->where('form.record', null));

    $this->actingAs($this->{$role})
        ->get("/finance/expenses/{$this->expense->id}/edit")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('form.mode', 'edit')
            ->where('form.record.id', $this->expense->id)
            ->where('month.value', FINANCE_LEDGER_EXPENSE_MONTH));
})->with(['admin', 'accountant'])->group('phase8', 'finance');

it('refuses every expense route to a role holding no finance key', function (string $role) {
    foreach (financeLedgerExpenseRoutes($this->expense->id) as [$method, $path]) {
        $this->actingAs($this->{$role})->{$method}($path)->assertForbidden();
    }
})->with(['employee', 'remote', 'manager'])->group('phase8', 'finance');

it('sends a guest to log in rather than refusing them on the expense routes', function () {
    foreach (financeLedgerExpenseRoutes($this->expense->id) as [$method, $path]) {
        $this->{$method}($path)->assertRedirect('/login');
    }
})->group('phase8', 'finance');

/*
|--------------------------------------------------------------------------
| Writing — Part C §4 names both of these events
|--------------------------------------------------------------------------
*/

it('records an expense and writes the expense.created audit row', function () {
    $before = Expense::count();

    $this->actingAs($this->accountant)
        ->post('/finance/expenses', financeLedgerExpenseBody(['amount' => '210.50']))
        ->assertRedirect('/finance/expenses?month=2026-09')
        ->assertSessionHas('success');

    expect(Expense::count())->toBe($before + 1);

    $audit = financeLedgerExpenseAudit(AuditEvent::ExpenseCreated);

    expect($audit->actor_id)->toBe($this->accountant->getKey())
        ->and($audit->old_value)->toBeNull()
        ->and($audit->new_value['amount'])->toBe('210.50')
        ->and($audit->new_value['category_name'])->toBe('Office')
        ->and($audit->new_value['category_kind'])->toBe('expense');
})->group('phase8', 'finance');

it('edits an expense and the expense.edited audit row carries old AND new', function () {
    $was = (string) $this->expense->amount;

    $this->actingAs($this->admin)
        ->put("/finance/expenses/{$this->expense->id}", financeLedgerExpenseBody([
            'category_id' => $this->expense->category_id,
            'amount' => '333.00',
            'date' => $this->expense->date->toDateString(),
            'notes' => 'Corrected',
        ]))
        ->assertRedirect('/finance/expenses?month=2026-09')
        ->assertSessionHas('success');

    $audit = financeLedgerExpenseAudit(AuditEvent::ExpenseEdited);

    expect($audit->old_value['amount'])->toBe($was)
        ->and($audit->new_value['amount'])->toBe('333.00')
        ->and($this->expense->fresh()->amount)->toBe('333.00');
})->group('phase8', 'finance');

it('deletes an expense for good, and the audit row is the whole of what is left of it', function () {
    $id = $this->expense->getKey();
    $amount = (string) $this->expense->amount;

    $this->actingAs($this->accountant)
        ->delete("/finance/expenses/{$id}")
        ->assertRedirect('/finance/expenses?month=2026-09')
        ->assertSessionHas('success');

    expect(Expense::find($id))->toBeNull();

    $audit = financeLedgerExpenseAudit(AuditEvent::FinanceRecordDeleted);

    expect($audit->target_id)->toBe($id)
        ->and($audit->new_value)->toBeNull()
        ->and($audit->old_value['amount'])->toBe($amount)
        ->and($audit->old_value)->toHaveKeys([
            'id', 'category_id', 'category_name', 'category_kind',
            'amount', 'date', 'notes', 'recorded_by', 'created_at', 'updated_at',
        ]);
})->group('phase8', 'finance');

/*
|--------------------------------------------------------------------------
| The refusals
|--------------------------------------------------------------------------
*/

it('refuses an income category on an expense record with a 422, not a foreign-key error', function () {
    $seo = (int) FinanceCategory::query()
        ->ofKind(FinanceCategoryKind::Income)
        ->where('name', 'SEO')
        ->firstOrFail()
        ->getKey();

    $this->actingAs($this->accountant)
        ->post('/finance/expenses', financeLedgerExpenseBody(['category_id' => $seo]))
        ->assertSessionHasErrors('category_id');

    expect(Expense::where('category_id', $seo)->exists())->toBeFalse();
})->group('phase8', 'finance');

it('refuses a zero and a negative expense amount', function (string $amount) {
    $this->actingAs($this->accountant)
        ->post('/finance/expenses', financeLedgerExpenseBody(['amount' => $amount]))
        ->assertSessionHasErrors('amount');
})->with(['0', '0.00', '-1', '-99.99'])->group('phase8', 'finance');

it('ignores a project_id on an expense, because Part D §20 gives an expense none', function () {
    $this->actingAs($this->accountant)
        ->post('/finance/expenses', financeLedgerExpenseBody() + ['project_id' => 1])
        ->assertSessionHasNoErrors();

    $recorded = Expense::query()->latest('id')->firstOrFail();

    // Not stored, and not even a column: "Project Cost" is a category, not a reference. A row
    // that quietly grew a project link would be a schema change nobody decided on (8-17).
    expect($recorded->getAttributes())->not->toHaveKey('project_id');
})->group('phase8', 'finance');

/*
|--------------------------------------------------------------------------
| The month, and the totals
|--------------------------------------------------------------------------
*/

it('shows only the expenses dated inside the month in the URL', function () {
    $august = Expense::factory()
        ->recordedBy($this->accountant)
        ->inCategory('Hosting')
        ->of('45.00')
        ->on('2026-08-14')
        ->create();

    $september = $this->actingAs($this->accountant)
        ->get('/finance/expenses?month=2026-09')
        ->assertOk()
        ->viewData('page')['props']['records'];

    expect(collect($september)->pluck('id'))->not->toContain($august->getKey())
        ->and($september)->toHaveCount(7);

    $previous = $this->actingAs($this->accountant)
        ->get('/finance/expenses?month=2026-08')
        ->assertOk()
        ->viewData('page')['props']['records'];

    expect(collect($previous)->pluck('id')->all())->toBe([$august->getKey()]);
})->group('phase8', 'finance');

it('prints the expense totals FinanceService::monthlyRollup computes', function () {
    $rollup = $this->service->monthlyRollup($this->accountant, 2026, 9);

    $props = $this->actingAs($this->accountant)
        ->get('/finance/expenses?month='.FINANCE_LEDGER_EXPENSE_MONTH)
        ->assertOk()
        ->viewData('page')['props'];

    // Against the service, not against a literal — there is one sum in this application and the
    // ledger reads it (decision 8-10).
    expect($props['totals'])->toBe($rollup['expenses'])
        ->and($props['totals']['total'])->toBe('2110.00');
})->group('phase8', 'finance');

it('sends no project on an expense row, at any depth', function () {
    $records = $this->actingAs($this->accountant)
        ->get('/finance/expenses?month='.FINANCE_LEDGER_EXPENSE_MONTH)
        ->assertOk()
        ->viewData('page')['props']['records'];

    expect($records)->not->toBeEmpty();

    foreach ($records as $row) {
        expect(array_keys($row))
            ->toEqualCanonicalizing(['id', 'date', 'amount', 'category', 'notes', 'permissions']);
    }
})->group('phase8', 'finance');
