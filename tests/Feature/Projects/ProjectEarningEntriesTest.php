<?php

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Project;
use App\Models\ProjectEarningEntry;
use App\Models\User;
use App\Support\AuditEvent;
use App\Support\ProjectStatus;
use App\Support\RoleName;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/*
|--------------------------------------------------------------------------
| Project financials (hand-recorded earnings entries)
|--------------------------------------------------------------------------
|
| Contract value, earned and received are kept on the project only: recording
| one creates no income, no expense and no payroll row, and totals are never
| added across currencies.
|
*/

beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->project = Project::where('name', 'Heat Gap — SEO Retainer')->firstOrFail();
});

function earning(array $overrides = []): array
{
    return array_merge([
        'kind' => 'contract_value',
        'amount' => '5000',
        'currency' => 'USD',
        'date' => '2026-10-01',
        'description' => 'Signed contract',
    ], $overrides);
}

it('records contract, earned and received entries without touching income or expenses', function () {
    $income = DB::table('income')->count();
    $expenses = DB::table('expenses')->count();

    foreach ([
        earning(),
        earning(['kind' => 'earned', 'amount' => '1200', 'description' => 'Milestone 1']),
        earning(['kind' => 'received', 'amount' => '1000', 'description' => '  First payment  ']),
        earning(['kind' => 'received', 'amount' => '20000', 'currency' => 'BDT', 'description' => null]),
    ] as $payload) {
        $this->actingAs($this->admin)
            ->post(route('admin.projects.earnings.store', $this->project), $payload)
            ->assertRedirect()
            ->assertSessionHas('success', 'Project earning recorded.');
    }

    $entries = ProjectEarningEntry::where('project_id', $this->project->id)->orderBy('id')->get();

    expect($entries)->toHaveCount(4)
        ->and($entries->map(fn ($e) => [$e->kind->value, (string) $e->amount, $e->currency])->all())->toBe([
            ['contract_value', '5000.00', 'USD'],
            ['earned', '1200.00', 'USD'],
            ['received', '1000.00', 'USD'],
            ['received', '20000.00', 'BDT'],
        ])
        ->and($entries[2]->description)->toBe('First payment')
        ->and($entries[3]->description)->toBeNull()
        ->and($entries[0]->recorded_by)->toBe($this->admin->id)
        ->and(DB::table('income')->count())->toBe($income)
        ->and(DB::table('expenses')->count())->toBe($expenses)
        ->and(AuditLog::where('event', AuditEvent::ProjectEarningRecorded->value)->count())->toBe(4);
});

it('totals per kind and per currency on the show page, never across currencies', function () {
    foreach ([
        earning(),
        earning(['kind' => 'earned', 'amount' => '1200']),
        earning(['kind' => 'received', 'amount' => '1000']),
        earning(['kind' => 'received', 'amount' => '20000', 'currency' => 'BDT']),
    ] as $payload) {
        $this->actingAs($this->admin)->post(route('admin.projects.earnings.store', $this->project), $payload);
    }

    $this->actingAs($this->admin)
        ->get(route('admin.projects.show', $this->project))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('earningEntries', 4)
            ->where('earningTotals', [
                ['currency' => 'BDT', 'contract_value' => '0.00', 'earned' => '0.00', 'received' => '20000.00'],
                ['currency' => 'USD', 'contract_value' => '5000.00', 'earned' => '1200.00', 'received' => '1000.00'],
            ])
            ->has('earningKinds', 3)
            ->where('canEditEarnings', true)
            ->etc());
});

it('updates an entry and records who edited it, then deletes it with an audit copy', function () {
    $this->actingAs($this->admin)->post(route('admin.projects.earnings.store', $this->project), earning());
    $entry = ProjectEarningEntry::firstOrFail();

    $this->actingAs($this->admin)
        ->put(route('admin.projects.earnings.update', [$this->project, $entry]), earning(['amount' => '5500.50']))
        ->assertRedirect()
        ->assertSessionHas('success', 'Project earning updated.');

    $entry->refresh();
    expect((string) $entry->amount)->toBe('5500.50')
        ->and($entry->updated_by)->toBe($this->admin->id)
        ->and(AuditLog::where('event', AuditEvent::ProjectEarningEdited->value)->firstOrFail()->old_value['amount'])->toBe('5000.00');

    $this->actingAs($this->admin)
        ->delete(route('admin.projects.earnings.destroy', [$this->project, $entry]))
        ->assertRedirect()
        ->assertSessionHas('success', 'Project earning deleted.');

    $audit = AuditLog::where('event', AuditEvent::ProjectEarningDeleted->value)->firstOrFail();

    expect(ProjectEarningEntry::count())->toBe(0)
        ->and($audit->old_value['amount'])->toBe('5500.50')
        ->and($audit->old_value['kind'])->toBe('contract_value')
        ->and($audit->old_value['currency'])->toBe('USD')
        ->and($audit->new_value)->toBeNull();
});

it('validates amount, currency and kind', function (array $override, string $field) {
    $this->actingAs($this->admin)
        ->post(route('admin.projects.earnings.store', $this->project), earning($override))
        ->assertSessionHasErrors($field);

    expect(ProjectEarningEntry::count())->toBe(0);
})->with([
    'zero amount' => [['amount' => '0'], 'amount'],
    'negative amount' => [['amount' => '-5'], 'amount'],
    'three decimals' => [['amount' => '10.123'], 'amount'],
    'unknown currency' => [['currency' => 'EUR'], 'currency'],
    'unknown kind' => [['kind' => 'invoiced'], 'kind'],
]);

it('closes all three endpoints to a user who can see the project but not its finances', function () {
    $this->actingAs($this->admin)->post(route('admin.projects.earnings.store', $this->project), earning());
    $entry = ProjectEarningEntry::firstOrFail();

    $manager = Employee::factory()->forRole(RoleName::MANAGER)->create()->user->fresh();

    // A manager may see every project (ProjectPolicy::view) but not its money.
    expect($manager->can('view', $this->project))->toBeTrue()
        ->and($manager->can('viewFinance', $this->project))->toBeFalse();

    $this->actingAs($manager)
        ->post(route('admin.projects.earnings.store', $this->project), earning())
        ->assertForbidden();
    $this->actingAs($manager)
        ->put(route('admin.projects.earnings.update', [$this->project, $entry]), earning(['amount' => '1']))
        ->assertForbidden();
    $this->actingAs($manager)
        ->delete(route('admin.projects.earnings.destroy', [$this->project, $entry]))
        ->assertForbidden();

    expect(ProjectEarningEntry::count())->toBe(1)
        ->and((string) $entry->fresh()->amount)->toBe('5000.00');
});

it('answers 404 for an entry from another project', function () {
    $this->actingAs($this->admin)->post(route('admin.projects.earnings.store', $this->project), earning());
    $entry = ProjectEarningEntry::firstOrFail();
    $other = Project::where('id', '!=', $this->project->id)->whereNull('archived_at')->firstOrFail();

    $this->actingAs($this->admin)
        ->put(route('admin.projects.earnings.update', [$other, $entry]), earning(['amount' => '1']))
        ->assertNotFound();
    $this->actingAs($this->admin)
        ->delete(route('admin.projects.earnings.destroy', [$other, $entry]))
        ->assertNotFound();

    expect(ProjectEarningEntry::count())->toBe(1);
});

it('refuses an archived project with an error flash and no row', function () {
    $this->actingAs($this->admin)
        ->post(route('admin.projects.archive', $this->project))
        ->assertRedirect();

    expect($this->project->fresh()->isArchived())->toBeTrue();

    $this->actingAs($this->admin)
        ->post(route('admin.projects.earnings.store', $this->project), earning())
        ->assertRedirect()
        ->assertSessionHas('error');

    expect(ProjectEarningEntry::count())->toBe(0);
});

it('creates no earning entry and no income when the project is completed', function () {
    $income = DB::table('income')->count();

    $this->actingAs($this->admin)
        ->post(route('admin.projects.status', $this->project), ['status' => ProjectStatus::Completed->value])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($this->project->fresh()->status)->toBe(ProjectStatus::Completed)
        ->and(ProjectEarningEntry::count())->toBe(0)
        ->and(DB::table('income')->count())->toBe($income);
});

it('saves the project finance currency and shows it in the payload', function () {
    $this->actingAs($this->admin)
        ->put(route('admin.projects.finance.update', $this->project), ['price' => 1500, 'currency' => 'USD'])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($this->project->fresh()->finance->currency)->toBe('USD');

    $this->actingAs($this->admin)
        ->get(route('admin.projects.show', $this->project))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('project.data.finance.currency', 'USD')
            ->where('defaultCurrency', 'USD')
            ->etc());

    $this->actingAs($this->admin)
        ->put(route('admin.projects.finance.update', $this->project), ['currency' => 'EUR'])
        ->assertSessionHasErrors('currency');
});
