<?php

use App\Models\Expense;
use App\Models\FinanceCategory;
use App\Models\Income;
use App\Models\Project;
use App\Models\ProjectFinance;
use App\Models\User;
use App\Services\ReportService;
use App\Services\SettingsService;
use App\Support\FinanceCategoryKind;
use App\Support\ProjectStatus;
use App\Support\ReportFilters;
use App\Support\ReportKey;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| Every finance summary is in BDT (D7)
|--------------------------------------------------------------------------
|
| 100.00 BDT income, 40.00 BDT expense and 50.00 USD income in February
| 2027, at 1 USD = 122.00 BDT. The finance dashboard and report, the
| Reports screen's Finance report and the Admin dashboard's finance card
| all show BDT: the USD income is converted (6,100.00 BDT) and added in.
| The projects table keeps the original USD figure beside the BDT one and
| carries no Costs or Profit.
|
| Helpers are prefixed currencyReport*, because Pest declares them
| globally across the whole suite.
|
*/

function currencyReportCategory(FinanceCategoryKind $kind, string $name): int
{
    return (int) FinanceCategory::query()->ofKind($kind)->where('name', $name)->firstOrFail()->getKey();
}

/**
 * @param  array<string, mixed>  $attributes
 */
function currencyReportIncome(User $by, array $attributes): Income
{
    return Income::create($attributes + [
        'category_id' => currencyReportCategory(FinanceCategoryKind::Income, 'Maintenance'),
        'recorded_by' => $by->getKey(),
        'notes' => null,
        'project_id' => null,
        'date' => '2027-02-10',
    ]);
}

beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->accountant = User::where('email', 'accountant@goodtechies.test')->firstOrFail();

    app(SettingsService::class)->set('currency', 'BDT', $this->admin);
    app(SettingsService::class)->set('usd_bdt_rate_mode', 'manual', $this->admin);
    app(SettingsService::class)->set('usd_bdt_rate', '122.00', $this->admin);

    $this->project = Project::factory()->create(['status' => ProjectStatus::Active]);
    ProjectFinance::factory()->for($this->project)->create([
        'price' => null,
        'recurring_amount' => '300.00',
        'billing_frequency' => 'monthly',
        'hourly_rate' => null,
        'currency' => 'USD',
    ]);

    currencyReportIncome($this->accountant, ['amount' => '100.00', 'currency' => 'BDT']);
    currencyReportIncome($this->accountant, ['amount' => '50.00', 'currency' => 'USD', 'project_id' => $this->project->getKey()]);

    Expense::create([
        'category_id' => currencyReportCategory(FinanceCategoryKind::Expense, 'Office'),
        'recorded_by' => $this->accountant->getKey(),
        'amount' => '40.00',
        'currency' => 'BDT',
        'date' => '2027-02-11',
        'notes' => null,
        'project_id' => $this->project->getKey(),
    ]);
});

it('shows the finance dashboard in BDT, USD converted at the stored rate', function () {
    $props = $this->actingAs($this->accountant)->get('/finance?month=2027-02')->assertOk()->inertiaProps();

    expect($props['currency'])->toBe('BDT')
        ->and($props)->not->toHaveKeys(['currencies', 'otherCurrencies'])
        ->and($props['rollup']['income']['total'])->toBe('6200.00')
        ->and($props['rollup']['expenses']['total'])->toBe('40.00')
        ->and($props['rollup']['net'])->toBe('6160.00')
        ->and($props['rollup'])->toHaveKey('payroll')
        ->and($props['showPayroll'])->toBeTrue()
        ->and($props['rate']['usd_bdt'])->toBe('122.00')
        ->and($props['rate']['mode'])->toBe('manual')
        ->and($props['rate']['can_edit'])->toBeTrue()
        ->and($props['earnings']['currency'])->toBe('BDT');
})->group('finance');

it('ignores a currency in the URL: the figures stay in BDT', function () {
    $props = $this->actingAs($this->accountant)->get('/finance?month=2027-02&currency=USD')->assertOk()->inertiaProps();

    expect($props['currency'])->toBe('BDT')
        ->and($props['rollup']['income']['total'])->toBe('6200.00')
        ->and($props['showPayroll'])->toBeTrue()
        ->and($props['reportHref'])->not->toContain('currency=');
})->group('finance');

it('converts the report\'s by-project cut, trend and project earnings to BDT', function () {
    $props = $this->actingAs($this->admin)->get('/finance/report?month=2027-02&months=3')->assertOk()->inertiaProps();

    $point = collect($props['trend']['points'])->firstWhere('month', '2027-02');
    $row = collect($props['earnings']['rows'])->firstWhere('project.id', $this->project->getKey());

    expect($props['byProject']['total'])->toBe('6200.00')
        ->and($props['trend']['currency'])->toBe('BDT')
        ->and($point['income'])->toBe('6200.00')
        ->and($point['expense'])->toBe('40.00')
        ->and($props['earnings']['currency'])->toBe('BDT')
        ->and($row['currency'])->toBe('USD')
        ->and($row['expected'])->toBe('300.00')
        ->and($row['expected_bdt'])->toBe('36600.00')
        ->and($row['received'])->toBe('50.00')
        ->and($row['received_bdt'])->toBe('6100.00')
        ->and($row['outstanding'])->toBe('250.00')
        ->and($row['outstanding_bdt'])->toBe('30500.00');
})->group('finance');

it('carries no Costs or Profit on the projects table', function () {
    $earnings = $this->actingAs($this->admin)->get('/finance?month=2027-02')->assertOk()->inertiaProps()['earnings'];

    expect($earnings['rows'])->not->toBeEmpty()
        ->and($earnings['totals'])->not->toHaveKeys(['costs', 'profit']);

    foreach ($earnings['rows'] as $row) {
        expect($row)->not->toHaveKeys(['costs', 'billable_costs', 'profit']);
    }

    // The expense itself is untouched: still in the books, still linked to the project.
    expect(Expense::where('project_id', $this->project->getKey())->value('amount'))->toBe('40.00');
})->group('finance');

it('gives the Reports screen one BDT row per month with a totals row and the rate note', function () {
    $result = app(ReportService::class)->build(
        ReportKey::Finance,
        $this->admin,
        ReportFilters::for(ReportKey::Finance, ['from' => '2027-02-01', 'to' => '2027-02-28'], Carbon::today()),
    );

    expect($result->rows)->toHaveCount(1)
        ->and($result->rows[0]['income'])->toBe('6200.00')
        ->and($result->rows[0]['net'])->toBe('6160.00')
        ->and($result->rows[0])->not->toHaveKey('currency')
        ->and($result->totals['income'])->toBe('6200.00')
        ->and($result->totals['net'])->toBe('6160.00')
        ->and($result->notes)->toContain('USD amounts are converted at 1 USD = 122.00 BDT (set by admin).')
        ->and(collect($result->charts)->map(fn ($chart) => $chart->jsonSerialize()['title'])->all())
        ->toBe(['Income by category', 'Expenses by category']);

    $income = collect($result->columns)->firstWhere('key', 'income')->jsonSerialize();
    expect($income)->not->toHaveKey('currency_key');
})->group('finance');

it('shows BDT on the Admin dashboard finance card with USD converted in', function () {
    $this->travelTo('2027-02-15');

    $finance = $this->actingAs($this->admin)->get('/admin/dashboard')->assertOk()->inertiaProps()['finance'];

    expect($finance['currency'])->toBe('BDT')
        ->and($finance['income'])->toBe('6200.00')
        ->and($finance['expense'])->toBe('40.00')
        ->and($finance['operating_result'])->toBe('6160.00')
        ->and($finance)->not->toHaveKey('other_currencies');
})->group('finance');

it('refuses an unknown currency with a validation error, not a 500', function () {
    $this->actingAs($this->accountant)
        ->get('/finance?month=2027-02&currency=EUR')
        ->assertRedirect()
        ->assertSessionHasErrors('currency');

    $this->actingAs($this->accountant)
        ->getJson('/finance/report?month=2027-02&currency=EUR')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('currency');
})->group('finance');
