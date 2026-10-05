<?php

use App\Models\ActivitySample;
use App\Models\Employee;
use App\Models\IdleDecision;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Services\ExtensionPairingService;
use App\Support\RoleName;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/*
| The server's auto-pause (docs/extension-api.md §6): five consecutive idle minutes and no
| decision → paused at the first of them.
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-09-24 09:00:00');

    $this->seed(RolePermissionSeeder::class);
    $this->seed(SettingsSeeder::class);

    $this->tapu = Employee::factory()->forRole(RoleName::REMOTE_EMPLOYEE)->create();
    $project = Project::factory()->create(['name' => 'Extension idle rule project']);
    $this->task = Task::factory()->create(['project_id' => $project->id, 'title' => 'Extension idle rule task']);
    $this->task->assignees()->attach($this->tapu->id, ['is_primary' => true]);

    $this->entry = TimeEntry::factory()->forEmployee($this->tapu)->onTask($this->task)
        ->running(Carbon::parse('2026-09-24 08:30:00'))->heartbeatAt(Carbon::now())->create();

    $pairing = app(ExtensionPairingService::class);
    $this->token = $pairing->exchange($pairing->mintCode($this->tapu->user), 'Chrome on EXT-RULE', (string) Str::uuid())['token'];
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/** @param list<string> $states  one per minute, from 08:55 */
function EXT_RULE_samples(array $states): array
{
    $samples = [];

    foreach ($states as $i => $state) {
        $samples[] = [
            'minute' => Carbon::parse('2026-09-24 08:55:00')->addMinutes($i)->toIso8601String(),
            'state' => $state,
            'call_source' => null,
            'sites' => [],
        ];
    }

    return $samples;
}

function EXT_RULE_beat(mixed $test, TimeEntry $entry, array $states): TestResponse
{
    return $test->withToken($test->token)->postJson('/api/timer/heartbeat', [
        'time_entry_id' => $entry->id,
        'client_uuid' => (string) Str::uuid(),
        'samples' => EXT_RULE_samples($states),
    ]);
}

it('pauses the entry at the first of five consecutive idle minutes', function (): void {
    EXT_RULE_beat($this, $this->entry, ['idle', 'idle', 'idle', 'idle', 'idle'])
        ->assertOk()
        ->assertJsonPath('running.state', 'paused')
        ->assertJsonPath('activity.pending_idle.idle_from', '2026-09-24T08:55:00+06:00');

    $entry = $this->entry->fresh();

    expect($entry->paused_at->format('Y-m-d H:i:s'))->toBe('2026-09-24 08:55:00')
        ->and($entry->idle_pending_from->format('Y-m-d H:i:s'))->toBe('2026-09-24 08:55:00')
        ->and($entry->idle_auto_paused_at)->not->toBeNull()
        ->and((int) $entry->idle_minutes)->toBe(5)
        ->and(IdleDecision::query()->where('decision', 'auto_pause')->count())->toBe(1);
});

it('does not pause when one of the five minutes is media', function (): void {
    EXT_RULE_beat($this, $this->entry, ['idle', 'idle', 'idle', 'idle', 'media'])->assertOk();

    expect($this->entry->fresh()->isRunning())->toBeTrue()
        ->and(IdleDecision::query()->count())->toBe(0);
});

it('does not pause a stretch somebody already decided to keep', function (): void {
    IdleDecision::query()->create([
        'time_entry_id' => $this->entry->id,
        'idle_from' => Carbon::parse('2026-09-24 08:55:00'),
        'idle_to' => Carbon::parse('2026-09-24 08:58:00'),
        'decision' => 'keep',
        'source' => 'extension',
        'decided_at' => Carbon::now(),
    ]);

    EXT_RULE_beat($this, $this->entry, ['idle', 'idle', 'idle', 'idle', 'idle'])->assertOk();

    expect($this->entry->fresh()->isRunning())->toBeTrue()
        ->and(IdleDecision::query()->where('decision', 'auto_pause')->count())->toBe(0);
});

it('runs on the web timer heartbeat too', function (): void {
    foreach (range(0, 4) as $i) {
        ActivitySample::query()->create([
            'time_entry_id' => $this->entry->id,
            'minute_at' => Carbon::parse('2026-09-24 08:56:00')->addMinutes($i),
            'state' => 'idle',
            'source' => 'extension',
        ]);
    }

    $this->actingAs($this->tapu->user)->postJson('/employee/time/heartbeat')->assertOk();

    $entry = $this->entry->fresh();

    expect($entry->isPaused())->toBeTrue()
        ->and($entry->paused_at->format('H:i'))->toBe('08:56')
        ->and(IdleDecision::query()->where('decision', 'auto_pause')->count())->toBe(1);
});

it('lets the answer to an auto-pause land on the same stretch and clears the prompt', function (): void {
    EXT_RULE_beat($this, $this->entry, ['idle', 'idle', 'idle', 'idle', 'idle'])->assertOk();

    $this->withToken($this->token)->postJson('/api/timer/idle-decision', [
        'time_entry_id' => $this->entry->id,
        'idle_from' => '2026-09-24T08:55:00+06:00',
        'idle_to' => '2026-09-24T09:00:00+06:00',
        'decision' => 'keep',
    ])->assertOk()
        ->assertJsonPath('decision.repeated', false)
        ->assertJsonPath('activity.pending_idle', null);

    expect(IdleDecision::query()->sole()->decision->value)->toBe('keep')
        ->and($this->entry->fresh()->isPaused())->toBeTrue();
});

it('clears the pending prompt when the auto-paused entry is resumed', function (): void {
    EXT_RULE_beat($this, $this->entry, ['idle', 'idle', 'idle', 'idle', 'idle'])
        ->assertOk()
        ->assertJsonPath('running.state', 'paused');

    $this->withToken($this->token)
        ->postJson('/api/timer/resume', ['time_entry_id' => $this->entry->id])
        ->assertOk()
        ->assertJsonPath('running.state', 'running')
        ->assertJsonPath('activity.pending_idle', null);

    $entry = $this->entry->fresh();

    expect($entry->idle_pending_from)->toBeNull()
        ->and($entry->idle_auto_paused_at)->toBeNull();
});
