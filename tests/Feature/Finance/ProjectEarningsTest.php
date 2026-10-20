<?php

use App\Models\Expense;
use App\Models\Income;
use App\Models\Project;
use App\Models\ProjectFinance;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\SettingsService;
use App\Support\FinanceCategoryKind;
use App\Support\ProjectEarnings;
use App\Support\ProjectStatus;
use Database\Factories\ExpenseFactory;
use Database\Factories\IncomeFactory;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| Per-project earnings — expected vs received vs cost, per month
|--------------------------------------------------------------------------
|
| Months in 2027, far from the seeded books, with projects made here.
|
*/

/** A project with its finance row. */
function earnProject(array $finance, array $project = []): Project
{
    $model = Project::factory()->create($project + ['status' => ProjectStatus::Active]);

    ProjectFinance::factory()->for($model)->create($finance + [
        'price' => null,
        'recurring_amount' => null,
        'billing_frequency' => null,
        'hourly_rate' => null,
    ]);

    return $model;
}

/** The earnings row for one project in one month. */
function earnRow(string $month, Project $project): array
{
    $row = collect(ProjectEarnings::forMonth(Carbon::parse($month.'-01'))['rows'])
        ->firstWhere('project.id', $project->getKey());

    expect($row)->not->toBeNull();

    return $row;
}

beforeEach(function () {
    $this->seed();

    $this->accountant = User::where('email', 'accountant@goodtechies.test')->firstOrFail();

    // ProjectEarnings is one currency at a time (the default). The factories' rows carry the
    // column's default, BDT, so the default currency is BDT here too.
    app(SettingsService::class)->set('currency', 'BDT', User::where('email', 'shahadat@goodtechies.test')->firstOrFail());
});

it('expects a monthly amount every month and a quarterly one only in its due months', function () {
    $monthly = earnProject(
        ['recurring_amount' => '300.00', 'billing_frequency' => 'monthly'],
        ['start_date' => '2027-01-10'],
    );
    $quarterly = earnProject(
        ['recurring_amount' => '900.00', 'billing_frequency' => 'quarterly'],
        ['start_date' => '2027-01-01'],
    );

    $march = earnRow('2027-03', $monthly);

    expect($march['type'])->toBe('recurring')
        ->and($march['type_label'])->toBe('Recurring (Monthly)')
        ->and($march['expected'])->toBe('300.00');

    expect(earnRow('2027-01', $quarterly)['expected'])->toBe('900.00')
        ->and(earnRow('2027-04', $quarterly)['expected'])->toBe('900.00');

    $notDue = earnRow('2027-03', $quarterly);

    expect($notDue['expected'])->toBeNull()
        ->and($notDue['basis'])->toContain('Apr 2027')
        ->and($notDue['outstanding'])->toBe('0.00');
})->group('finance');

it('expects approved hours times the hourly rate, ignoring unapproved time', function () {
    $project = earnProject(['billing_frequency' => 'hourly', 'hourly_rate' => '25.00']);
    $task = Task::factory()->for($project)->create();

    // 10h + 2h30m approved = 12h30m.
    TimeEntry::factory()->onTask($task)->on(Carbon::parse('2027-03-04'))->create(['duration_seconds' => 36000]);
    TimeEntry::factory()->onTask($task)->on(Carbon::parse('2027-03-18'))->create(['duration_seconds' => 9000]);
    // Waiting for approval: does not count.
    TimeEntry::factory()->onTask($task)->on(Carbon::parse('2027-03-20'))->manual()->create(['duration_seconds' => 7200]);

    $row = earnRow('2027-03', $project);

    expect($row['type'])->toBe('hourly')
        ->and($row['hours'])->toBe('12.50')
        ->and($row['rate'])->toBe('25.00')
        ->and($row['expected'])->toBe('312.50');
})->group('finance');

it('leaves the hours out of the hourly row when not detailed', function () {
    $project = earnProject(['billing_frequency' => 'hourly', 'hourly_rate' => '25.00']);
    $task = Task::factory()->for($project)->create();

    TimeEntry::factory()->onTask($task)->on(Carbon::parse('2027-03-04'))->create(['duration_seconds' => 36000]);

    $row = collect(ProjectEarnings::forMonth(Carbon::parse('2027-03-01'), false)['rows'])
        ->firstWhere('project.id', $project->getKey());

    expect($row)->not->toBeNull()
        ->and($row['hours'])->toBeNull()
        ->and($row['basis'])->not->toContain('hours')
        ->and($row['basis'])->not->toContain('10.00')
        ->and($row['expected'])->toBe('250.00');
})->group('finance');

it('reports what a project received, what it cost and its profit', function () {
    $project = earnProject(
        ['recurring_amount' => '300.00', 'billing_frequency' => 'monthly'],
        ['start_date' => '2027-01-10'],
    );

    Income::factory()->recordedBy($this->accountant)->forProject($project)->of('200.00')->on('2027-03-05')->create([
        'category_id' => IncomeFactory::category(FinanceCategoryKind::Income, 'Maintenance'),
    ]);

    Expense::factory()->recordedBy($this->accountant)->of('50.00')->on('2027-03-06')->create([
        'category_id' => ExpenseFactory::category(FinanceCategoryKind::Expense, 'Hosting'),
        'project_id' => $project->getKey(),
        'billable' => true,
    ]);

    $row = earnRow('2027-03', $project);

    // Expenses stay in the books but are no longer a column here: no costs, no profit.
    expect($row['received'])->toBe('200.00')
        ->and($row)->not->toHaveKeys(['costs', 'billable_costs', 'profit'])
        ->and($row['outstanding'])->toBe('100.00');
})->group('finance');

it('compares a one-time price with everything received to date', function () {
    $project = earnProject(['price' => '1000.00']);
    $category = IncomeFactory::category(FinanceCategoryKind::Income, 'Website');

    Income::factory()->recordedBy($this->accountant)->forProject($project)->of('400.00')->on('2027-02-10')->create(['category_id' => $category]);
    Income::factory()->recordedBy($this->accountant)->forProject($project)->of('300.00')->on('2027-03-10')->create(['category_id' => $category]);

    $earnings = ProjectEarnings::forMonth(Carbon::parse('2027-03-01'));
    $contract = collect($earnings['contracts'])->firstWhere('project.id', $project->getKey());

    expect($contract)->not->toBeNull()
        ->and($contract['price'])->toBe('1000.00')
        ->and($contract['received_to_date'])->toBe('700.00')
        ->and($contract['balance'])->toBe('300.00')
        // D7: a BDT-priced contract's BDT figures are its own.
        ->and($contract['currency'])->toBe('BDT')
        ->and($contract['price_bdt'])->toBe('1000.00')
        ->and($contract['received_to_date_bdt'])->toBe('700.00')
        ->and($contract['balance_bdt'])->toBe('300.00');

    $row = collect($earnings['rows'])->firstWhere('project.id', $project->getKey());

    expect($row['type'])->toBe('one_time')
        ->and($row['expected'])->toBeNull()
        ->and($row['expected_bdt'])->toBeNull()
        ->and($row['received'])->toBe('300.00')
        ->and($row['received_bdt'])->toBe('300.00');
})->group('finance');

it('expects nothing from a cancelled recurring project', function () {
    $project = earnProject(
        ['recurring_amount' => '300.00', 'billing_frequency' => 'monthly'],
        ['start_date' => '2027-01-10', 'status' => ProjectStatus::Cancelled],
    );

    $row = earnRow('2027-03', $project);

    expect($row['type'])->toBe('recurring')
        ->and($row['expected'])->toBeNull()
        ->and($row['outstanding'])->toBe('0.00');
})->group('finance');
