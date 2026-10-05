<?php

use App\Models\Employee;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Services\ExtensionPairingService;
use App\Support\RoleName;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/*
| The extension's timer verbs (docs/extension-api.md §2) are new doors into the same
| TimerService the web timer uses: both clients read the same state.
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-09-24 09:00:00');

    $this->seed(RolePermissionSeeder::class);
    $this->seed(SettingsSeeder::class);

    $this->tapu = Employee::factory()->forRole(RoleName::REMOTE_EMPLOYEE)->create();
    $project = Project::factory()->create(['name' => 'Extension timer project']);
    $this->task = Task::factory()->create(['project_id' => $project->id, 'title' => 'Extension timer task']);
    $this->task->assignees()->attach($this->tapu->id, ['is_primary' => true]);

    $pairing = app(ExtensionPairingService::class);
    $this->token = $pairing->exchange($pairing->mintCode($this->tapu->user), 'Chrome on EXT-API', (string) Str::uuid())['token'];
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('shows a timer started on the web as running, with the same client_uuid', function (): void {
    $uuid = (string) Str::uuid();

    $this->actingAs($this->tapu->user)
        ->post('/employee/time/start', ['task_id' => $this->task->id, 'client_uuid' => $uuid])
        ->assertRedirect();

    app('auth')->forgetGuards();

    $this->withToken($this->token)->getJson('/api/timer/state')
        ->assertOk()
        ->assertJsonPath('running.client_uuid', $uuid)
        ->assertJsonPath('running.state', 'running')
        ->assertJsonPath('activity.idle_prompt_seconds', 120)
        ->assertJsonPath('activity.idle_pause_minutes', 5)
        ->assertJsonPath('activity.pending_idle', null);
});

it('lists the tasks the person may time', function (): void {
    $this->withToken($this->token)->getJson('/api/timer/tasks')
        ->assertOk()
        ->assertJsonPath('tasks.0.id', $this->task->id)
        ->assertJsonPath('tasks.0.project', 'Extension timer project');
});

it('starts from the extension and stops there, and the web sees it stopped', function (): void {
    $uuid = (string) Str::uuid();

    $started = $this->withToken($this->token)
        ->postJson('/api/timer/start', ['task_id' => $this->task->id, 'client_uuid' => $uuid])
        ->assertOk()
        ->assertJsonPath('running.state', 'running');

    $entryId = $started->json('running.id');

    expect(TimeEntry::query()->findOrFail($entryId)->activity_source)->toBe('extension');

    $this->withToken($this->token)
        ->postJson('/api/timer/stop', ['time_entry_id' => $entryId])
        ->assertOk()
        ->assertJsonPath('running', null);

    app('auth')->forgetGuards();

    $this->withoutToken()->actingAs($this->tapu->user)
        ->getJson('/employee/time/current')
        ->assertOk()
        ->assertJsonPath('running', null);

    expect(TimeEntry::query()->findOrFail($entryId)->ended_at)->not->toBeNull();
});

it('answers a second pause with 409 entry_not_running and the state', function (): void {
    $entry = TimeEntry::factory()->forEmployee($this->tapu)->onTask($this->task)->running()->heartbeatAt(Carbon::now())->create();

    $this->withToken($this->token)
        ->postJson('/api/timer/pause', ['time_entry_id' => $entry->id])
        ->assertOk()
        ->assertJsonPath('running.state', 'paused');

    $this->withToken($this->token)
        ->postJson('/api/timer/pause', ['time_entry_id' => $entry->id])
        ->assertStatus(409)
        ->assertJsonPath('error', 'entry_not_running')
        ->assertJsonPath('state.running.state', 'paused');
});

it('answers a resume on a running entry with 409 entry_not_paused', function (): void {
    $entry = TimeEntry::factory()->forEmployee($this->tapu)->onTask($this->task)->running()->heartbeatAt(Carbon::now())->create();

    $this->withToken($this->token)
        ->postJson('/api/timer/resume', ['time_entry_id' => $entry->id])
        ->assertStatus(409)
        ->assertJsonPath('error', 'entry_not_paused')
        ->assertJsonStructure(['error', 'message', 'state' => ['running']]);
});

it("answers 404 entry_not_found for another employee's entry", function (): void {
    $other = Employee::factory()->forRole(RoleName::REMOTE_EMPLOYEE)->create();
    $entry = TimeEntry::factory()->forEmployee($other)->running()->create();

    foreach (['pause', 'resume', 'stop'] as $verb) {
        $this->withToken($this->token)
            ->postJson("/api/timer/{$verb}", ['time_entry_id' => $entry->id])
            ->assertNotFound()
            ->assertJsonPath('error', 'entry_not_found');
    }

    expect($entry->fresh()->isRunning())->toBeTrue();
});
