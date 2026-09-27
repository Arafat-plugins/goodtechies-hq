<?php

use App\Models\User;
use App\Services\FinanceService;
use App\Services\ReportService;
use App\Support\ReportFilters;
use App\Support\ReportKey;
use App\Support\ReportResult;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| The Finance report IS the monthly rollup
|--------------------------------------------------------------------------
|
| docs/report-contract.md §4 rule 2:
|
|   "Every figure the app already states elsewhere is read from the service
|    that states it. The Finance report's month totals come from
|    FinanceService. A report is a new CUT, never a second opinion."
|
| Two disagreeing statements about September is the failure this file
| exists to prevent, and an assertion that the report is "about right" would
| not prevent it. So the test is equality, string for string, against
| FinanceService::monthlyRollup() — the same method the Finance screens and
| the Admin dashboard's operating-result row read.
|
| Part D §13's acceptance example is the anchor: September 2026 income
| Maintenance $860, SEO $800, Website $1,250 → $2,910. FinanceSeeder makes
| that true in the running application, so the number below is not a fixture
| invented for this test.
|
| Prefixed FINREPORT_ / finReport*, because Pest declares both globally.
|
*/

function finReportBuild(User $viewer, string $from, string $to): ReportResult
{
    return app(ReportService::class)->build(
        ReportKey::Finance,
        $viewer,
        ReportFilters::for(ReportKey::Finance, ['from' => $from, 'to' => $to], Carbon::today()),
    );
}

beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->rollup = app(FinanceService::class)->monthlyRollup($this->admin, 2026, 9);
});

it('equals FinanceService::monthlyRollup() for a window that is exactly one calendar month', function () {
    $result = finReportBuild($this->admin, '2026-09-01', '2026-09-30');

    expect($result->rows)->toHaveCount(1);

    // The row, string for string. Not "close to", not cast through a float on the way.
    expect($result->rows[0])->toBe([
        'month' => $this->rollup['label'],
        'income' => $this->rollup['income']['total'],
        'expenses' => $this->rollup['expenses']['total'],
        'net' => $this->rollup['net'],
    ]);

    // And the footer, which for a single month is the same three strings with no arithmetic
    // done to them at all.
    expect($result->totals['income'])->toBe($this->rollup['income']['total'])
        ->and($result->totals['expenses'])->toBe($this->rollup['expenses']['total'])
        ->and($result->totals['net'])->toBe($this->rollup['net']);
})->group('phase10', 'reports');

it('carries Part D §13\'s own September figures', function () {
    $result = finReportBuild($this->admin, '2026-09-01', '2026-09-30');

    // FinanceSeeder: 500 + 300 SEO, 350 + 260 + 250 maintenance, 1250 website.
    expect($result->rows[0]['income'])->toBe('2910.00')
        // 180 + 95 + 120 + 60 + 200 + 55 + 1400.
        ->and($result->rows[0]['expenses'])->toBe('2110.00')
        ->and($result->rows[0]['net'])->toBe('800.00');

    // The category donuts are the rollup's own category lines, which is why the three income
    // slices are the acceptance example's three and not four with an empty "Other".
    $income = collect($result->charts[0]->series)->pluck('value', 'label')->all();
    expect($income)->toBe(['Website' => '1250.00', 'Maintenance' => '860.00', 'SEO' => '800.00']);
})->group('phase10', 'reports');

it('keeps every figure a string and never a float', function () {
    $result = finReportBuild($this->admin, '2026-09-01', '2026-09-30');

    foreach (['income', 'expenses', 'net'] as $key) {
        expect($result->rows[0][$key])->toBeString()
            ->and($result->totals[$key])->toBeString()
            // Two decimal places, always — `8.6` is what a float would have left behind.
            ->and($result->rows[0][$key])->toMatch('/^-?\d+\.\d{2}$/');
    }

    foreach ($result->charts as $chart) {
        foreach ($chart->series as $slice) {
            expect($slice['value'])->toBeString();
        }
    }
})->group('phase10', 'reports');

it('adds a multi-month window up in cents, and each month is still its own rollup', function () {
    $result = finReportBuild($this->admin, '2026-08-01', '2026-10-31');

    expect($result->rows)->toHaveCount(3)
        ->and(array_column($result->rows, 'month'))
        ->toBe(['August 2026', 'September 2026', 'October 2026']);

    // Nothing is seeded in August or October, so the range total is September's.
    expect($result->rows[0])->toBe(['month' => 'August 2026', 'income' => '0.00', 'expenses' => '0.00', 'net' => '0.00']);
    expect($result->totals['income'])->toBe('2910.00')
        ->and($result->totals['net'])->toBe('800.00')
        ->and($result->totals['month'])->toBe('Range total');

    // Every month is the rollup for that month, so adding a row to October moves exactly one
    // row and the footer, and never September.
    foreach ($result->rows as $row) {
        $month = Carbon::createFromFormat('F Y', (string) $row['month'])->startOfMonth();
        $rollup = app(FinanceService::class)->monthlyRollup($this->admin, (int) $month->year, (int) $month->month);

        expect($row['income'])->toBe($rollup['income']['total'])
            ->and($row['net'])->toBe($rollup['net']);
    }
})->group('phase10', 'reports');

it('refuses to build the Finance report for somebody without finance.view', function () {
    // `FinanceService::monthlyRollup()` asks `IncomePolicy::viewAny` itself, so the refusal is
    // the existing one rather than a check this report wrote. The controller's 403 arrives
    // first in practice; this is the belt behind it.
    $employee = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();

    expect(fn () => finReportBuild($employee, '2026-09-01', '2026-09-30'))
        ->toThrow(AuthorizationException::class);
})->group('phase10', 'reports');
