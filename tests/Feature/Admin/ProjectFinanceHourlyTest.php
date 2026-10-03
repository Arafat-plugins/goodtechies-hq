<?php

use App\Http\Resources\AccountantProjectResource;
use App\Http\Resources\ProjectResource;
use App\Models\Client;
use App\Models\Employee;
use App\Models\Project;
use App\Models\User;
use App\Support\BillingFrequency;
use App\Support\BillingType;
use App\Support\Priority;
use App\Support\ProjectType;
use App\Support\RoleName;
use Illuminate\Http\Request;
use Inertia\Testing\AssertableInertia as Assert;

/*
|--------------------------------------------------------------------------
| Hourly billing on a project's finance
|--------------------------------------------------------------------------
|
| `billing_frequency = hourly` carries an `hourly_rate`, required with it. The rate is money, so
| it travels under exactly the visibility of `price`: Admins and the Accountant see it, an
| Employee never does, at any depth.
|
| Helpers are prefixed hourly*, because Pest declares them globally across the suite.
|
*/

beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->project = Project::where('name', 'Buffalo Modular — SEO')->firstOrFail();
});

/**
 * Every key in a nested array, at any depth.
 *
 * @param  array<mixed>  $payload
 * @return list<string>
 */
function hourlyKeysAnywhere(array $payload): array
{
    $keys = [];

    foreach ($payload as $key => $value) {
        if (is_string($key)) {
            $keys[] = $key;
        }

        if (is_array($value)) {
            $keys = [...$keys, ...hourlyKeysAnywhere($value)];
        }
    }

    return $keys;
}

/**
 * @return array<string, mixed>
 */
function hourlyResourcePayload(object $resource, User $user): array
{
    $request = Request::create('/');
    $request->setUserResolver(fn () => $user);

    return json_decode(json_encode($resource->toArray($request), JSON_THROW_ON_ERROR), true);
}

it('saves an hourly rate through the finance endpoint and serialises it', function () {
    $this->actingAs($this->admin)
        ->put(route('admin.projects.finance.update', $this->project), [
            'billing_frequency' => BillingFrequency::Hourly->value,
            'hourly_rate' => 40,
        ])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success');

    $finance = $this->project->fresh()->finance;

    expect($finance->billing_frequency)->toBe('hourly')
        ->and($finance->hourly_rate)->toBe('40.00');

    $payload = hourlyResourcePayload(new ProjectResource($this->project->fresh()->load('finance')), $this->admin);

    expect($payload['finance']['hourly_rate'])->toBe('40.00')
        ->and($payload['finance']['billing_frequency_label'])->toBe('Hourly');
})->group('phase1');

it('requires the rate when the frequency is hourly', function () {
    $this->actingAs($this->admin)
        ->put(route('admin.projects.finance.update', $this->project), [
            'billing_frequency' => BillingFrequency::Hourly->value,
            'hourly_rate' => null,
        ])
        ->assertSessionHasErrors(['hourly_rate' => 'Enter the hourly rate.']);
})->group('phase1');

it('refuses a negative hourly rate', function () {
    $this->actingAs($this->admin)
        ->put(route('admin.projects.finance.update', $this->project), [
            'billing_frequency' => BillingFrequency::Hourly->value,
            'hourly_rate' => -5,
        ])
        ->assertSessionHasErrors('hourly_rate');
})->group('phase1');

it('answers 422 on hourly_rate for a JSON request without one', function () {
    $this->actingAs($this->admin)
        ->putJson(route('admin.projects.finance.update', $this->project), [
            'billing_frequency' => BillingFrequency::Hourly->value,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['hourly_rate' => 'Enter the hourly rate.']);
})->group('phase1');

it('creates a project billed hourly in one request', function () {
    $client = Client::where('name', 'ABC Ltd')->firstOrFail();

    $this->actingAs($this->admin)->post(route('admin.projects.store'), [
        'client_id' => $client->id,
        'name' => 'ABC — Ads',
        'project_type' => ProjectType::Seo->value,
        'billing_type' => BillingType::OneTime->value,
        'priority' => Priority::Medium->value,
        'finance' => [
            'billing_frequency' => BillingFrequency::Hourly->value,
            'hourly_rate' => 55.5,
        ],
    ])->assertSessionHasNoErrors();

    $finance = Project::where('name', 'ABC — Ads')->firstOrFail()->finance;

    expect($finance->billing_frequency)->toBe('hourly')
        ->and($finance->hourly_rate)->toBe('55.50');
})->group('phase1');

it('asks for the rate on create under the finance prefix', function () {
    $client = Client::where('name', 'ABC Ltd')->firstOrFail();

    $this->actingAs($this->admin)->post(route('admin.projects.store'), [
        'client_id' => $client->id,
        'name' => 'ABC — Ads',
        'project_type' => ProjectType::Seo->value,
        'billing_type' => BillingType::OneTime->value,
        'priority' => Priority::Medium->value,
        'finance' => ['billing_frequency' => BillingFrequency::Hourly->value],
    ])->assertSessionHasErrors(['finance.hourly_rate' => 'Enter the hourly rate.']);
})->group('phase1');

it('gives the edit page the frequencies, hourly included, and the project finance', function () {
    $this->project->finance()->updateOrCreate([], [
        'billing_frequency' => BillingFrequency::Hourly->value,
        'hourly_rate' => '40.00',
    ]);

    $this->actingAs($this->admin)
        ->get(route('admin.projects.edit', $this->project))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Projects/Edit')
            ->has('billingFrequencies', count(BillingFrequency::cases()))
            ->where('billingFrequencies', fn ($options) => collect($options)->contains('value', 'hourly'))
            ->where('project.data.finance.hourly_rate', '40.00')
            ->where('project.data.permissions.can_view_finance', true),
        );
})->group('phase1');

it('never gives an employee the hourly rate, at any depth', function () {
    $this->project->finance()->updateOrCreate([], [
        'billing_frequency' => BillingFrequency::Hourly->value,
        'hourly_rate' => '40.00',
    ]);

    $user = User::factory()->create();
    $employee = Employee::factory()->for($user)->forRole(RoleName::EMPLOYEE)->create();
    $this->project->members()->attach($employee->id, ['role_on_project' => 'seo']);

    $project = Project::with(['client', 'finance', 'members.user', 'pm.user'])->findOrFail($this->project->id);
    $payload = hourlyResourcePayload(new ProjectResource($project), $user->fresh());

    expect(hourlyKeysAnywhere($payload))->not->toContain('hourly_rate')
        ->and(hourlyKeysAnywhere($payload))->not->toContain('finance');
})->group('phase1');

it('gives the accountant resource the hourly rate', function () {
    $this->project->finance()->updateOrCreate([], [
        'billing_frequency' => BillingFrequency::Hourly->value,
        'hourly_rate' => '40.00',
    ]);

    $accountant = User::where('email', 'accountant@goodtechies.test')->firstOrFail();
    $payload = hourlyResourcePayload(new AccountantProjectResource($this->project->fresh()->load('finance')), $accountant);

    expect($payload['finance']['hourly_rate'])->toBe('40.00')
        ->and($payload['finance']['billing_frequency'])->toBe('hourly');
})->group('phase1');
