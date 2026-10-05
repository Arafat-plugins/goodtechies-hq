<?php

use App\Models\Employee;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\ActivityService;
use App\Support\TrackingMode;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

/*
| The two activity day pages (Phase 11): the employee's own "My activity" and the Admin's
| activity page. Remote timer users only on the employee side; domains and states only, never a
| URL, a title, a score or a rank.
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-09-24 12:00:00');

    // The full seed, for the seeded Admin with a confirmed second factor (`surface:admin` sits
    // behind `two-factor`). Seeded time is cleared so every figure here is the test's own.
    $this->seed();
    TimeEntry::query()->delete();

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();
    $this->yaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();
    $this->accountant = User::where('email', 'accountant@goodtechies.test')->firstOrFail();

    $project = Project::factory()->create(['name' => 'Activity pages project']);
    $this->task = Task::factory()->create(['project_id' => $project->id, 'title' => 'Activity pages task']);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/** Every key anywhere in a payload. */
function EXT_PAGES_keys(mixed $node): array
{
    if (! is_array($node)) {
        return [];
    }

    $keys = [];

    foreach ($node as $key => $value) {
        if (is_string($key)) {
            $keys[] = $key;
        }

        $keys = [...$keys, ...EXT_PAGES_keys($value)];
    }

    return $keys;
}

/** A stopped entry of Tapu's, 09:00–09:10, with ten minutes of samples and three kinds of site. */
function EXT_PAGES_sampledEntry(User $tapu, Task $task): TimeEntry
{
    $entry = TimeEntry::factory()->forEmployee($tapu->employee)->onTask($task)->create([
        'work_date' => '2026-09-24',
        'started_at' => Carbon::parse('2026-09-24 09:00:00'),
        'ended_at' => Carbon::parse('2026-09-24 09:10:00'),
        'duration_seconds' => 600,
    ]);

    $states = ['active', 'active', 'active', 'active', 'active', 'active', 'media', 'call', 'idle', 'idle'];
    $samples = [];

    foreach ($states as $i => $state) {
        $sites = match ($i) {
            0, 1, 2 => [['kind' => 'site', 'host' => 'docs.google.com', 'seconds' => 60]],
            3 => [['kind' => 'site', 'host' => 'github.com', 'seconds' => 60]],
            4 => [['kind' => 'other_app', 'host' => '', 'seconds' => 60]],
            default => [],
        };

        $samples[] = [
            'minute' => Carbon::parse('2026-09-24 09:00:00')->addMinutes($i)->toIso8601String(),
            'state' => $state,
            'call_source' => $state === 'call' ? 'detected' : null,
            'sites' => $sites,
        ];
    }

    $service = app(ActivityService::class);
    $service->storeSamples($entry, $samples, ActivityService::SOURCE_EXTENSION);
    $service->rollup($entry->refresh());

    return $entry->refresh();
}

it('shows Tapu his own activity day with sessions, summary, sites and legend', function (): void {
    EXT_PAGES_sampledEntry($this->tapu, $this->task);

    $this->actingAs($this->tapu)->get('/employee/time/activity')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Employee/Time/Activity')
            ->has('activity.sessions', 1)
            ->has('activity.summary')
            ->has('activity.sites')
            ->has('activity.legend', 4)
            ->where('activity.employee.id', $this->tapu->employee->id)
            ->has('timer'));
});

it('refuses an office employee and the Accountant', function (): void {
    $this->actingAs($this->yaseen)->get('/employee/time/activity')->assertForbidden();
    $this->actingAs($this->accountant)->get('/employee/time/activity')->assertForbidden();
});

it('shows the Admin Tapu\'s day, and the default route lands on a remote employee', function (): void {
    $this->actingAs($this->admin)->get('/admin/time/activity/'.$this->tapu->employee->id)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Time/Activity')
            ->where('activity.employee.id', $this->tapu->employee->id)
            ->has('employees'));

    $page = $this->actingAs($this->admin)->get('/admin/time/activity')->assertOk()->viewData('page');
    $landed = Employee::query()->findOrFail($page['props']['activity']['employee']['id']);

    expect($landed->tracking_mode)->toBe(TrackingMode::RemoteTimer);
});

it('sends no url, title, hostname, score or productivity key', function (): void {
    EXT_PAGES_sampledEntry($this->tapu, $this->task);

    foreach ([
        [$this->tapu, '/employee/time/activity'],
        [$this->admin, '/admin/time/activity/'.$this->tapu->employee->id],
    ] as [$user, $url]) {
        $page = $this->actingAs($user)->get($url)->assertOk()->viewData('page');
        $keys = EXT_PAGES_keys($page['props']['activity']);

        expect(array_intersect($keys, ['url', 'title', 'hostname', 'score', 'productivity']))->toBe([]);
    }
});

it('turns a day of samples into minutes, an idle share and a site share', function (): void {
    EXT_PAGES_sampledEntry($this->tapu, $this->task);

    $activity = $this->actingAs($this->tapu)->get('/employee/time/activity')->assertOk()
        ->viewData('page')['props']['activity'];

    $session = $activity['sessions'][0];

    expect($session['has_activity_data'])->toBeTrue()
        ->and($session['minutes'])->toHaveCount(10)
        ->and($session['minutes'][0]['state'])->toBe('active')
        ->and($session['minutes'][0]['host'])->toBe('docs.google.com')
        ->and($session['minutes'][7]['state'])->toBe('call')
        ->and($session['task']['name'])->toBe('Activity pages task')
        ->and($activity['summary']['active_minutes'])->toBe(6)
        ->and($activity['summary']['media_minutes'])->toBe(1)
        ->and($activity['summary']['call_minutes'])->toBe(1)
        ->and($activity['summary']['idle_minutes'])->toBe(2)
        ->and($activity['summary']['idle_percent'])->toBe(20)
        ->and($activity['sites'][0]['host'])->toBe('docs.google.com')
        ->and($activity['sites'][0]['seconds'])->toBe(180)
        ->and($activity['sites'][0]['share'])->toBe(60);
});

it('marks an entry with no samples as having no activity data', function (): void {
    TimeEntry::factory()->forEmployee($this->tapu->employee)->onTask($this->task)->create([
        'work_date' => '2026-09-24',
        'started_at' => Carbon::parse('2026-09-24 10:00:00'),
        'ended_at' => Carbon::parse('2026-09-24 10:30:00'),
        'duration_seconds' => 1800,
    ]);

    $activity = $this->actingAs($this->tapu)->get('/employee/time/activity')->assertOk()
        ->viewData('page')['props']['activity'];

    expect($activity['sessions'])->toHaveCount(1)
        ->and($activity['sessions'][0]['has_activity_data'])->toBeFalse()
        ->and($activity['sessions'][0]['activity_source'])->toBeNull()
        ->and($activity['summary']['idle_percent'])->toBe(0);
});
