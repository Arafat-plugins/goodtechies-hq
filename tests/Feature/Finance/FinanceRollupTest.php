<?php

use App\Models\Expense;
use App\Models\Income;
use App\Models\User;
use App\Services\FinanceService;
use App\Support\FinanceCategoryKind;
use Database\Factories\ExpenseFactory;
use Database\Factories\IncomeFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Monthly rollups — Part D §13's acceptance example, verbatim
|--------------------------------------------------------------------------
|
|   "Monthly totals per category auto-computed. […] Example rollup:
|    September 2026 — Maintenance $860, SEO $800, Website $1,250 → $2,910."
|
| The first test is that sentence and nothing else. The second is the half
| that matters to the client: the SEEDED database makes it true, so the
| number is demonstrable in the running application and not only here.
|
| The rest is the rule underneath: totals are computed, never stored. That
| is the same reasoning that kept `completed` off `meetings.status` (7-3)
| and an overdue flag off tasks — a second statement of a fact is a thing
| somebody has to keep in step, and here the two things that would disagree
| are both about money.
|
*/

/** Part D §13's example, as a list. */
const FINANCE_ROLLUP_SEPTEMBER = [
    'Maintenance' => '860.00',
    'SEO' => '800.00',
    'Website' => '1250.00',
];

const FINANCE_ROLLUP_SEPTEMBER_TOTAL = '2910.00';

beforeEach(function () {
    $this->seed();

    $this->service = app(FinanceService::class);
    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->accountant = User::where('email', 'accountant@goodtechies.test')->firstOrFail();
});

/*
|--------------------------------------------------------------------------
| The acceptance example
|--------------------------------------------------------------------------
*/

it('rolls September 2026 up to Maintenance $860, SEO $800, Website $1,250 → $2,910', function () {
    $rollup = $this->service->monthlyRollup($this->admin, 2026, 9);

    $lines = collect($rollup['income']['categories'])->pluck('total', 'name')->all();

    expect($lines)->toBe(FINANCE_ROLLUP_SEPTEMBER)
        ->and($rollup['income']['total'])->toBe(FINANCE_ROLLUP_SEPTEMBER_TOTAL)
        ->and($rollup['month'])->toBe('2026-09')
        ->and($rollup['label'])->toBe('September 2026');
})->group('phase8', 'finance');

it('gets that number from the seeded rows, so it is true in the running application', function () {
    // Six seeded income rows across three categories — not one row per category. The sentence
    // has to be true of real bookkeeping, otherwise the demo is a fixture.
    expect(Income::query()->inMonth(2026, 9)->count())->toBe(6)
        ->and((string) Income::query()->inMonth(2026, 9)->sum('amount'))->toBe('2910.00');

    $maintenance = Income::query()
        ->inMonth(2026, 9)
        ->whereHas('category', fn ($q) => $q->where('name', 'Maintenance'))
        ->get();

    expect($maintenance)->toHaveCount(3)
        // number_format, because summing a Collection of decimal strings in PHP gives back
        // "860" — the value is right and the scale is not, and the scale is the point.
        ->and(number_format((float) $maintenance->sum('amount'), 2, '.', ''))->toBe('860.00');
})->group('phase8', 'finance');

it('reports September expenses and the operating result', function () {
    $rollup = $this->service->monthlyRollup($this->accountant, 2026, 9);

    expect($rollup['expenses']['total'])->toBe('2110.00')
        // Part D §13's "operating result": what came in less what went out.
        ->and($rollup['net'])->toBe('800.00');
})->group('phase8', 'finance');

/*
|--------------------------------------------------------------------------
| What the rollup is, and what it is not
|--------------------------------------------------------------------------
*/

it('stores no total anywhere — there is no column to go stale', function () {
    foreach (['income', 'expenses', 'finance_categories'] as $table) {
        foreach (['total', 'monthly_total', 'running_total', 'balance', 'net'] as $column) {
            expect(Schema::hasColumn($table, $column))->toBeFalse("{$table}.{$column} exists");
        }
    }
})->group('phase8', 'finance');

it('follows an edit immediately, because it is a query and not a cache', function () {
    $income = Income::query()
        ->inMonth(2026, 9)
        ->whereHas('category', fn ($q) => $q->where('name', 'Website'))
        ->firstOrFail();

    $this->service->updateIncome($this->accountant, $income, ['amount' => '1000.00']);

    $rollup = $this->service->monthlyRollup($this->admin, 2026, 9);

    expect(collect($rollup['income']['categories'])->firstWhere('name', 'Website')['total'])->toBe('1000.00')
        ->and($rollup['income']['total'])->toBe('2660.00');
})->group('phase8', 'finance');

it('drops a deleted record out of the month at once', function () {
    $income = Income::query()
        ->inMonth(2026, 9)
        ->whereHas('category', fn ($q) => $q->where('name', 'Website'))
        ->firstOrFail();

    $this->service->deleteIncome($this->accountant, $income);

    $rollup = $this->service->monthlyRollup($this->admin, 2026, 9);

    expect(collect($rollup['income']['categories'])->pluck('name')->all())->toBe(['Maintenance', 'SEO'])
        ->and($rollup['income']['total'])->toBe('1660.00');
})->group('phase8', 'finance');

it('leaves a category with no rows this month out of the list rather than showing a zero', function () {
    // "Other" is seeded and empty. The rollup is a report of what happened; inventing zero rows
    // to make a layout easier is how a report starts lying. A screen that wants every category
    // asks for the categories.
    $names = collect($this->service->monthlyRollup($this->admin, 2026, 9)['income']['categories'])->pluck('name');

    expect($names)->not->toContain('Other')
        ->and($this->service->categories(FinanceCategoryKind::Income)->pluck('name'))->toContain('Other');
})->group('phase8', 'finance');

it('counts a row dated on the first and on the last day of the month, and nothing outside it', function () {
    // Boundary arithmetic on a half-open date range is where an off-by-one month lives.
    $category = IncomeFactory::category(FinanceCategoryKind::Income, 'Website');

    foreach (['2026-10-01', '2026-10-31'] as $date) {
        Income::factory()->recordedBy($this->accountant)->of('100.00')->on($date)->create(['category_id' => $category]);
    }

    foreach (['2026-09-30', '2026-11-01'] as $date) {
        Income::factory()->recordedBy($this->accountant)->of('999.00')->on($date)->create(['category_id' => $category]);
    }

    expect($this->service->monthlyRollup($this->admin, 2026, 10)['income']['total'])->toBe('200.00');
})->group('phase8', 'finance');

it('adds cents exactly, where a float would drift', function () {
    // Ten rows of $0.10 are $1.00. In binary floating point they are 0.9999999999999999, and a
    // ledger that is a cent out once a year is a ledger nobody trusts. decimal(12,2) in the
    // column, integer cents in the net.
    $category = ExpenseFactory::category(FinanceCategoryKind::Expense, 'Operations');

    for ($i = 0; $i < 10; $i++) {
        Expense::factory()->recordedBy($this->accountant)->of('0.10')->on('2026-11-0'.($i % 9 + 1))->create([
            'category_id' => $category,
        ]);
    }

    $rollup = $this->service->monthlyRollup($this->admin, 2026, 11);

    expect($rollup['expenses']['total'])->toBe('1.00')
        ->and($rollup['net'])->toBe('-1.00');
})->group('phase8', 'finance');

it('reports an empty month as zeroes rather than failing', function () {
    $rollup = $this->service->monthlyRollup($this->admin, 2027, 4);

    expect($rollup['income']['categories'])->toBe([])
        ->and($rollup['income']['total'])->toBe('0.00')
        ->and($rollup['expenses']['total'])->toBe('0.00')
        ->and($rollup['net'])->toBe('0.00');
})->group('phase8', 'finance');

it('sums in the database rather than in PHP', function () {
    // One GROUP BY per side, not a row-by-row addition: the Finance report in Phase 10 reads
    // twelve months at a time and a rollup that loaded every row would load a year of them.
    DB::enableQueryLog();

    $this->service->monthlyRollup($this->admin, 2026, 9);

    $sums = collect(DB::getQueryLog())
        ->filter(fn (array $q): bool => stripos($q['query'], 'sum(') !== false);

    expect($sums)->toHaveCount(2);

    DB::disableQueryLog();
})->group('phase8', 'finance');
