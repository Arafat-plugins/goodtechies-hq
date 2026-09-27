<?php

use App\Models\User;
use App\Services\ReportService;
use App\Services\SettingsService;
use App\Support\ReportChart;
use App\Support\ReportColumn;
use App\Support\ReportFilter;
use App\Support\ReportFilters;
use App\Support\ReportFormat;
use App\Support\ReportKey;
use App\Support\ReportResult;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;

/*
|--------------------------------------------------------------------------
| The shape itself — the contract's §2 and §3
|--------------------------------------------------------------------------
|
| Sixteen reports share one screen, so the screen's vocabulary is the thing
| that has to hold: three charts, seven formats, rows keyed by column, a
| range that always has two ends and drops what the report does not accept.
|
| The chart cap is asserted by CONSTRUCTING a fourth, not by counting the
| eight that exist — a budget that is only true of today's reports is a
| budget the seventeenth one breaks.
|
| Prefixed SHAPE_ / shape*, because Pest declares constants and functions
| globally (AGENTS.md).
|
*/

function shapeChart(string $title = 'A chart'): ReportChart
{
    return ReportChart::donut($title, [['label' => 'One', 'value' => 1]]);
}

beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
});

/*
|--------------------------------------------------------------------------
| Three charts, and the constructor is what says so
|--------------------------------------------------------------------------
*/

it('accepts three charts and throws on a fourth', function () {
    $three = array_map(fn (int $i): ReportChart => shapeChart('Chart '.$i), range(1, 3));

    expect((new ReportResult(columns: [], rows: [], charts: $three))->charts)->toHaveCount(3);

    expect(fn () => new ReportResult(columns: [], rows: [], charts: [...$three, shapeChart('Fourth')]))
        ->toThrow(InvalidArgumentException::class, 'at most 3 charts');
})->group('phase10', 'reports');

it('keeps every one of the eight reports inside the budget', function () {
    // Part D §3 budgets three charts a screen. The cap above makes a fourth impossible; this
    // is the statement that none of the eight is sitting on the line by accident.
    foreach (ReportKey::cases() as $key) {
        $result = app(ReportService::class)->build(
            $key,
            $this->admin,
            ReportFilters::for($key, [
                'from' => Carbon::today()->subDays(45)->toDateString(),
                'to' => Carbon::today()->addDays(35)->toDateString(),
            ], Carbon::today()),
        );

        expect(count($result->charts))->toBeLessThanOrEqual(ReportResult::MAX_CHARTS);
    }
})->group('phase10', 'reports');

it('drops an empty chart and an empty footer from an empty report', function () {
    $encoded = json_decode(json_encode(new ReportResult(
        columns: [ReportColumn::text('a', 'A')],
        rows: [],
        totals: ['a' => 'Total'],
        charts: [shapeChart()],
        empty: 'Nothing here.',
    )), true);

    expect($encoded['totals'])->toBeNull()
        ->and($encoded['charts'])->toBe([])
        ->and($encoded['empty'])->toBe('Nothing here.');
})->group('phase10', 'reports');

/*
|--------------------------------------------------------------------------
| Columns, rows and the seven formats
|--------------------------------------------------------------------------
*/

it('keys every row and every total by a declared column or a declared href, in every report', function () {
    // A row is a map, not a tuple: a column added in the middle of a builder must not shift a
    // figure one place to the right.
    //
    // A row may carry **one extra kind of key**: the href of a linked column
    // (`ReportColumn::linkedBy()`, contract §3). It is a second flat key rather than a nested
    // `{value, href}` cell so that a row stays scalars, which is what lets `totals` share a
    // row's shape — and so the totals check below stays the strict one. Nothing else may ride
    // along: an href no column names is either a link nobody can click or a field that was
    // meant to be hidden, and both are worth failing over.
    foreach (ReportKey::cases() as $key) {
        $result = app(ReportService::class)->build(
            $key,
            $this->admin,
            ReportFilters::for($key, ['from' => '2026-09-01', 'to' => '2026-09-30'], Carbon::today()),
        );

        $declared = array_map(fn (ReportColumn $column): string => $column->key, $result->columns);

        $hrefKeys = array_values(array_unique(array_filter(array_map(
            fn (ReportColumn $column): ?string => $column->linkKey,
            $result->columns,
        ))));

        expect($declared)->not->toBe([]);

        // Every href key a column names must be spelled differently from every column key, or
        // a link would overwrite the value it is a link to.
        expect(array_intersect($declared, $hrefKeys))->toBe([]);

        foreach ($result->rows as $row) {
            expect(array_keys($row))->toEqualCanonicalizing([...$declared, ...$hrefKeys]);
        }

        if ($result->totals !== null && ! $result->isEmpty()) {
            expect(array_keys($result->totals))->toEqualCanonicalizing($declared);
        }

        expect($result->empty)->not->toBe('');
    }
})->group('phase10', 'reports');

it('aligns figures to the end and words to the start', function () {
    expect(ReportColumn::money('m', 'M')->align())->toBe('end')
        ->and(ReportColumn::minutes('m', 'M')->align())->toBe('end')
        ->and(ReportColumn::number('n', 'N')->align())->toBe('end')
        ->and(ReportColumn::percent('p', 'P')->align())->toBe('end')
        ->and(ReportColumn::text('t', 'T')->align())->toBe('start')
        ->and(ReportColumn::date('d', 'D')->align())->toBe('start')
        ->and(ReportColumn::status('s', 'S')->align())->toBe('start');

    // Seven formats and no eighth: a builder that wants one raises it rather than inventing it.
    expect(array_map(fn (ReportFormat $f): string => $f->value, ReportFormat::cases()))
        ->toBe(['text', 'number', 'money', 'minutes', 'date', 'percent', 'status']);
})->group('phase10', 'reports');

it('offers three chart kinds and no way to spell a fourth', function () {
    expect(ReportChart::bar('B', [])->kind)->toBe('bar')
        ->and(ReportChart::donut('D', [])->kind)->toBe('donut')
        ->and(ReportChart::area('A', [])->kind)->toBe('area');

    // The constructor is private, so `new ReportChart('pie', …)` is not a runtime bug to find
    // in staging — it does not compile.
    expect((new ReflectionClass(ReportChart::class))->getConstructor()->isPrivate())->toBeTrue();
})->group('phase10', 'reports');

/*
|--------------------------------------------------------------------------
| ReportFilters — the range, and what is dropped
|--------------------------------------------------------------------------
*/

it('defaults the range to the current calendar month', function () {
    $asOf = Carbon::parse('2026-09-17');
    $filters = ReportFilters::for(ReportKey::Task, [], $asOf);

    expect($filters->from->toDateString())->toBe('2026-09-01')
        ->and($filters->to->toDateString())->toBe('2026-09-30')
        ->and($filters->isWholeMonth())->toBeTrue();
})->group('phase10', 'reports');

it('drops every filter the report does not accept', function () {
    $input = ['from' => '2026-09-01', 'to' => '2026-09-30', 'employee' => 3, 'project' => 4, 'client' => 5];

    // The Finance report takes a date range and nothing else, so a hand-typed `?employee=3` is
    // gone before a query is written rather than carried and ignored.
    $finance = ReportFilters::for(ReportKey::Finance, $input);
    expect($finance->employeeId)->toBeNull()
        ->and($finance->projectId)->toBeNull()
        ->and($finance->clientId)->toBeNull();

    // Employee Work takes no client.
    expect(ReportFilters::for(ReportKey::EmployeeWork, $input)->clientId)->toBeNull();
    expect(ReportFilters::for(ReportKey::EmployeeWork, $input)->employeeId)->toBe(3);

    // And the Task report takes all four.
    $task = ReportFilters::for(ReportKey::Task, $input);
    expect([$task->employeeId, $task->projectId, $task->clientId])->toBe([3, 4, 5]);
})->group('phase10', 'reports');

it('refuses a date that only looks like one', function () {
    // Carbon rolls 2026-09-31 over into October; the round trip is what makes this strict, so
    // a bad date falls back to the default month rather than silently becoming another one.
    $filters = ReportFilters::for(ReportKey::Task, ['from' => '2026-09-31', 'to' => 'tomorrow'], Carbon::parse('2026-09-17'));

    expect($filters->from->toDateString())->toBe('2026-09-01')
        ->and($filters->to->toDateString())->toBe('2026-09-30');
})->group('phase10', 'reports');

it('names every calendar month a window touches', function () {
    $months = ReportFilters::for(ReportKey::Finance, ['from' => '2026-08-28', 'to' => '2026-10-02'])->months();

    expect(array_map(fn (Carbon $m): string => $m->toDateString(), $months))
        ->toBe(['2026-08-01', '2026-09-01', '2026-10-01']);

    // 3–8 September is one month, which is the case that would break a naive month-difference.
    expect(ReportFilters::for(ReportKey::Finance, ['from' => '2026-09-03', 'to' => '2026-09-08'])->months())
        ->toHaveCount(1);
})->group('phase10', 'reports');

/*
|--------------------------------------------------------------------------
| ReportRequest — the five keys, and no `exists:` rule on any of them
|--------------------------------------------------------------------------
*/

it('refuses a malformed date and a range that ends before it starts', function () {
    $this->actingAs($this->admin)
        ->get('/admin/reports/task?from=17-09-2026')
        ->assertSessionHasErrors(ReportFilter::FROM);

    $this->actingAs($this->admin)
        ->get('/admin/reports/task?from=2026-09-30&to=2026-09-01')
        ->assertSessionHasErrors(ReportFilter::TO);
})->group('phase10', 'reports');

it('ignores a key the contract does not have', function () {
    // "Anything not on the list is ignored, never passed through" (contract §2). A `?status=`
    // or a `?limit=` must not reach a query, so the answer has to be identical to the plain one.
    $totals = function (string $url): array {
        $captured = [];

        test()->actingAs($this->admin)
            ->get($url)
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use (&$captured): void {
                $captured = $page->toArray()['props']['result']['totals'];
            });

        return $captured;
    };

    expect($totals('/admin/reports/task?status=completed&limit=1&mine=1&order=net'))
        ->toBe($totals('/admin/reports/task'));
})->group('phase10', 'reports');

it('sends settings.currency with every report, because every money cell wears it', function () {
    // The setting, not a constant: a report that printed a hard-coded symbol would disagree
    // with the Finance screens the day somebody changes it.
    app(SettingsService::class)->set('currency', 'GBP', $this->admin);

    $this->actingAs($this->admin)
        ->get('/admin/reports/finance')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('currency', 'GBP'));
})->group('phase10', 'reports');
