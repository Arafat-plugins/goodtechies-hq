<?php

use App\Models\Device;
use App\Models\Employee;
use App\Models\Project;
use App\Models\Task;
use App\Services\ExtensionPairingService;
use App\Support\RoleName;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;

/*
| Pairing the timer extension (docs/extension-api.md §1–§2): a code from Profile, traded once
| for a token carrying exactly the three timer abilities.
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-09-24 09:00:00');

    $this->seed(RolePermissionSeeder::class);
    $this->seed(SettingsSeeder::class);

    $this->tapu = Employee::factory()->forRole(RoleName::REMOTE_EMPLOYEE)->create();
    $project = Project::factory()->create(['name' => 'Extension pairing project']);
    $this->task = Task::factory()->create(['project_id' => $project->id, 'title' => 'Extension pairing task']);
    $this->task->assignees()->attach($this->tapu->id, ['is_primary' => true]);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function EXT_PAIRING_exchange(mixed $test, string $code): TestResponse
{
    return $test->postJson('/api/extension/exchange', [
        'code' => $code,
        'device_name' => 'Chrome on EXT-PAIRING',
        'install_uuid' => (string) Str::uuid(),
    ]);
}

it('mints a pairing code from Profile for a remote timer user', function (): void {
    $response = $this->actingAs($this->tapu->user)
        ->postJson('/profile/extension/code')
        ->assertOk()
        ->assertJsonPath('expires_in_minutes', 10);

    expect($response->json('code'))->toMatch('/^[A-HJ-NP-Z2-9]{8}$/');
});

it('exchanges a code for a token carrying exactly the three timer abilities and a device row', function (): void {
    $code = app(ExtensionPairingService::class)->mintCode($this->tapu->user);

    $response = EXT_PAIRING_exchange($this, $code)
        ->assertCreated()
        ->assertJsonStructure(['token', 'device' => ['id', 'name'], 'user' => ['name'], 'server_time', 'state' => ['server_time', 'running', 'today', 'activity']])
        ->assertJsonPath('user.name', $this->tapu->user->name);

    $token = PersonalAccessToken::findToken($response->json('token'));

    expect($token)->not->toBeNull()
        ->and($token->name)->toBe('timer-extension')
        ->and($token->abilities)->toBe(['timer:read-tasks', 'timer:track', 'timer:heartbeat'])
        ->and($token->expires_at)->toBeNull();

    $device = Device::query()->sole();

    expect($device->user_id)->toBe($this->tapu->user_id)
        ->and($device->token_id)->toBe($token->id)
        ->and($device->kind)->toBe('extension')
        ->and($device->revoked_at)->toBeNull();
});

it('burns the code on first use, so a second exchange is 422 code_invalid', function (): void {
    $code = app(ExtensionPairingService::class)->mintCode($this->tapu->user);

    EXT_PAIRING_exchange($this, $code)->assertCreated();

    EXT_PAIRING_exchange($this, $code)
        ->assertStatus(422)
        ->assertJsonPath('error', 'code_invalid');

    expect(PersonalAccessToken::query()->count())->toBe(1);
});

it('refuses an expired code', function (): void {
    $code = app(ExtensionPairingService::class)->mintCode($this->tapu->user);

    $this->travel(11)->minutes();

    EXT_PAIRING_exchange($this, $code)
        ->assertStatus(422)
        ->assertJsonPath('error', 'code_invalid');

    expect(PersonalAccessToken::query()->count())->toBe(0);
});

it('disconnects: 204, the device is revoked and the token no longer authenticates', function (): void {
    $code = app(ExtensionPairingService::class)->mintCode($this->tapu->user);
    $token = EXT_PAIRING_exchange($this, $code)->assertCreated()->json('token');

    $this->withToken($token)->getJson('/api/timer/state')->assertOk();

    app('auth')->forgetGuards();
    $this->withToken($token)->postJson('/api/extension/disconnect')->assertNoContent();

    expect(Device::query()->sole()->revoked_at)->not->toBeNull()
        ->and(PersonalAccessToken::query()->count())->toBe(0);

    app('auth')->forgetGuards();
    $this->withToken($token)->getJson('/api/timer/state')
        ->assertUnauthorized()
        ->assertJsonPath('error', 'token_invalid');
});
