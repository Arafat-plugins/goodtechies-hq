<?php

/*
|--------------------------------------------------------------------------
| What `db:seed --force` writes into a PRODUCTION database (brief 030)
|--------------------------------------------------------------------------
|
| deploy/install.sh seeds the VPS once, while `users` is empty. The demo data — invented
| clients, projects, tasks, recurring templates, meetings, finance rows, salaries, a payroll
| draft, attendance and tracked time — must not land there. DatabaseSeeder::seedsDemo() gates
| it: SEED_DEMO decides when set, and when it is not the answer is "everywhere except
| production", so local development and every other test seed exactly what they always did.
|
| Helpers are prefixed PRODSEED_ / prodSeed*, because Pest declares them globally (AGENTS.md).
|
*/

use App\Models\Client;
use App\Models\EmployeeSalary;
use App\Models\Expense;
use App\Models\FinanceCategory;
use App\Models\Holiday;
use App\Models\Income;
use App\Models\LeaveType;
use App\Models\Meeting;
use App\Models\PayrollPeriod;
use App\Models\Project;
use App\Models\RecurringTask;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;

/** Set SEED_DEMO the way a .env line would (null = absent), for env() to read. */
function prodSeedSetFlag(?string $value): void
{
    if ($value === null) {
        putenv('SEED_DEMO');
        unset($_ENV['SEED_DEMO'], $_SERVER['SEED_DEMO']);

        return;
    }

    putenv('SEED_DEMO='.$value);
    $_ENV['SEED_DEMO'] = $value;
    $_SERVER['SEED_DEMO'] = $value;
}

/**
 * Seed the way install.sh does, as if APP_ENV were production. The seeder is invoked directly:
 * `db:seed` in production asks for confirmation, which a test cannot give.
 */
function prodSeedAsProduction(): void
{
    app()->detectEnvironment(fn () => 'production');
    app(DatabaseSeeder::class)->setContainer(app())->__invoke();
}

afterEach(function () {
    prodSeedSetFlag(null);
    app()->detectEnvironment(fn () => 'testing');
});

it('seeds only reference data and the team in production', function () {
    prodSeedSetFlag(null);
    prodSeedAsProduction();

    // What production gets.
    expect(User::count())->toBe(5)
        ->and(Role::count())->toBeGreaterThan(0)
        ->and(Setting::count())->toBeGreaterThan(0)
        ->and(LeaveType::count())->toBe(6)
        ->and(Holiday::count())->toBeGreaterThan(0)
        ->and(FinanceCategory::count())->toBeGreaterThan(0);

    // What it does not.
    expect(Client::count())->toBe(0)
        ->and(Project::count())->toBe(0)
        ->and(Task::count())->toBe(0)
        ->and(RecurringTask::count())->toBe(0)
        ->and(Meeting::count())->toBe(0)
        ->and(Income::count())->toBe(0)
        ->and(Expense::count())->toBe(0)
        ->and(EmployeeSalary::count())->toBe(0)
        ->and(PayrollPeriod::count())->toBe(0)
        ->and(TimeEntry::count())->toBe(0);
});

it('keeps demo data out of production when SEED_DEMO=0, as the env template ships it', function () {
    prodSeedSetFlag('0');
    prodSeedAsProduction();

    expect(Project::count())->toBe(0)
        ->and(User::count())->toBe(5);
});

it('seeds the demo data in production only when SEED_DEMO=1 asks for it', function () {
    prodSeedSetFlag('1');
    prodSeedAsProduction();

    expect(Client::count())->toBeGreaterThan(0)
        ->and(Project::count())->toBeGreaterThan(0)
        ->and(Task::count())->toBeGreaterThan(0);
});

it('still seeds the demo data outside production when SEED_DEMO is absent', function () {
    prodSeedSetFlag(null);

    expect(app()->isProduction())->toBeFalse()
        ->and(DatabaseSeeder::seedsDemo())->toBeTrue();
});

it('reads SEED_DEMO as a boolean and falls back on the environment for anything else', function (?string $value, bool $production, bool $expected) {
    prodSeedSetFlag($value);
    app()->detectEnvironment(fn () => $production ? 'production' : 'testing');

    expect(DatabaseSeeder::seedsDemo())->toBe($expected);
})->with([
    'absent, production' => [null, true, false],
    'blank, production' => ['', true, false],
    '0, production' => ['0', true, false],
    'false, production' => ['false', true, false],
    '1, production' => ['1', true, true],
    'on, production' => ['on', true, true],
    'garbage, production' => ['maybe', true, false],
    '0, testing' => ['0', false, false],
    'absent, testing' => [null, false, true],
]);
