<?php

use App\Models\ActivitySample;
use App\Models\ActivitySite;
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
| Heartbeats with activity and sites (docs/extension-api.md §5): idempotent, owned by the
| extension over the web, and never carrying anything but a bare host.
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-09-24 09:00:00');

    $this->seed(RolePermissionSeeder::class);
    $this->seed(SettingsSeeder::class);

    $this->tapu = Employee::factory()->forRole(RoleName::REMOTE_EMPLOYEE)->create();
    $project = Project::factory()->create(['name' => 'Extension heartbeat project']);
    $this->task = Task::factory()->create(['project_id' => $project->id, 'title' => 'Extension heartbeat task']);
    $this->task->assignees()->attach($this->tapu->id, ['is_primary' => true]);

    $this->entry = TimeEntry::factory()->forEmployee($this->tapu)->onTask($this->task)
        ->running(Carbon::parse('2026-09-24 08:30:00'))->heartbeatAt(Carbon::now())->create();

    $pairing = app(ExtensionPairingService::class);
    $this->token = $pairing->exchange($pairing->mintCode($this->tapu->user), 'Chrome on EXT-HB', (string) Str::uuid())['token'];
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/** @param list<array<string, mixed>> $samples */
function EXT_HB_body(TimeEntry $entry, array $samples): array
{
    return ['time_entry_id' => $entry->id, 'client_uuid' => (string) Str::uuid(), 'samples' => $samples];
}

function EXT_HB_sample(string $minute, string $state = 'active', array $sites = []): array
{
    return ['minute' => $minute, 'state' => $state, 'call_source' => null, 'sites' => $sites];
}

function EXT_HB_batch(): array
{
    return [
        EXT_HB_sample('2026-09-24T08:55:00+06:00', 'active', [
            ['kind' => 'site', 'host' => 'docs.google.com', 'seconds' => 48],
            ['kind' => 'other_app', 'host' => '', 'seconds' => 12],
        ]),
        EXT_HB_sample('2026-09-24T08:56:00+06:00', 'media', [
            ['kind' => 'site', 'host' => 'youtube.com', 'seconds' => 60],
        ]),
        EXT_HB_sample('2026-09-24T08:57:00+06:00', 'active', [
            ['kind' => 'site', 'host' => 'localhost:8000', 'seconds' => 30],
            ['kind' => 'private', 'host' => '', 'seconds' => 30],
        ]),
    ];
}

it('stores a batch of samples with their sites', function (): void {
    $this->withToken($this->token)
        ->postJson('/api/timer/heartbeat', EXT_HB_body($this->entry, EXT_HB_batch()))
        ->assertOk()
        ->assertJsonPath('accepted', 3)
        ->assertJsonPath('repeated', 0)
        ->assertJsonPath('rejected', [])
        ->assertJsonPath('running.id', $this->entry->id);

    expect(ActivitySample::query()->count())->toBe(3)
        ->and(ActivitySite::query()->count())->toBe(5)
        ->and(ActivitySite::query()->where('host', 'docs.google.com')->value('seconds'))->toBe(48)
        ->and(ActivitySample::query()->pluck('source')->unique()->all())->toBe(['extension'])
        ->and($this->entry->fresh()->activity_source)->toBe('extension');
});

it('counts a replayed batch as repeated and stores nothing twice', function (): void {
    $this->withToken($this->token)->postJson('/api/timer/heartbeat', EXT_HB_body($this->entry, EXT_HB_batch()))->assertOk();

    $this->withToken($this->token)
        ->postJson('/api/timer/heartbeat', EXT_HB_body($this->entry, EXT_HB_batch()))
        ->assertOk()
        ->assertJsonPath('accepted', 0)
        ->assertJsonPath('repeated', 3);

    expect(ActivitySample::query()->count())->toBe(3)
        ->and(ActivitySite::query()->count())->toBe(5);
});

it('keeps an extension minute when the web heartbeat arrives for it', function (): void {
    $this->withToken($this->token)
        ->postJson('/api/timer/heartbeat', EXT_HB_body($this->entry, [EXT_HB_sample('2026-09-24T09:00:00+06:00', 'media')]))
        ->assertJsonPath('accepted', 1);

    app('auth')->forgetGuards();
    $this->withoutToken()->actingAs($this->tapu->user)->postJson('/employee/time/heartbeat')->assertOk();

    $sample = ActivitySample::query()->sole();

    expect($sample->source)->toBe('extension')
        ->and($sample->state->value)->toBe('media');
});

it('lets an extension minute replace a web one', function (): void {
    $this->actingAs($this->tapu->user)->postJson('/employee/time/heartbeat')->assertOk();

    expect(ActivitySample::query()->sole()->source)->toBe('web');

    app('auth')->forgetGuards();
    $this->withToken($this->token)
        ->postJson('/api/timer/heartbeat', EXT_HB_body($this->entry, [
            ['minute' => '2026-09-24T09:00:00+06:00', 'state' => 'call', 'call_source' => 'detected', 'sites' => []],
        ]))
        ->assertOk()
        ->assertJsonPath('accepted', 1);

    $sample = ActivitySample::query()->sole();

    expect($sample->source)->toBe('extension')
        ->and($sample->state->value)->toBe('call')
        ->and($sample->call_source)->toBe('detected');
});

it('answers a heartbeat for a stopped entry with 409 and the state', function (): void {
    $this->entry->forceFill(['ended_at' => Carbon::now(), 'duration_seconds' => 1800])->save();

    $this->withToken($this->token)
        ->postJson('/api/timer/heartbeat', EXT_HB_body($this->entry, EXT_HB_batch()))
        ->assertStatus(409)
        ->assertJsonPath('error', 'entry_not_running')
        ->assertJsonPath('state.running', null);

    expect(ActivitySample::query()->count())->toBe(0);
});

it('rejects a minute in the future and one before the start, and keeps the rest', function (): void {
    $this->withToken($this->token)
        ->postJson('/api/timer/heartbeat', EXT_HB_body($this->entry, [
            EXT_HB_sample('2026-09-24T08:59:00+06:00'),
            EXT_HB_sample('2026-09-24T09:05:00+06:00'),
            EXT_HB_sample('2026-09-24T08:00:00+06:00'),
        ]))
        ->assertOk()
        ->assertJsonPath('accepted', 1)
        ->assertJsonPath('rejected.0.reason', 'in_future')
        ->assertJsonPath('rejected.1.reason', 'before_start');

    expect(ActivitySample::query()->count())->toBe(1);
});

it('refuses sites adding up to more than 60 seconds', function (): void {
    $this->withToken($this->token)
        ->postJson('/api/timer/heartbeat', EXT_HB_body($this->entry, [EXT_HB_sample('2026-09-24T08:59:00+06:00', 'active', [
            ['kind' => 'site', 'host' => 'docs.google.com', 'seconds' => 31],
            ['kind' => 'site', 'host' => 'mail.google.com', 'seconds' => 30],
        ])]))
        ->assertStatus(422)
        ->assertJsonPath('error', 'validation_failed');

    expect(ActivitySample::query()->count())->toBe(0);
});

it('refuses anything but a bare lower-case host', function (string $host): void {
    $this->withToken($this->token)
        ->postJson('/api/timer/heartbeat', EXT_HB_body($this->entry, [EXT_HB_sample('2026-09-24T08:59:00+06:00', 'active', [
            ['kind' => 'site', 'host' => $host, 'seconds' => 30],
        ])]))
        ->assertStatus(422)
        ->assertJsonPath('error', 'validation_failed');

    expect(ActivitySample::query()->count())->toBe(0);
})->with([
    'a path' => 'docs.google.com/path',
    'a space' => 'a b.com',
    'a scheme' => 'https://x.com',
    'upper case and www' => 'www.Docs.Google.com',
    'a port on a domain' => 'example.com:8080',
    'credentials' => 'user@example.com',
]);

it('refuses a payload carrying a key named url, title or hostname', function (string $key): void {
    $sample = EXT_HB_sample('2026-09-24T08:59:00+06:00', 'active', [
        ['kind' => 'site', 'host' => 'docs.google.com', 'seconds' => 30, $key => 'https://docs.google.com/d/1'],
    ]);

    $this->withToken($this->token)
        ->postJson('/api/timer/heartbeat', EXT_HB_body($this->entry, [$sample]))
        ->assertStatus(422)
        ->assertJsonPath('error', 'validation_failed');

    expect(ActivitySample::query()->count())->toBe(0);
})->with(['url', 'title', 'hostname']);
