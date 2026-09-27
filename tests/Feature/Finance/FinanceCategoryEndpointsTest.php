<?php

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\FinanceCategory;
use App\Models\Income;
use App\Models\User;
use App\Support\AuditEvent;
use App\Support\FinanceCategoryKind;
use App\Support\RoleName;
use Inertia\Testing\AssertableInertia as Assert;

/*
|--------------------------------------------------------------------------
| The category list — read with one key, written with another
|--------------------------------------------------------------------------
|
| Master prompt Part D §13: "Categories (seeded, **Admin-editable**)", and
| decision 8-12, which spells that as two different keys on one screen:
|
|   | ability                    | key              | who holds it      |
|   | -------------------------- | ---------------- | ----------------- |
|   | index                      | `finance.view`   | ADMIN, ACCOUNTANT |
|   | store / update / destroy   | `settings.manage`| ADMIN             |
|
| The Accountant reading it and not writing it is the whole point of this
| file: they live in the pickers these names fill, and renaming one changes
| how every historic report reads.
|
| Also asserted:
|
|   - **A category in use cannot be deleted, and the refusal is a sentence.**
|     `ON DELETE RESTRICT` is the promise; `FinanceStateException` is what a
|     person reads instead of SQLSTATE[23503] on a settings screen.
|   - **A category cannot change sides**, in use or not — `kind` is not a
|     field the update request accepts.
|   - Every write is audited as `configuration.changed`, Part C §4's own
|     last line.
|
| Constants and helpers are prefixed FINANCE_LEDGER_ / financeLedger*,
| because Pest declares both globally across the whole suite (AGENTS.md).
|
*/

/** The four routes of this screen, as method + path. */
function financeLedgerCategoryRoutes(int $id): array
{
    return [
        ['get', '/finance/categories'],
        ['post', '/finance/categories'],
        ['put', "/finance/categories/{$id}"],
        ['delete', "/finance/categories/{$id}"],
    ];
}

/** The three write routes only. */
function financeLedgerCategoryWriteRoutes(int $id): array
{
    return array_slice(financeLedgerCategoryRoutes($id), 1);
}

function financeLedgerCategoryAudit(): AuditLog
{
    return AuditLog::where('event', AuditEvent::ConfigurationChanged->value)
        ->orderByDesc('id')
        ->firstOrFail();
}

beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->accountant = User::where('email', 'accountant@goodtechies.test')->firstOrFail();
    $this->employee = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();
    $this->remote = User::where('email', 'tapu@goodtechies.test')->firstOrFail();
    $this->manager = Employee::factory()->forRole(RoleName::MANAGER)->create()->user;

    // Seeded and in use: every September income is filed under one of these.
    $this->seo = FinanceCategory::query()
        ->ofKind(FinanceCategoryKind::Income)
        ->where('name', 'SEO')
        ->firstOrFail();

    // Seeded and empty — Part D §13's "Other" on the income side has no rows this month or any.
    $this->unused = FinanceCategory::query()
        ->ofKind(FinanceCategoryKind::Income)
        ->where('name', 'Other')
        ->firstOrFail();
});

/*
|--------------------------------------------------------------------------
| Reading it — both finance roles
|--------------------------------------------------------------------------
*/

it('shows both sides of the list to the admin and to the accountant', function (string $role) {
    $this->actingAs($this->{$role})
        ->get('/finance/categories')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Shared/Finance/Categories')
            // Part D §13's two lists: 4 income, 9 expense.
            ->has('income', 4)
            ->has('expense', 9)
            ->where('income.0.kind', 'income')
            ->where('expense.0.kind', 'expense')
            // The blast radius travels on every row, which is how the screen knows whether to
            // offer a Remove control at all.
            ->has('income.0.usage_count')
            ->has('income.0.in_use'));
})->with(['admin', 'accountant'])->group('phase8', 'finance');

it('tells the screen who may change the list, per record, on the server', function () {
    $admin = $this->actingAs($this->admin)->get('/finance/categories')->assertOk()->viewData('page')['props'];
    $accountant = $this->actingAs($this->accountant)->get('/finance/categories')->assertOk()->viewData('page')['props'];

    expect($admin['permissions']['can_create'])->toBeTrue()
        ->and($admin['income'][0]['permissions']['can_update'])->toBeTrue()
        ->and($admin['income'][0]['permissions']['can_delete'])->toBeTrue();

    // The same list, the same screen, no controls. Decision 8-12: reading is `finance.view`,
    // writing is `settings.manage`, and no role is named in any of it.
    expect($accountant['permissions']['can_create'])->toBeFalse()
        ->and($accountant['income'][0]['permissions']['can_update'])->toBeFalse()
        ->and($accountant['income'][0]['permissions']['can_delete'])->toBeFalse();

    // And it really is the same list.
    expect(array_column($accountant['income'], 'name'))->toBe(array_column($admin['income'], 'name'));
})->group('phase8', 'finance');

it('refuses the whole screen to a role holding no finance key', function (string $role) {
    foreach (financeLedgerCategoryRoutes($this->seo->id) as [$method, $path]) {
        $this->actingAs($this->{$role})->{$method}($path)->assertForbidden();
    }
})->with(['employee', 'remote', 'manager'])->group('phase8', 'finance');

it('sends a guest to log in rather than refusing them on the category routes', function () {
    foreach (financeLedgerCategoryRoutes($this->seo->id) as [$method, $path]) {
        $this->{$method}($path)->assertRedirect('/login');
    }
})->group('phase8', 'finance');

/*
|--------------------------------------------------------------------------
| Writing it — the Admin, and only the Admin
|--------------------------------------------------------------------------
*/

it('refuses every category write to the accountant, who may read the same list', function () {
    foreach (financeLedgerCategoryWriteRoutes($this->unused->id) as [$method, $path]) {
        $this->actingAs($this->accountant)
            ->{$method}($path, ['kind' => 'income', 'name' => 'Consulting'])
            ->assertForbidden();
    }

    // Nothing moved.
    expect(FinanceCategory::query()->ofKind(FinanceCategoryKind::Income)->count())->toBe(4)
        ->and($this->unused->fresh()->name)->toBe('Other');

    // And the read still works, which is the half that makes the finance forms usable.
    $this->actingAs($this->accountant)->get('/finance/categories')->assertOk();
})->group('phase8', 'finance');

it('lets the admin add a category, on the side they asked for', function () {
    $this->actingAs($this->admin)
        ->post('/finance/categories', ['kind' => 'expense', 'name' => 'Travel'])
        ->assertRedirect('/finance/categories')
        ->assertSessionHas('success');

    $added = FinanceCategory::query()->ofKind(FinanceCategoryKind::Expense)->where('name', 'Travel')->firstOrFail();

    // At the end of its OWN side's list, not at the end of both.
    expect($added->kind)->toBe(FinanceCategoryKind::Expense)
        ->and($added->position)->toBeGreaterThan(
            (int) FinanceCategory::query()->ofKind(FinanceCategoryKind::Expense)->where('name', 'Office')->value('position')
        );

    $audit = financeLedgerCategoryAudit();

    expect($audit->old_value)->toBeNull()
        ->and($audit->new_value['name'])->toBe('Travel')
        ->and($audit->new_value['kind'])->toBe('expense');
})->group('phase8', 'finance');

it('refuses a duplicate name on the same side, and allows it on the other', function () {
    $this->actingAs($this->admin)
        ->post('/finance/categories', ['kind' => 'income', 'name' => 'SEO'])
        ->assertSessionHasErrors('name');

    // "Other" exists on both sides and they are different categories (decision 8-1), so a name
    // taken on one side is free on the other. "Payroll" is an expense category; as income it is
    // a name nobody has taken.
    $this->actingAs($this->admin)
        ->post('/finance/categories', ['kind' => 'income', 'name' => 'Payroll'])
        ->assertSessionHasNoErrors();

    expect(FinanceCategory::query()->where('name', 'Payroll')->count())->toBe(2);
})->group('phase8', 'finance');

it('lets the admin rename a category, and every record under it keeps it', function () {
    $filedUnderIt = Income::query()->where('category_id', $this->seo->id)->pluck('id');

    expect($filedUnderIt)->not->toBeEmpty();

    $this->actingAs($this->admin)
        ->put("/finance/categories/{$this->seo->id}", ['name' => 'Search'])
        ->assertRedirect('/finance/categories')
        ->assertSessionHas('success');

    expect($this->seo->fresh()->name)->toBe('Search')
        // The rows point at the id, so a rename moves every one of them at once — which is why
        // the dialog says so out loud.
        ->and(Income::query()->where('category_id', $this->seo->id)->pluck('id')->all())
        ->toBe($filedUnderIt->all());

    $audit = financeLedgerCategoryAudit();

    expect($audit->old_value['name'])->toBe('SEO')
        ->and($audit->new_value['name'])->toBe('Search');
})->group('phase8', 'finance');

it('will not move a category to the other side of the ledger, however it is asked', function () {
    // `kind` is not a field `UpdateFinanceCategoryRequest` accepts. Beneath that,
    // `FinanceService::updateCategory()` refuses a differing kind and `ON UPDATE RESTRICT`
    // refuses it for any category in use. Sending one renames and nothing else.
    $this->actingAs($this->admin)
        ->put("/finance/categories/{$this->unused->id}", ['name' => 'Sundry', 'kind' => 'expense'])
        ->assertSessionHasNoErrors();

    expect($this->unused->fresh()->kind)->toBe(FinanceCategoryKind::Income)
        ->and($this->unused->fresh()->name)->toBe('Sundry');
})->group('phase8', 'finance');

/*
|--------------------------------------------------------------------------
| Deleting — and the one refusal that has to be a sentence
|--------------------------------------------------------------------------
*/

it('lets the admin delete a category nothing is filed under', function () {
    $id = $this->unused->getKey();

    $this->actingAs($this->admin)
        ->delete("/finance/categories/{$id}")
        ->assertRedirect('/finance/categories')
        ->assertSessionHas('success');

    expect(FinanceCategory::find($id))->toBeNull();

    $audit = financeLedgerCategoryAudit();

    expect($audit->old_value['name'])->toBe('Other')
        ->and($audit->new_value)->toBeNull();
})->group('phase8', 'finance');

it('refuses to delete a category in use, in words rather than with a 500', function () {
    $inUse = Income::query()->where('category_id', $this->seo->id)->count();

    expect($inUse)->toBeGreaterThan(0);

    $response = $this->actingAs($this->admin)
        ->from('/finance/categories')
        ->delete("/finance/categories/{$this->seo->id}");

    // A redirect with a sentence, not a foreign-key violation and not a 500. `ON DELETE
    // RESTRICT` is the promise; this is what a person reads.
    $response->assertRedirect('/finance/categories');

    $message = session('error');

    expect($message)->toBeString()
        ->and($message)->toContain('SEO')
        // The blast radius is in the sentence, because the next question is always "how many?".
        ->and($message)->toContain((string) $inUse)
        ->and($message)->toContain('cannot be deleted')
        ->and($message)->not->toContain('SQLSTATE');

    expect(FinanceCategory::find($this->seo->id))->not->toBeNull();
})->group('phase8', 'finance');

it('writes no audit row when a category deletion is refused', function () {
    $before = AuditLog::count();

    $this->actingAs($this->admin)->delete("/finance/categories/{$this->seo->id}");

    expect(AuditLog::count())->toBe($before);
})->group('phase8', 'finance');

it('marks a category in use on the payload, so the screen does not offer a control that would fail', function () {
    $props = $this->actingAs($this->admin)->get('/finance/categories')->assertOk()->viewData('page')['props'];

    $seo = collect($props['income'])->firstWhere('name', 'SEO');
    $other = collect($props['income'])->firstWhere('name', 'Other');

    expect($seo['in_use'])->toBeTrue()
        ->and($seo['usage_count'])->toBe(Income::query()->where('category_id', $this->seo->id)->count())
        ->and($other['in_use'])->toBeFalse()
        ->and($other['usage_count'])->toBe(0);
})->group('phase8', 'finance');

/*
|--------------------------------------------------------------------------
| What the pickers get
|--------------------------------------------------------------------------
*/

it('sends each finance form only its own side of the list', function () {
    $income = $this->actingAs($this->accountant)
        ->get('/finance/income/create')
        ->assertOk()
        ->viewData('page')['props']['categories'];

    $expense = $this->actingAs($this->accountant)
        ->get('/finance/expenses/create')
        ->assertOk()
        ->viewData('page')['props']['categories'];

    expect(array_unique(array_column($income, 'kind')))->toBe(['income'])
        ->and(array_unique(array_column($expense, 'kind')))->toBe(['expense']);

    // The picker does not count usage — nine counts to render a dropdown that never shows the
    // number. Absent, not zero: a screen must not read "nothing is filed under this" from a
    // caller that did not ask.
    expect($income[0])->not->toHaveKey('usage_count')
        ->and($income[0])->not->toHaveKey('in_use');
})->group('phase8', 'finance');
