<?php

use App\Models\User;
use App\Services\FinanceService;
use App\Services\ReportService;
use App\Services\SettingsService;
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

    // D7: the report is in BDT, every USD figure converted at the stored rate. Pin it.
    app(SettingsService::class)->set('usd_bdt_rate_mode', 'manual', $this->admin);
    app(SettingsService::class)->set('usd_bdt_rate', '122.00', $this->admin);

    $full = app(FinanceService::class)->monthlyRollup($this->admin, 2026, 9);
    $this->rollup = $full['bdt'] + ['label' => $full['label']];
});

it('equals FinanceService::monthlyRollup() for a window that is exactly one calendar month', function () {
    $result = finReportBuild($this->admin, '2026-09-01', '2026-09-30');

    // One row for the month, in BDT: the USD and BDT figures converted and added together.
    expect($result->rows)->toHaveCount(1);

    // The row, string for string. Not "close to", not cast through a float on the way.
    expect($result->rows[0])->toBe([
        'month' => $this->rollup['label'],
        'income' => $this->rollup['income']['total'],
        'expenses' => $this->rollup['expenses']['total'],
        'net' => $this->rollup['net'],
    ]);

    // One month: the footer is the rollup's own strings, and a note names the rate.
    expect($result->totals['income'])->toBe($this->rollup['income']['total'])
        ->and($result->totals['net'])->toBe($this->rollup['net'])
        ->and($result->notes)->toContain('USD amounts are converted at 1 USD = 122.00 BDT (set by admin).');
})->group('phase10', 'reports');

it('carries Part D §13\'s own September figures', function () {
    $result = finReportBuild($this->admin, '2026-09-01', '2026-09-30');

    // FinanceSeeder: $2,400 of USD income (500 + 300 SEO, 350 maintenance, 1250 website) at
    // 122.00 is 292,800.00 BDT, plus 260 + 250 BDT maintenance.
    expect($result->rows[0]['income'])->toBe('293310.00')
        // $2,110 of USD expenses (180 + 95 + 120 + 60 + 200 + 55 + 1400) at 122.00.
        ->and($result->rows[0]['expenses'])->toBe('257420.00')
        ->and($result->rows[0]['net'])->toBe('35890.00');

    // The category donuts are the BDT category lines: the acceptance example's three, not four
    // with an empty "Other".
    $income = collect($result->charts[0]->series)->pluck('value', 'label')->all();
    expect($income)->toBe(['Website' => '152500.00', 'SEO' => '97600.00', 'Maintenance' => '43210.00']);
})->group('phase10', 'reports');

it('keeps every figure a string and never a float', function () {
    $result = finReportBuild($this->admin, '2026-09-01', '2026-09-30');
    $footer = finReportBuild($this->admin, '2026-08-01', '2026-08-31')->totals;

    foreach (['income', 'expenses', 'net'] as $key) {
        expect($footer[$key])->toBeString();

        foreach ($result->rows as $row) {
            expect($row[$key])->toBeString()
                // Two decimal places, always — `8.6` is what a float would have left behind.
                ->and($row[$key])->toMatch('/^-?\d+\.\d{2}$/');
        }
    }

    foreach ($result->charts as $chart) {
        foreach ($chart->series as $slice) {
            expect($slice['value'])->toBeString();
        }
    }
})->group('phase10', 'reports');

it('adds a multi-month window up in cents, and each month is still its own rollup', function () {
    $result = finReportBuild($this->admin, '2026-08-01', '2026-10-31');

    // One row per month, in BDT.
    expect($result->rows)->toHaveCount(3)
        ->and(array_column($result->rows, 'month'))
        ->toBe(['August 2026', 'September 2026', 'October 2026']);

    expect($result->rows[0])->toBe(['month' => 'August 2026', 'income' => '0.00', 'expenses' => '0.00', 'net' => '0.00'])
        // The range total, added up in cents.
        ->and($result->totals['month'])->toBe('Range total')
        ->and($result->totals['income'])->toBe($this->rollup['income']['total'])
        ->and($result->totals['net'])->toBe($this->rollup['net']);

    // Every month is the rollup for that month, so adding a row to October moves exactly one
    // row and the footer, and never September.
    foreach ($result->rows as $row) {
        $month = Carbon::createFromFormat('F Y', (string) $row['month'])->startOfMonth();
        $rollup = app(FinanceService::class)->monthlyRollup($this->admin, (int) $month->year, (int) $month->month)['bdt'];

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
