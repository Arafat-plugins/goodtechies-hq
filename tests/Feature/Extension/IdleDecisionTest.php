<?php

use App\Models\ActivitySample;
use App\Models\AuditLog;
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
| Answers to the idle prompt (docs/extension-api.md §6): idempotent on the stretch, each one
| audited.
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-09-24 09:00:00');

    $this->seed(RolePermissionSeeder::class);
    $this->seed(SettingsSeeder::class);

    $this->tapu = Employee::factory()->forRole(RoleName::REMOTE_EMPLOYEE)->create();
    $project = Project::factory()->create(['name' => 'Extension idle project']);
    $this->task = Task::factory()->create(['project_id' => $project->id, 'title' => 'Extension idle task']);
    $this->task->assignees()->attach($this->tapu->id, ['is_primary' => true]);

    $this->entry = TimeEntry::factory()->forEmployee($this->tapu)->onTask($this->task)
        ->running(Carbon::parse('2026-09-24 08:30:00'))->heartbeatAt(Carbon::now())->create();

    $pairing = app(ExtensionPairingService::class);
    $this->token = $pairing->exchange($pairing->mintCode($this->tapu->user), 'Chrome on EXT-IDLE', (string) Str::uuid())['token'];
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function EXT_IDLE_post(mixed $test, TimeEntry $entry, string $decision): TestResponse
{
    return $test->withToken($test->token)->postJson('/api/timer/idle-decision', [
        'time_entry_id' => $entry->id,
        'idle_from' => '2026-09-24T08:50:00+06:00',
        'idle_to' => '2026-09-24T08:53:00+06:00',
        'decision' => $decision,
    ]);
}

function EXT_IDLE_audits(): int
{
    return AuditLog::query()->where('event', 'timer.idle_decision')->count();
}

it('discards three minutes once, even when the answer is posted twice', function (): void {
    EXT_IDLE_post($this, $this->entry, 'discard')
        ->assertOk()
        ->assertJsonPath('decision.decision', 'discard')
        ->assertJsonPath('decision.discarded_seconds', 180)
        ->assertJsonPath('decision.repeated', false);

    EXT_IDLE_post($this, $this->entry, 'discard')
        ->assertOk()
        ->assertJsonPath('decision.discarded_seconds', 180)
        ->assertJsonPath('decision.repeated', true);

    $entry = $this->entry->fresh();

    expect($entry->paused_seconds)->toBe(180)
        ->and((int) $entry->discarded_seconds)->toBe(180)
        ->and(IdleDecision::query()->count())->toBe(1)
        ->and(EXT_IDLE_audits())->toBe(1);
});

it('turns the stretch into a manual call when the answer is meeting', function (): void {
    foreach (['08:50', '08:51', '08:52', '08:53'] as $minute) {
        ActivitySample::query()->create([
            'time_entry_id' => $this->entry->id,
            'minute_at' => Carbon::parse("2026-09-24 {$minute}:00"),
            'state' => 'idle',
            'source' => 'extension',
        ]);
    }

    EXT_IDLE_post($this, $this->entry, 'meeting')->assertOk()->assertJsonPath('decision.decision', 'meeting');

    $states = ActivitySample::query()->orderBy('minute_at')->get()
        ->map(fn (ActivitySample $s): string => $s->state->value.'/'.($s->call_source ?? '-'))
        ->all();

    // [08:50, 08:53): three minutes become a call; 08:53 itself is outside the stretch.
    expect($states)->toBe(['call/manual', 'call/manual', 'call/manual', 'idle/-'])
        ->and(EXT_IDLE_audits())->toBe(1);
});

it('stops the timer when the answer is stop', function (): void {
    EXT_IDLE_post($this, $this->entry, 'stop')
        ->assertOk()
        ->assertJsonPath('running', null)
        ->assertJsonPath('decision.decision', 'stop');

    expect($this->entry->fresh()->ended_at)->not->toBeNull()
        ->and(EXT_IDLE_audits())->toBe(1);
});

it('records keep and nothing else', function (): void {
    EXT_IDLE_post($this, $this->entry, 'keep')->assertOk()->assertJsonPath('decision.decision', 'keep');

    $entry = $this->entry->fresh();

    expect($entry->isRunning())->toBeTrue()
        ->and((int) $entry->paused_seconds)->toBe(0)
        ->and(EXT_IDLE_audits())->toBe(1);
});

it('refuses a stretch longer than 24 hours and an unknown decision', function (): void {
    $this->withToken($this->token)->postJson('/api/timer/idle-decision', [
        'time_entry_id' => $this->entry->id,
        'idle_from' => '2026-09-22T08:00:00+06:00',
        'idle_to' => '2026-09-24T08:53:00+06:00',
        'decision' => 'keep',
    ])->assertStatus(422)->assertJsonPath('error', 'validation_failed');

    EXT_IDLE_post($this, $this->entry, 'auto_pause')->assertStatus(422);

    expect(IdleDecision::query()->count())->toBe(0);
});

it("answers 404 for another employee's entry", function (): void {
    $other = TimeEntry::factory()->forEmployee(Employee::factory()->forRole(RoleName::REMOTE_EMPLOYEE)->create())->running()->create();

    EXT_IDLE_post($this, $other, 'discard')->assertNotFound()->assertJsonPath('error', 'entry_not_found');
});
