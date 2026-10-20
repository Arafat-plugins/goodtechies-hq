<?php

use App\Models\Expense;
use App\Models\FinanceCategory;
use App\Models\Income;
use App\Models\PayrollItem;
use App\Models\PayrollPeriod;
use App\Models\Project;
use App\Models\User;
use App\Services\FinanceService;
use App\Support\Currency;
use App\Support\FinanceCategoryKind;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Every finance record carries its own currency
|--------------------------------------------------------------------------
|
| BDT or USD on every income row, expense row and repeating template. The
| server refuses anything else; the month's rollup reports each currency on
| its own and never adds two together (there is no exchange rate); and the
| Income list filters by currency, category, project and a notes search.
|
| Helpers are prefixed financeCurrency*, because Pest declares them
| globally across the whole suite (AGENTS.md).
|
*/

function financeCurrencyCategoryId(FinanceCategoryKind $kind, string $name): int
{
    return (int) FinanceCategory::query()->ofKind($kind)->where('name', $name)->firstOrFail()->getKey();
}

/**
 * @param  array<string, mixed>  $attributes
 */
function financeCurrencyIncome(User $by, array $attributes): Income
{
    return Income::create($attributes + [
        'category_id' => financeCurrencyCategoryId(FinanceCategoryKind::Income, 'Maintenance'),
        'recorded_by' => $by->getKey(),
        'notes' => null,
        'project_id' => null,
    ]);
}

/**
 * @return list<int>
 */
function financeCurrencyRecordIds(array $props): array
{
    return collect($props['records'])->pluck('id')->sort()->values()->all();
}

beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->accountant = User::where('email', 'accountant@goodtechies.test')->firstOrFail();
    $this->service = app(FinanceService::class);
});

/*
|--------------------------------------------------------------------------
| Writing
|--------------------------------------------------------------------------
*/

it('stores the currency chosen on an income and on an expense', function (string $currency) {
    $this->actingAs($this->accountant)
        ->post('/finance/income', [
            'category_id' => financeCurrencyCategoryId(FinanceCategoryKind::Income, 'SEO'),
            'amount' => '400.00',
            'currency' => $currency,
            'date' => '2027-02-11',
            'notes' => 'Currency test income',
        ])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success');

    $this->actingAs($this->accountant)
        ->post('/finance/expenses', [
            'category_id' => financeCurrencyCategoryId(FinanceCategoryKind::Expense, 'Office'),
            'amount' => '75.00',
            'currency' => $currency,
            'date' => '2027-02-12',
            'notes' => 'Currency test expense',
        ])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success');

    expect(Income::where('notes', 'Currency test income')->firstOrFail()->currency)->toBe($currency)
        ->and(Expense::where('notes', 'Currency test expense')->firstOrFail()->currency)->toBe($currency);
})->with(['BDT', 'USD'])->group('finance');

it('refuses a missing or unknown currency on income and expenses with a 422 on the field', function (?string $currency) {
    $income = [
        'category_id' => financeCurrencyCategoryId(FinanceCategoryKind::Income, 'SEO'),
        'amount' => '400.00',
        'date' => '2027-02-11',
    ];
    $expense = [
        'category_id' => financeCurrencyCategoryId(FinanceCategoryKind::Expense, 'Office'),
        'amount' => '75.00',
        'date' => '2027-02-12',
    ];

    if ($currency !== null) {
        $income['currency'] = $currency;
        $expense['currency'] = $currency;
    }

    $incomeBefore = Income::count();
    $expenseBefore = Expense::count();

    $this->actingAs($this->accountant)
        ->postJson('/finance/income', $income)
        ->assertStatus(422)
        ->assertJsonValidationErrors('currency');

    $this->actingAs($this->accountant)
        ->postJson('/finance/expenses', $expense)
        ->assertStatus(422)
        ->assertJsonValidationErrors('currency');

    expect(Income::count())->toBe($incomeBefore)
        ->and(Expense::count())->toBe($expenseBefore);
})->with([null, 'EUR'])->group('finance');

it('says to choose BDT or USD when the currency is unknown', function () {
    $this->actingAs($this->accountant)
        ->postJson('/finance/income', [
            'category_id' => financeCurrencyCategoryId(FinanceCategoryKind::Income, 'SEO'),
            'amount' => '400.00',
            'currency' => 'EUR',
            'date' => '2027-02-11',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['currency' => 'Choose BDT or USD.']);
})->group('finance');

/*
|--------------------------------------------------------------------------
| The rollup, per currency
|--------------------------------------------------------------------------
*/

it('reports each currency on its own and never adds BDT to USD', function () {
    financeCurrencyIncome($this->accountant, ['amount' => '100.00', 'currency' => 'BDT', 'date' => '2027-02-05']);
    financeCurrencyIncome($this->accountant, ['amount' => '50.00', 'currency' => 'USD', 'date' => '2027-02-06']);

    $rollup = $this->service->monthlyRollup($this->admin, 2027, 2);
    $byCurrency = collect($rollup['by_currency'])->keyBy('currency');

    expect($byCurrency->keys()->sort()->values()->all())->toBe(['BDT', 'USD'])
        ->and($byCurrency['BDT']['income']['total'])->toBe('100.00')
        ->and($byCurrency['USD']['income']['total'])->toBe('50.00')
        ->and($byCurrency['BDT']['net'])->toBe('100.00')
        ->and($byCurrency['USD']['net'])->toBe('50.00')
        // The default currency comes first.
        ->and($rollup['by_currency'][0]['currency'])->toBe(Currency::default()->value);

    foreach ($rollup['by_currency'] as $entry) {
        expect($entry['income']['total'])->not->toBe('150.00');
    }
})->group('finance');

it('puts the counted payroll line only under the default currency', function () {
    $default = Currency::default()->value;
    $other = $default === 'BDT' ? 'USD' : 'BDT';

    Expense::create([
        'category_id' => financeCurrencyCategoryId(FinanceCategoryKind::Expense, 'Hosting'),
        'amount' => '100.00',
        'currency' => $other,
        'date' => '2027-05-10',
        'recorded_by' => $this->accountant->getKey(),
    ]);

    $period = PayrollPeriod::factory()->forMonth('2027-05-01')->create();
    PayrollItem::factory()->in($period)->of('1000.00', '0.00')->create();

    DB::table('payroll_periods')->where('id', $period->getKey())->update([
        'status' => 'locked',
        'locked_at' => Carbon::now(),
    ]);

    $rollup = $this->service->monthlyRollup($this->admin, 2027, 5);
    $byCurrency = collect($rollup['by_currency'])->keyBy('currency');

    $salaries = fn (string $currency) => collect($byCurrency[$currency]['expenses']['categories'])
        ->where('source', 'payroll')
        ->values()
        ->all();

    expect($rollup['payroll']['counted'])->toBeTrue()
        ->and($rollup['payroll']['currency'])->toBe($default)
        ->and($salaries($default))->toHaveCount(1)
        ->and($salaries($default)[0]['name'])->toBe('Salaries (payroll)')
        ->and($salaries($default)[0]['category_id'])->toBe(0)
        ->and($byCurrency[$default]['expenses']['total'])->toBe('1000.00')
        ->and($salaries($other))->toBe([])
        ->and($byCurrency[$other]['expenses']['total'])->toBe('100.00');
})->group('finance');

/*
|--------------------------------------------------------------------------
| Filtering the Income list
|--------------------------------------------------------------------------
*/

it('filters the income list by currency, category, project and a notes search', function () {
    $project = Project::where('name', 'Buffalo Modular — SEO')->firstOrFail();
    $seo = financeCurrencyCategoryId(FinanceCategoryKind::Income, 'SEO');

    $bdtRetainer = financeCurrencyIncome($this->accountant, [
        'amount' => '100.00', 'currency' => 'BDT', 'date' => '2027-03-03', 'notes' => 'Monthly retainer',
    ]);
    $usdSeo = financeCurrencyIncome($this->accountant, [
        'amount' => '50.00', 'currency' => 'USD', 'date' => '2027-03-04', 'notes' => 'One-off audit',
        'category_id' => $seo, 'project_id' => $project->getKey(),
    ]);
    $usdPlain = financeCurrencyIncome($this->accountant, [
        'amount' => '70.00', 'currency' => 'USD', 'date' => '2027-03-05', 'notes' => 'Hosting fee',
    ]);

    $props = fn (string $query) => $this->actingAs($this->accountant)
        ->get('/finance/income?month=2027-03&'.$query)
        ->assertOk()
        ->viewData('page')['props'];

    $all = $props('');
    expect(financeCurrencyRecordIds($all))->toBe([$bdtRetainer->id, $usdSeo->id, $usdPlain->id])
        ->and($all['filters'])->toBe(['category' => null, 'currency' => null, 'project' => null, 'q' => ''])
        ->and($all['currencies'])->toBe(Currency::options())
        ->and($all['defaultCurrency'])->toBe(Currency::default()->value);

    $byCurrency = $props('currency=BDT');
    expect(financeCurrencyRecordIds($byCurrency))->toBe([$bdtRetainer->id])
        ->and($byCurrency['filters']['currency'])->toBe('BDT');

    $byCategory = $props('category='.$seo);
    expect(financeCurrencyRecordIds($byCategory))->toBe([$usdSeo->id])
        ->and($byCategory['filters']['category'])->toBe($seo);

    $unlinked = $props('project=none');
    expect(financeCurrencyRecordIds($unlinked))->toBe([$bdtRetainer->id, $usdPlain->id])
        ->and($unlinked['filters']['project'])->toBe('none');

    $linked = $props('project='.$project->getKey());
    expect(financeCurrencyRecordIds($linked))->toBe([$usdSeo->id]);

    $search = $props('q=retainer');
    expect(financeCurrencyRecordIds($search))->toBe([$bdtRetainer->id])
        ->and($search['filters']['q'])->toBe('retainer');

    // Anything that does not parse is ignored, not refused.
    $junk = $props('currency=EUR&category=999999');
    expect(financeCurrencyRecordIds($junk))->toBe([$bdtRetainer->id, $usdSeo->id, $usdPlain->id])
        ->and($junk['filters']['currency'])->toBeNull()
        ->and($junk['filters']['category'])->toBeNull();

    // Each row carries its own currency.
    expect(collect($all['records'])->pluck('currency', 'id')->all())->toBe([
        $usdPlain->id => 'USD',
        $usdSeo->id => 'USD',
        $bdtRetainer->id => 'BDT',
    ]);
})->group('finance');
