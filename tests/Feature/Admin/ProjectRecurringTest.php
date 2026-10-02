<?php

use App\Http\Resources\ProjectResource;
use App\Models\Employee;
use App\Models\Project;
use App\Models\User;
use App\Support\BillingType;
use App\Support\Priority;
use App\Support\ProjectRecurrenceFrequency;
use App\Support\ProjectType;
use App\Support\RoleName;
use Illuminate\Http\Request;

/*
|--------------------------------------------------------------------------
| Creating a project, and Recurring billing
|--------------------------------------------------------------------------
|
| Billing type is One-Time or Recurring. A Recurring project needs a frequency and a start date,
| and its deadline is computed by the server from them — whatever deadline the client sent.
|
*/

beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function recurringProjectPayload(array $overrides = []): array
{
    return [
        'client_id' => null,
        'name' => 'Recurring Test Project',
        'project_type' => ProjectType::Seo->value,
        'billing_type' => BillingType::Recurring->value,
        'recurrence_frequency' => ProjectRecurrenceFrequency::Monthly->value,
        'priority' => Priority::Medium->value,
        'start_date' => '2026-10-05',
        'deadline' => null,
        ...$overrides,
    ];
}

it('creates a one-time project with a numeric price and redirects to its page', function () {
    $response = $this->actingAs($this->admin)->post(route('admin.projects.store'), recurringProjectPayload([
        'name' => 'One-Time With Price',
        'billing_type' => BillingType::OneTime->value,
        'recurrence_frequency' => null,
        'deadline' => '2026-12-01',
        'finance' => ['price' => 4500],
    ]));

    $project = Project::where('name', 'One-Time With Price')->firstOrFail();

    $response->assertStatus(302)
        ->assertRedirect(route('admin.projects.show', $project))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success');

    expect($project->billing_type)->toBe(BillingType::OneTime)
        ->and($project->recurrence_frequency)->toBeNull()
        ->and($project->deadline?->toDateString())->toBe('2026-12-01')
        ->and((string) $project->finance?->price)->toBe('4500.00');
})->group('projects-recurring');

it('computes the deadline from the start date for each frequency', function (string $frequency, string $deadline) {
    $this->actingAs($this->admin)
        ->post(route('admin.projects.store'), recurringProjectPayload([
            'name' => "Recurring {$frequency}",
            'recurrence_frequency' => $frequency,
        ]))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $project = Project::where('name', "Recurring {$frequency}")->firstOrFail();

    expect($project->billing_type)->toBe(BillingType::Recurring)
        ->and($project->recurrence_frequency?->value)->toBe($frequency)
        ->and($project->deadline?->toDateString())->toBe($deadline);
})->with([
    'daily' => ['daily', '2026-10-06'],
    'weekly' => ['weekly', '2026-10-12'],
    'biweekly' => ['biweekly', '2026-10-19'],
    'monthly' => ['monthly', '2026-11-05'],
])->group('projects-recurring');

it('ignores a deadline the client sent for a recurring project', function () {
    $this->actingAs($this->admin)
        ->post(route('admin.projects.store'), recurringProjectPayload([
            'name' => 'Recurring Wrong Deadline',
            'recurrence_frequency' => ProjectRecurrenceFrequency::Weekly->value,
            'deadline' => '2026-10-01',
        ]))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(Project::where('name', 'Recurring Wrong Deadline')->firstOrFail()->deadline?->toDateString())
        ->toBe('2026-10-12');
})->group('projects-recurring');

it('clamps a monthly deadline to the end of a shorter month, leap years included', function (string $start, string $deadline) {
    $this->actingAs($this->admin)
        ->post(route('admin.projects.store'), recurringProjectPayload([
            'name' => "Month end {$start}",
            'start_date' => $start,
        ]))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(Project::where('name', "Month end {$start}")->firstOrFail()->deadline?->toDateString())->toBe($deadline);
})->with([
    'common year' => ['2027-01-31', '2027-02-28'],
    'leap year' => ['2028-01-31', '2028-02-29'],
])->group('projects-recurring');

it('requires a frequency for a recurring project', function () {
    $this->actingAs($this->admin)
        ->post(route('admin.projects.store'), recurringProjectPayload(['recurrence_frequency' => null]))
        ->assertSessionHasErrors(['recurrence_frequency' => 'Choose how often it recurs.']);

    expect(Project::where('name', 'Recurring Test Project')->exists())->toBeFalse();
})->group('projects-recurring');

it('requires a start date for a recurring project', function () {
    $this->actingAs($this->admin)
        ->post(route('admin.projects.store'), recurringProjectPayload(['start_date' => null]))
        ->assertSessionHasErrors(['start_date' => 'A recurring project needs a start date.']);
})->group('projects-recurring');

it('rejects the retired monthly_recurring billing type', function () {
    $this->actingAs($this->admin)
        ->post(route('admin.projects.store'), recurringProjectPayload(['billing_type' => 'monthly_recurring']))
        ->assertSessionHasErrors('billing_type');
})->group('projects-recurring');

it('clears the frequency and keeps the given deadline when a project becomes one-time', function () {
    $this->actingAs($this->admin)
        ->post(route('admin.projects.store'), recurringProjectPayload())
        ->assertSessionHasNoErrors();

    $project = Project::where('name', 'Recurring Test Project')->firstOrFail();

    $this->actingAs($this->admin)
        ->put(route('admin.projects.update', $project), recurringProjectPayload([
            'billing_type' => BillingType::OneTime->value,
            'recurrence_frequency' => ProjectRecurrenceFrequency::Weekly->value,
            'deadline' => '2026-12-24',
        ]))
        ->assertRedirect()
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success');

    $project->refresh();

    expect($project->billing_type)->toBe(BillingType::OneTime)
        ->and($project->recurrence_frequency)->toBeNull()
        ->and($project->deadline?->toDateString())->toBe('2026-12-24');
})->group('projects-recurring');

it('keeps the recurrence frequency out of an employee\'s project payload', function () {
    $user = User::factory()->create();
    $employee = Employee::factory()->for($user)->forRole(RoleName::EMPLOYEE)->create();

    $project = Project::factory()->create([
        'billing_type' => BillingType::Recurring,
        'recurrence_frequency' => ProjectRecurrenceFrequency::Weekly,
    ]);
    $project->members()->attach($employee->id, ['role_on_project' => 'developer']);

    $request = Request::create('/');
    $request->setUserResolver(fn () => $user->fresh());

    $payload = (new ProjectResource($project->fresh()))->toArray($request);

    expect($payload)->not->toHaveKey('recurrence_frequency')
        ->and($payload)->not->toHaveKey('recurrence_frequency_label')
        ->and($payload)->not->toHaveKey('billing_type');

    $adminRequest = Request::create('/');
    $adminRequest->setUserResolver(fn () => $this->admin);

    expect((new ProjectResource($project->fresh()))->toArray($adminRequest))
        ->toHaveKey('recurrence_frequency', 'weekly')
        ->toHaveKey('recurrence_frequency_label', 'Weekly');
})->group('projects-recurring');
