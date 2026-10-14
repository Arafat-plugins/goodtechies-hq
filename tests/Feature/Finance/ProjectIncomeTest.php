<?php

use App\Models\FinanceCategory;
use App\Models\Income;
use App\Models\PayrollPeriod;
use App\Models\Project;
use App\Models\ProjectFinance;
use App\Models\ProjectIncomeRun;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\FinanceService;
use App\Services\ProjectIncomeService;
use App\Services\ProjectService;
use App\Support\FinanceCategoryKind;
use App\Support\ProjectStatus;
use App\Support\ProjectType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Project income — recorded automatically from each project's billing
|--------------------------------------------------------------------------
|
| Recurring amounts on due dates, approved hours × rate once a month, the
| one-time price on completion. No back-fill before `auto_income_from`, one
| run per billed period (so a deleted auto income is never recreated), locked
| months skipped. Hand-entered income keeps working beside it.
|
| Months in 2027, far from the seeded books. Helpers are prefixed pinc*,
| because Pest declares them globally across the whole suite (AGENTS.md).
|
*/

/** A project with its finance row, billing automatically from `auto_income_from`. */
function pincProject(array $finance, array $project = []): Project
{
    $model = Project::factory()->create($project + ['status' => ProjectStatus::Active]);

    ProjectFinance::factory()->for($model)->create($finance + [
        'price' => null,
        'recurring_amount' => null,
        'billing_frequency' => null,
        'hourly_rate' => null,
        'auto_income' => true,
        'auto_income_from' => '2027-03-01',
    ]);

    return $model;
}

/** The auto income rows of one project, oldest first. */
function pincIncomes(Project $project)
{
    return Income::query()
        ->where('project_id', $project->getKey())
        ->where('source', 'auto')
        ->orderBy('date')
        ->get();
}

function pincGenerate(string $today): int
{
    test()->travelTo(Carbon::parse($today.' 09:00'));

    return app(ProjectIncomeService::class)->generate(Carbon::parse($today));
}

beforeEach(function () {
    $this->seed();

    // Only the projects made in these tests bill automatically.
    ProjectFinance::query()->update(['auto_income' => false]);

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->accountant = User::where('email', 'accountant@goodtechies.test')->firstOrFail();
    $this->pmUser = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();
});

it('records the recurring amount on each due date from auto_income_from, never before', function () {
    $project = pincProject(
        ['recurring_amount' => '300.00', 'billing_frequency' => 'monthly'],
        [
            'start_date' => '2027-01-10',
            'project_type' => ProjectType::WebsiteMaintenance,
            'pm_id' => $this->pmUser->employee->getKey(),
        ],
    );

    $recorded = pincGenerate('2027-04-15');

    $rows = pincIncomes($project);
    $maintenance = FinanceCategory::query()->ofKind(FinanceCategoryKind::Income)->where('name', 'Maintenance')->value('id');

    expect($recorded)->toBe(2)
        ->and($rows->map(fn (Income $row) => $row->date->toDateString())->all())->toBe(['2027-03-10', '2027-04-10'])
        ->and(Income::where('project_id', $project->getKey())->count())->toBe(2);

    foreach ($rows as $row) {
        expect((string) $row->amount)->toBe('300.00')
            ->and($row->source)->toBe('auto')
            ->and((int) $row->category_id)->toBe((int) $maintenance)
            ->and((int) $row->recorded_by)->toBe((int) $this->pmUser->getKey());
    }
})->group('finance');

it('records nothing new on a second run and never recreates a deleted auto income', function () {
    $project = pincProject(
        ['recurring_amount' => '300.00', 'billing_frequency' => 'monthly'],
        ['start_date' => '2027-01-10'],
    );

    expect(pincGenerate('2027-04-15'))->toBe(2)
        ->and(pincGenerate('2027-04-15'))->toBe(0)
        ->and(pincIncomes($project))->toHaveCount(2);

    pincIncomes($project)->first()->delete();

    expect(pincGenerate('2027-04-16'))->toBe(0)
        ->and(pincIncomes($project))->toHaveCount(1)
        ->and(ProjectIncomeRun::where('project_id', $project->getKey())->count())->toBe(2);
})->group('finance');

it('bills approved hours times the rate as soon as the month ends, dated its last day', function () {
    $project = pincProject(['billing_frequency' => 'hourly', 'hourly_rate' => '25.00']);
    $task = Task::factory()->for($project)->create();

    // 10h + 2h30m approved = 12h30m.
    TimeEntry::factory()->onTask($task)->on(Carbon::parse('2027-03-04'))->create(['duration_seconds' => 36000]);
    TimeEntry::factory()->onTask($task)->on(Carbon::parse('2027-03-18'))->create(['duration_seconds' => 9000]);
    // Waiting for approval: does not count.
    TimeEntry::factory()->onTask($task)->on(Carbon::parse('2027-03-20'))->manual()->create(['duration_seconds' => 7200]);

    pincGenerate('2027-03-31');

    expect(pincIncomes($project))->toHaveCount(0);

    pincGenerate('2027-04-01');

    $rows = pincIncomes($project);

    expect($rows)->toHaveCount(1)
        ->and((string) $rows->first()->amount)->toBe('312.50')
        ->and($rows->first()->date->toDateString())->toBe('2027-03-31')
        ->and($rows->first()->notes)->toContain('12.50');
})->group('finance');

it('records nothing for a project with automatic income switched off', function () {
    $project = pincProject(
        ['recurring_amount' => '300.00', 'billing_frequency' => 'monthly', 'auto_income' => false],
        ['start_date' => '2027-01-10'],
    );

    expect(pincGenerate('2027-04-15'))->toBe(0)
        ->and(Income::where('project_id', $project->getKey())->count())->toBe(0);
})->group('finance');

it('records the one-time price once, when the project is completed', function () {
    $project = pincProject(['price' => '1000.00']);
    $projects = app(ProjectService::class);

    $this->travelTo(Carbon::parse('2027-06-10 09:00'));

    $project = $projects->changeStatus($this->admin, $project, ProjectStatus::Completed);

    $rows = pincIncomes($project);

    expect($rows)->toHaveCount(1)
        ->and((string) $rows->first()->amount)->toBe('1000.00')
        ->and($rows->first()->date->toDateString())->toBe('2027-06-10');

    $project = $projects->changeStatus($this->admin, $project, ProjectStatus::Active);
    $projects->changeStatus($this->admin, $project, ProjectStatus::Completed);

    expect(pincIncomes($project))->toHaveCount(1);
})->group('finance');

it('skips a due date in a locked month and logs it', function () {
    $project = pincProject(
        ['recurring_amount' => '300.00', 'billing_frequency' => 'monthly'],
        ['start_date' => '2027-03-10'],
    );

    $period = PayrollPeriod::factory()->forMonth('2027-03-01')->create();

    DB::table('payroll_periods')->where('id', $period->getKey())->update([
        'status' => 'locked',
        'locked_at' => Carbon::now(),
    ]);

    expect(pincGenerate('2027-03-20'))->toBe(0)
        ->and(Income::where('project_id', $project->getKey())->count())->toBe(0);

    $run = ProjectIncomeRun::where('project_id', $project->getKey())->firstOrFail();

    expect($run->outcome)->toBe('skipped_locked')
        ->and($run->income_id)->toBeNull()
        ->and($run->period->toDateString())->toBe('2027-03-10');
})->group('finance');

it('still records hand-entered income, as manual', function () {
    $category = FinanceCategory::query()->ofKind(FinanceCategoryKind::Income)->inOrder()->value('id');

    $income = app(FinanceService::class)->recordIncome($this->accountant, [
        'category_id' => $category,
        'amount' => '150.00',
        'date' => '2027-05-10',
        'notes' => 'Extra payment',
    ]);

    expect($income->fresh()->source)->toBe('manual');
})->group('finance');
