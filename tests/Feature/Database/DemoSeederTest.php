<?php

use App\Models\Client;
use App\Models\Employee;
use App\Models\Project;
use App\Models\ProjectFinance;
use App\Support\Priority;
use App\Support\ProjectStatus;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->seed();
});

it('seeds the demo clients and projects', function () {
    expect(Client::count())->toBe(4)
        ->and(Project::count())->toBe(7);
})->group('phase1');

it('makes Tapu a member of exactly the two SEO projects', function () {
    $tapu = Employee::where('employee_number', 'GT-003')->firstOrFail();

    $names = $tapu->projects()->pluck('name')->sort()->values()->all();

    expect($names)->toBe([
        'Buffalo Modular — SEO',
        'Heat Gap — SEO Retainer',
    ]);
})->group('phase1');

it('makes Yaseen a member of the three maintenance projects plus Internal', function () {
    $yaseen = Employee::where('employee_number', 'GT-004')->firstOrFail();

    $names = $yaseen->projects()->pluck('name')->sort()->values()->all();

    expect($names)->toBe([
        'APH — Website Maintenance',
        'Buffalo Modular — Website Maintenance',
        'GoodTechies HQ — Internal',
        'abc.com — Monthly Maintenance',
    ]);
})->group('phase1');

it('gives the internal project no client and no finance row', function () {
    $internal = Project::where('name', 'GoodTechies HQ — Internal')->firstOrFail();

    expect($internal->client_id)->toBeNull()
        ->and($internal->finance)->toBeNull();
})->group('phase1');

it('gives every non-internal project a finance row with a price or a recurring amount', function () {
    $nonInternal = Project::where('name', '!=', 'GoodTechies HQ — Internal')->get();

    expect($nonInternal)->toHaveCount(6);

    foreach ($nonInternal as $project) {
        $finance = ProjectFinance::where('project_id', $project->id)->first();

        expect($finance)->not->toBeNull("Project [{$project->name}] has no finance row.")
            ->and($finance->price !== null || $finance->recurring_amount !== null)->toBeTrue();
    }
})->group('phase1');

it('puts exactly one project on hold and none archived or cancelled', function () {
    expect(Project::where('status', ProjectStatus::OnHold->value)->count())->toBe(1)
        ->and(Project::where('status', ProjectStatus::OnHold->value)->value('name'))->toBe('APH — Website Maintenance')
        ->and(Project::where('status', ProjectStatus::Archived->value)->count())->toBe(0)
        ->and(Project::where('status', ProjectStatus::Cancelled->value)->count())->toBe(0);
})->group('phase1');

it('gives exactly one project a null deadline (the rolling retainer)', function () {
    expect(Project::whereNull('deadline')->count())->toBe(1)
        ->and(Project::whereNull('deadline')->value('name'))->toBe('Buffalo Modular — SEO');
})->group('phase1');

it('gives exactly one open project a deadline in the past', function () {
    $overdue = Project::where('deadline', '<', Carbon::today())->get();

    expect($overdue)->toHaveCount(1);

    $project = $overdue->first();

    expect($project->name)->toBe('APH — Website Maintenance')
        ->and($project->status->isOpen())->toBeTrue();
})->group('phase1');

it('gives the priority column at least one high and one urgent project', function () {
    expect(Project::where('priority', Priority::High->value)->count())->toBeGreaterThanOrEqual(1)
        ->and(Project::where('priority', Priority::Urgent->value)->count())->toBeGreaterThanOrEqual(1);
})->group('phase1');

it('keeps the counts stable when seeding twice', function () {
    $before = [
        'clients' => Client::count(),
        'projects' => Project::count(),
        'project_finance' => ProjectFinance::count(),
    ];

    $this->seed();

    expect([
        'clients' => Client::count(),
        'projects' => Project::count(),
        'project_finance' => ProjectFinance::count(),
    ])->toBe($before);
})->group('phase1');
