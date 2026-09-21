<?php

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Project;
use App\Models\User;
use App\Support\AuditEvent;
use App\Support\BillingFrequency;
use App\Support\RoleName;
use Inertia\Testing\AssertableInertia as Assert;

/*
|--------------------------------------------------------------------------
| Admin project finance endpoint
|--------------------------------------------------------------------------
|
| Money is its own permission and its own audit trail: every price move lands
| in audit_logs with what it was and what it became.
|
*/

beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->project = Project::where('name', 'Heat Gap — SEO Retainer')->firstOrFail();
});

it('records one price change with its old and new value', function () {
    $this->actingAs($this->admin)
        ->put(route('admin.projects.finance.update', $this->project), [
            'price' => 1200,
            'recurring_amount' => 350,
            'billing_frequency' => BillingFrequency::Quarterly->value,
            'contract_terms' => 'Quarterly in advance.',
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $finance = $this->project->fresh()->finance;

    expect((float) $finance->price)->toBe(1200.00)
        ->and($finance->billing_frequency)->toBe(BillingFrequency::Quarterly->value);

    $audit = AuditLog::where('event', AuditEvent::ProjectPriceChanged->value)
        ->where('target_id', $this->project->id)
        ->get();

    expect($audit)->toHaveCount(1)
        // Only the field that moved is recorded; the recurring amount was already 350.
        ->and($audit->first()->old_value)->toBe(['price' => null])
        ->and($audit->first()->new_value)->toBe(['price' => '1200.00'])
        ->and($audit->first()->actor_id)->toBe($this->admin->id);
})->group('phase1');

it('writes no audit row when nothing about the money moved', function () {
    $this->actingAs($this->admin)
        ->put(route('admin.projects.finance.update', $this->project), ['recurring_amount' => 350])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(AuditLog::where('event', AuditEvent::ProjectPriceChanged->value)->count())->toBe(0);
})->group('phase1');

it('refuses a negative price', function () {
    $this->actingAs($this->admin)
        ->put(route('admin.projects.finance.update', $this->project), ['price' => -1])
        ->assertSessionHasErrors('price');

    expect($this->project->fresh()->finance->price)->toBeNull();
})->group('phase1');

it('closes the finance endpoint to a manager', function () {
    $manager = Employee::factory()->forRole(RoleName::MANAGER)->create()->user->fresh();

    $this->actingAs($manager)
        ->put(route('admin.projects.finance.update', $this->project), ['price' => 9999])
        ->assertForbidden();

    expect($this->project->fresh()->finance->price)->toBeNull();
})->group('phase1');

it('keeps the finance payload out of a project a role may not see money on', function () {
    $tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();

    $props = $this->actingAs($tapu)
        ->get(route('employee.projects.show', $this->project))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Employee/Projects/Show')
            ->missing('project.data.finance')
            ->missing('project.data.billing_type'),
        )
        ->inertiaPage()['props'];

    expect($props['project']['data'])->not->toHaveKey('finance')
        ->and($props['project']['data']['permissions']['can_view_finance'])->toBeFalse();
})->group('phase1');
