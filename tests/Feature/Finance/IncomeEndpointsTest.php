<?php

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\FinanceCategory;
use App\Models\Income;
use App\Models\Project;
use App\Models\User;
use App\Services\FinanceService;
use App\Support\AuditEvent;
use App\Support\FinanceCategoryKind;
use App\Support\RoleName;
use Inertia\Testing\AssertableInertia as Assert;

/*
|--------------------------------------------------------------------------
| The income ledger — six shared routes, one page, one month at a time
|--------------------------------------------------------------------------
|
| Master prompt Part D §13 and Phase 8. What is asserted here:
|
| **Who reaches it.** ADMIN and ACCOUNTANT, because the matrix gives both
| `finance.view` and `finance.manage`; EMPLOYEE, REMOTE_EMPLOYEE and
| MANAGER get 403 on every one of the six, which is the phase's own line
| "Employees/Remote get 403 on every finance route". It is 403 rather than
| 404 deliberately — the feature is refused, not a row of it (IncomePolicy).
|
| **That the screens are shared.** One set of routes, one page component,
| the layout picked from the viewer's surface. The Admin and the Accountant
| get the same payload from the same controller, asserted side by side, so
| "what is September" cannot be answered two ways.
|
| **The three refusals that would otherwise be a 500.** An expense category
| on an income row is a composite-foreign-key violation in the database; a
| zero or a negative amount is a CHECK violation. All three come back 422 on
| the field.
|
| **The audit trail.** Create, edit and delete each write their row, and the
| delete's row is the whole of what is left of the record (decision 8-5).
|
| **The Part C guard, which is the load-bearing test of this slice.** The
| income form's project picker carries exactly `id`, `name`, `domain`, and
| no seeded client or contact name appears anywhere in the page's props —
| for the Admin exactly as for the Accountant.
|
| Constants and helpers are prefixed FINANCE_LEDGER_ / financeLedger*,
| because Pest declares both globally across the whole suite (AGENTS.md).
|
*/

/** The month the seeder fills — Part D §13's own example. */
const FINANCE_LEDGER_MONTH = '2026-09';

/** The three keys a project may have on a finance screen, and no fourth. */
const FINANCE_LEDGER_PROJECT_KEYS = ['id', 'name', 'domain'];

/**
 * Every seeded client name and contact name. Neither may appear anywhere in a finance payload,
 * at any depth, as a key or as a value — Part C, and `AccountantProjectEndpointTest` pins the
 * same list against the finance-only project endpoint.
 */
const FINANCE_LEDGER_CLIENT_NAMES = [
    'Buffalo Modular Homes',
    'Heat Gap Heating & Plumbing',
    'APH St Albans',
    'ABC Ltd',
];

const FINANCE_LEDGER_CONTACT_NAMES = ['Karen Buffalo', 'Dave Heatgap', 'Priya Aph', 'Sam Abc'];

/** Keys that must never travel with a project on a finance screen, at any depth. */
const FINANCE_LEDGER_FORBIDDEN_KEYS = [
    'client',
    'client_id',
    'client_name',
    'contact',
    'contacts',
    'contact_info',
    'internal_notes',
    'employee_notes',
    'description',
    'tasks',
    'tasks_count',
    'members',
    'member_count',
    'price',
    'contract_value',
    'contract_terms',
    'profitability_snapshot',
];

/**
 * A seeded category's id, by side and name.
 *
 * Declared here rather than borrowed from `FinanceServiceTest`: Pest declares a test file's
 * functions globally, but only once that file has been loaded, so a helper from another file is
 * present when the folder runs and absent when this file runs alone. The prefix keeps it from
 * colliding with anything (AGENTS.md).
 */
function financeLedgerCategoryId(FinanceCategoryKind $kind, string $name): int
{
    return (int) FinanceCategory::query()->ofKind($kind)->where('name', $name)->firstOrFail()->getKey();
}

/** @return AuditLog the newest audit row for this event */
function financeLedgerLatestAudit(AuditEvent $event): AuditLog
{
    return AuditLog::where('event', $event->value)->orderByDesc('id')->firstOrFail();
}

/** The six routes of this ledger, as method + path, for the role matrix. */
function financeLedgerIncomeRoutes(int $id): array
{
    return [
        'index' => ['get', '/finance/income'],
        'create' => ['get', '/finance/income/create'],
        'store' => ['post', '/finance/income'],
        'edit' => ['get', "/finance/income/{$id}/edit"],
        'update' => ['put', "/finance/income/{$id}"],
        'destroy' => ['delete', "/finance/income/{$id}"],
    ];
}

/** @return int the id of a seeded income category by name */
function financeLedgerIncomeCategory(string $name): int
{
    return financeLedgerCategoryId(FinanceCategoryKind::Income, $name);
}

/** A complete, valid income body. */
function financeLedgerIncomeBody(array $overrides = []): array
{
    return array_merge([
        'category_id' => financeLedgerIncomeCategory('SEO'),
        'amount' => '400.00',
        'date' => '2026-09-21',
        'notes' => 'A test payment',
        'project_id' => null,
    ], $overrides);
}

/** Every key used anywhere in a payload, however deep. */
function financeLedgerKeysIn(mixed $payload): array
{
    $keys = [];

    $walk = function (mixed $value) use (&$walk, &$keys): void {
        if (! is_array($value)) {
            return;
        }

        foreach ($value as $key => $child) {
            if (is_string($key)) {
                $keys[] = $key;
            }

            $walk($child);
        }
    };

    $walk($payload);

    return $keys;
}

beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->accountant = User::where('email', 'accountant@goodtechies.test')->firstOrFail();
    $this->employee = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();
    $this->remote = User::where('email', 'tapu@goodtechies.test')->firstOrFail();
    // MANAGER is a dormant role seeded onto nobody, so the matrix's manager is a factory user
    // holding the role and none of the finance keys.
    $this->manager = Employee::factory()->forRole(RoleName::MANAGER)->create()->user;

    $this->income = Income::query()->orderBy('id')->firstOrFail();
    $this->service = app(FinanceService::class);
});

/*
|--------------------------------------------------------------------------
| Who may reach it
|--------------------------------------------------------------------------
*/

it('renders the ledger for the admin and for the accountant, from the one shared route', function (string $role) {
    $this->actingAs($this->{$role})
        ->get('/finance/income?month='.FINANCE_LEDGER_MONTH)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Shared/Finance/Income')
            ->where('month.value', FINANCE_LEDGER_MONTH)
            ->where('month.label', 'September 2026')
            ->where('month.previous', '2026-08')
            ->where('month.next', '2026-10')
            // The six seeded September receipts.
            ->has('records', 6)
            ->where('permissions.can_create', true)
            // The form is a route, not a flag: it is shut on the index.
            ->where('form', null)
            ->has('totals.categories')
            ->has('categories'));
})->with(['admin', 'accountant'])->group('phase8', 'finance');

it('opens the form on its own address, for a new record and for an existing one', function (string $role) {
    $this->actingAs($this->{$role})
        ->get('/finance/income/create?month='.FINANCE_LEDGER_MONTH)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Shared/Finance/Income')
            ->where('form.mode', 'create')
            ->where('form.record', null)
            ->has('projects'));

    $this->actingAs($this->{$role})
        ->get("/finance/income/{$this->income->id}/edit")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Shared/Finance/Income')
            ->where('form.mode', 'edit')
            ->where('form.record.id', $this->income->id)
            // The month follows the record, so the row is on the list behind the dialog.
            ->where('month.value', FINANCE_LEDGER_MONTH));
})->with(['admin', 'accountant'])->group('phase8', 'finance');

it('refuses every income route to a role holding no finance key', function (string $role) {
    foreach (financeLedgerIncomeRoutes($this->income->id) as $name => [$method, $path]) {
        $this->actingAs($this->{$role})
            ->{$method}($path)
            ->assertForbidden();
    }
})->with(['employee', 'remote', 'manager'])->group('phase8', 'finance');

it('sends a guest to log in rather than refusing them', function () {
    foreach (financeLedgerIncomeRoutes($this->income->id) as [$method, $path]) {
        $this->{$method}($path)->assertRedirect('/login');
    }
})->group('phase8', 'finance');

/*
|--------------------------------------------------------------------------
| Writing — and the audit row each write leaves
|--------------------------------------------------------------------------
*/

it('records income and writes an audit row carrying the new values', function () {
    $before = Income::count();

    $this->actingAs($this->accountant)
        ->post('/finance/income', financeLedgerIncomeBody(['amount' => '750.00']))
        // Back to the month the record is IN, not the month the form was on.
        ->assertRedirect('/finance/income?month=2026-09')
        ->assertSessionHas('success');

    expect(Income::count())->toBe($before + 1);

    $audit = financeLedgerLatestAudit(AuditEvent::IncomeCreated);

    expect($audit->actor_id)->toBe($this->accountant->getKey())
        ->and($audit->old_value)->toBeNull()
        ->and($audit->new_value['amount'])->toBe('750.00')
        ->and($audit->new_value['date'])->toBe('2026-09-21')
        // The names travel beside the ids: an audit reader is by definition looking at the past.
        ->and($audit->new_value['category_name'])->toBe('SEO')
        ->and($audit->new_value['recorded_by'])->toBe($this->accountant->getKey());
})->group('phase8', 'finance');

it('edits income and the audit row carries old AND new', function () {
    $was = (string) $this->income->amount;

    $this->actingAs($this->admin)
        ->put("/finance/income/{$this->income->id}", financeLedgerIncomeBody([
            'category_id' => $this->income->category_id,
            'amount' => '999.00',
            'date' => $this->income->date->toDateString(),
            'notes' => 'Corrected',
        ]))
        ->assertRedirect('/finance/income?month=2026-09')
        ->assertSessionHas('success');

    $audit = financeLedgerLatestAudit(AuditEvent::IncomeEdited);

    expect($audit->old_value['amount'])->toBe($was)
        ->and($audit->new_value['amount'])->toBe('999.00')
        ->and($audit->new_value['notes'])->toBe('Corrected')
        ->and($this->income->fresh()->amount)->toBe('999.00');
})->group('phase8', 'finance');

it('deletes income for good, and the audit row is the whole of what is left of it', function () {
    $id = $this->income->getKey();
    $amount = (string) $this->income->amount;

    $this->actingAs($this->accountant)
        ->delete("/finance/income/{$id}")
        ->assertRedirect('/finance/income?month=2026-09')
        ->assertSessionHas('success');

    // A HARD delete (decision 8-5). There is no `deleted_at` to find it behind.
    expect(Income::find($id))->toBeNull();

    $audit = financeLedgerLatestAudit(AuditEvent::FinanceRecordDeleted);

    expect($audit->target_id)->toBe($id)
        ->and($audit->new_value)->toBeNull()
        ->and($audit->old_value['id'])->toBe($id)
        ->and($audit->old_value['amount'])->toBe($amount)
        // Everything needed to put the row back, including what the category was called.
        ->and($audit->old_value)->toHaveKeys([
            'category_id', 'category_name', 'category_kind', 'project_id', 'project_name',
            'amount', 'date', 'notes', 'recorded_by', 'created_at', 'updated_at',
        ]);
})->group('phase8', 'finance');

/*
|--------------------------------------------------------------------------
| The three refusals that would otherwise reach the database
|--------------------------------------------------------------------------
*/

it('refuses an expense category on an income record with a 422, not a foreign-key error', function () {
    $payroll = financeLedgerCategoryId(FinanceCategoryKind::Expense, 'Payroll');

    $this->actingAs($this->accountant)
        ->post('/finance/income', financeLedgerIncomeBody(['category_id' => $payroll]))
        // The composite foreign key would answer SQLSTATE[23503] on a form about invoices.
        // `StoreIncomeRequest` scopes its `exists` rule to the side of the ledger instead.
        ->assertSessionHasErrors('category_id');

    expect(Income::where('category_id', $payroll)->exists())->toBeFalse();
})->group('phase8', 'finance');

it('refuses a zero and a negative amount', function (string $amount) {
    $this->actingAs($this->accountant)
        ->post('/finance/income', financeLedgerIncomeBody(['amount' => $amount]))
        ->assertSessionHasErrors('amount');
})->with(['0', '0.00', '-1', '-250.00'])->group('phase8', 'finance');

it('refuses more than two decimal places, because the column would silently round them', function () {
    $this->actingAs($this->accountant)
        ->post('/finance/income', financeLedgerIncomeBody(['amount' => '10.005']))
        ->assertSessionHasErrors('amount');
})->group('phase8', 'finance');

/*
|--------------------------------------------------------------------------
| The month, and the totals
|--------------------------------------------------------------------------
*/

it('shows only the rows dated inside the month in the URL', function () {
    $october = Income::factory()
        ->recordedBy($this->accountant)
        ->inCategory('SEO')
        ->of('123.00')
        ->on('2026-10-04')
        ->create();

    $september = $this->actingAs($this->accountant)
        ->get('/finance/income?month=2026-09')
        ->assertOk()
        ->viewData('page')['props']['records'];

    expect(collect($september)->pluck('id'))->not->toContain($october->getKey())
        ->and($september)->toHaveCount(6);

    $next = $this->actingAs($this->accountant)
        ->get('/finance/income?month=2026-10')
        ->assertOk()
        ->viewData('page')['props']['records'];

    expect(collect($next)->pluck('id')->all())->toBe([$october->getKey()]);
})->group('phase8', 'finance');

it('falls back to this month rather than throwing on an unparseable one', function () {
    $this->actingAs($this->accountant)
        ->get('/finance/income?month=not-a-month')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('month.value', now(config('app.timezone'))->format('Y-m')));
})->group('phase8', 'finance');

it('prints the totals FinanceService::monthlyRollup computes, and does not add up its own', function () {
    $rollup = $this->service->monthlyRollup($this->accountant, 2026, 9);

    $props = $this->actingAs($this->accountant)
        ->get('/finance/income?month='.FINANCE_LEDGER_MONTH)
        ->assertOk()
        ->viewData('page')['props'];

    // Asserted against the SERVICE, not against a literal: the point is that there is one sum
    // in this application and the ledger reads it, not that the ledger happens to agree with a
    // number somebody typed into a test (decision 8-10).
    expect($props['totals'])->toBe($rollup['income'])
        ->and($props['totals']['total'])->toBe('2910.00');
})->group('phase8', 'finance');

it('gives the admin and the accountant the same totals, because it is the same ledger', function () {
    $asAdmin = $this->actingAs($this->admin)
        ->get('/finance/income?month='.FINANCE_LEDGER_MONTH)
        ->assertOk()
        ->viewData('page')['props']['totals'];

    $asAccountant = $this->actingAs($this->accountant)
        ->get('/finance/income?month='.FINANCE_LEDGER_MONTH)
        ->assertOk()
        ->viewData('page')['props']['totals'];

    expect($asAdmin)->toBe($asAccountant);
})->group('phase8', 'finance');

/*
|--------------------------------------------------------------------------
| Part C — the project picker, and what it may not carry
|--------------------------------------------------------------------------
*/

it('sends the project picker exactly id, name and domain — for the admin as well as the accountant', function (string $role) {
    $projects = $this->actingAs($this->{$role})
        ->get('/finance/income/create')
        ->assertOk()
        ->viewData('page')['props']['projects'];

    expect($projects)->not->toBeEmpty();

    foreach ($projects as $project) {
        // The exact key set, not a check for three names: a test that asserts three fields
        // passes the day somebody adds a fourth.
        expect(array_keys($project))->toEqualCanonicalizing(FINANCE_LEDGER_PROJECT_KEYS);
    }

    // This supersedes decision 8-16: the Admin's picker is this payload, not ProjectResource
    // with a client attached. Narrower for the Admin, identical on both surfaces, one key set.
    $keys = financeLedgerKeysIn($projects);

    foreach (FINANCE_LEDGER_FORBIDDEN_KEYS as $forbidden) {
        expect($keys)->not->toContain($forbidden);
    }
})->with(['admin', 'accountant'])->group('phase8', 'finance');

it('names no seeded client and no seeded contact in anything the server derives about a project', function (string $role) {
    // The strict half of the Part C guard: every project block on the page, from the picker and
    // from every row, with no exclusions at all.
    foreach (['/finance/income', '/finance/income/create', "/finance/income/{$this->income->id}/edit"] as $path) {
        $props = $this->actingAs($this->{$role})->get($path)->assertOk()->viewData('page')['props'];

        $projects = array_merge(
            $props['projects'],
            array_values(array_filter(array_column($props['records'], 'project'))),
        );

        expect($projects)->not->toBeEmpty();

        $body = json_encode($projects, JSON_THROW_ON_ERROR);

        foreach (FINANCE_LEDGER_CLIENT_NAMES as $client) {
            expect($body)->not->toContain($client);
        }

        foreach (FINANCE_LEDGER_CONTACT_NAMES as $contact) {
            expect($body)->not->toContain($contact);
        }
    }
})->with(['admin', 'accountant'])->group('phase8', 'finance');

it('names no seeded client and no seeded contact anywhere else in the income page props', function (string $role) {
    // The wide half. The key walk above catches a field somebody NAMED; this catches a client's
    // name arriving as the VALUE of an allowed key — `name` set to the client rather than the
    // project, or a contact landing in a label.
    //
    // **It runs over the WHOLE payload with nothing excluded, and the history is worth keeping.**
    // It first shipped with `notes` stripped out, because `FinanceSeeder` wrote "September
    // maintenance retainer — APH St Albans" into one: free text about a payment the Accountant
    // is entitled to see. The argument for the exclusion was that Part C governs what the
    // SERVER derives, not what a person typed — which is true, and was still the wrong call.
    // Demo data that types a client's name into a note hands the Accountant, through the back
    // door, exactly what AccountantProjectResource spends four keys refusing at the front, and
    // it does it in the one field no key walk can see, because there the leak is the VALUE.
    //
    // So the seeder changed instead and the exclusion went. **A guard with an exception carved
    // into it is a guard that gets widened later**; a finance row says what it was for through
    // its LINKED PROJECT, which is a real relation, policy-checked, carrying a name the
    // Accountant is entitled to. If a human later types a client's name into a note, that is
    // their own act on their own books and this test is not about it — but nothing this
    // application ships will have put it there.
    foreach (['/finance/income', '/finance/income/create', "/finance/income/{$this->income->id}/edit"] as $path) {
        $props = $this->actingAs($this->{$role})->get($path)->assertOk()->viewData('page')['props'];

        $body = json_encode($props, JSON_THROW_ON_ERROR);

        foreach (FINANCE_LEDGER_CLIENT_NAMES as $client) {
            expect($body)->not->toContain($client);
        }

        foreach (FINANCE_LEDGER_CONTACT_NAMES as $contact) {
            expect($body)->not->toContain($contact);
        }
    }
})->with(['admin', 'accountant'])->group('phase8', 'finance');

it('still lets income be linked to a project, which is the point of the picker', function () {
    $project = Project::where('name', 'Buffalo Modular — SEO')->firstOrFail();

    $this->actingAs($this->accountant)
        ->post('/finance/income', financeLedgerIncomeBody(['project_id' => $project->getKey()]))
        ->assertSessionHasNoErrors();

    $recorded = Income::query()->where('project_id', $project->getKey())->latest('id')->firstOrFail();

    expect($recorded->project_id)->toBe($project->getKey());

    // And the row prints the project the same three keys the picker offered.
    $records = $this->actingAs($this->accountant)
        ->get('/finance/income?month='.FINANCE_LEDGER_MONTH)
        ->assertOk()
        ->viewData('page')['props']['records'];

    $row = collect($records)->firstWhere('id', $recorded->getKey());

    expect(array_keys($row['project']))->toEqualCanonicalizing(FINANCE_LEDGER_PROJECT_KEYS);
})->group('phase8', 'finance');

it('carries no audit row when a write is refused', function () {
    $before = AuditLog::count();

    $this->actingAs($this->accountant)
        ->post('/finance/income', financeLedgerIncomeBody(['amount' => '0']))
        ->assertSessionHasErrors('amount');

    expect(AuditLog::count())->toBe($before);
})->group('phase8', 'finance');
