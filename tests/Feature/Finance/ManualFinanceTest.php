<?php

use App\Models\Client;
use App\Models\Income;
use App\Models\Project;
use App\Models\User;
use App\Support\BillingFrequency;
use App\Support\BillingType;
use App\Support\Priority;
use App\Support\ProjectStatus;
use App\Support\ProjectType;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Finance is entered by hand
|--------------------------------------------------------------------------
|
| Client spec: "Do not automatically generate income from projects, project
| completion, invoices, expenses, or other events." Creating a project with
| finance figures and completing it record no income, no project income run,
| and every project's old `auto_income` switch is off.
|
| Helpers are prefixed manualFin*, because Pest declares them globally.
|
*/

beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
});

it('creates no income when a project is created with finance figures', function () {
    $incomeBefore = Income::count();
    $runsBefore = DB::table('project_income_runs')->count();

    $this->actingAs($this->admin)->post(route('admin.projects.store'), [
        'client_id' => Client::query()->orderBy('id')->firstOrFail()->id,
        'name' => 'Manual — Hosting Retainer',
        'project_type' => ProjectType::Other->value,
        'billing_type' => BillingType::OneTime->value,
        'priority' => Priority::Medium->value,
        'start_date' => '2026-10-01',
        'deadline' => '2026-12-15',
        'finance' => [
            'price' => 5000,
            'recurring_amount' => 300,
            'billing_frequency' => BillingFrequency::Custom->value,
        ],
    ])->assertSessionHas('success');

    $project = Project::where('name', 'Manual — Hosting Retainer')->firstOrFail();

    expect((float) $project->finance->price)->toBe(5000.0)
        ->and(DB::table('project_finance')->where('project_id', $project->id)->value('auto_income'))->toBeFalse()
        ->and(Income::count())->toBe($incomeBefore)
        ->and(DB::table('project_income_runs')->count())->toBe($runsBefore);
})->group('finance');

it('records no income and no income run when a project is completed', function () {
    $this->actingAs($this->admin)->post(route('admin.projects.store'), [
        'client_id' => Client::query()->orderBy('id')->firstOrFail()->id,
        'name' => 'Manual — One-time Build',
        'project_type' => ProjectType::Other->value,
        'billing_type' => BillingType::OneTime->value,
        'priority' => Priority::Medium->value,
        'start_date' => '2026-10-01',
        'deadline' => '2026-12-15',
        'finance' => ['price' => 7500],
    ])->assertSessionHas('success');

    $project = Project::where('name', 'Manual — One-time Build')->firstOrFail();

    $incomeBefore = Income::count();
    $runsBefore = DB::table('project_income_runs')->count();

    $this->actingAs($this->admin)
        ->post(route('admin.projects.status', $project), ['status' => ProjectStatus::Completed->value])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($project->fresh()->status)->toBe(ProjectStatus::Completed)
        ->and(Income::count())->toBe($incomeBefore)
        ->and(Income::where('project_id', $project->id)->exists())->toBeFalse()
        ->and(DB::table('project_income_runs')->count())->toBe($runsBefore)
        ->and(DB::table('project_income_runs')->where('project_id', $project->id)->exists())->toBeFalse();
})->group('finance');

it('leaves every seeded project with automatic income switched off', function () {
    expect(DB::table('project_finance')->count())->toBeGreaterThan(0)
        ->and(DB::table('project_finance')->where('auto_income', true)->count())->toBe(0);
})->group('finance');
