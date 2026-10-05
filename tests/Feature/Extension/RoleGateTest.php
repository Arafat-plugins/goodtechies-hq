<?php

use App\Models\Device;
use App\Models\Employee;
use App\Services\EmployeeAdministrationService;
use App\Services\ExtensionPairingService;
use App\Support\RoleName;
use App\Support\UserStatus;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/*
| Who the extension API is for (docs/extension-api.md §1): remote timer users only, with a
| token that opens nothing but these routes.
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-09-24 09:00:00');

    $this->seed(RolePermissionSeeder::class);
    $this->seed(SettingsSeeder::class);

    $this->tapu = Employee::factory()->forRole(RoleName::REMOTE_EMPLOYEE)->create();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/** @return array{token: string, device: Device} */
function EXT_GATE_pair(Employee $employee): array
{
    $pairing = app(ExtensionPairingService::class);

    return $pairing->exchange($pairing->mintCode($employee->user), 'Chrome on EXT-GATE', (string) Str::uuid());
}

it('refuses an office employee at the exchange and issues no token', function (): void {
    $yaseen = Employee::factory()->forRole(RoleName::EMPLOYEE)->create();
    $code = app(ExtensionPairingService::class)->mintCode($yaseen->user);

    $this->postJson('/api/extension/exchange', [
        'code' => $code,
        'device_name' => 'Chrome on EXT-GATE',
        'install_uuid' => (string) Str::uuid(),
    ])->assertForbidden()->assertJsonPath('error', 'role_not_allowed');

    expect(PersonalAccessToken::query()->count())->toBe(0)
        ->and(Device::query()->count())->toBe(0);
});

it('refuses an office employee a pairing code from Profile', function (): void {
    $yaseen = Employee::factory()->forRole(RoleName::EMPLOYEE)->create();

    $this->actingAs($yaseen->user)->postJson('/profile/extension/code')->assertForbidden();
});

it('refuses an Admin holding a token with the three abilities', function (): void {
    $admin = Employee::factory()->forRole(RoleName::ADMIN)->create();
    $token = $admin->user->createToken('timer-extension', ExtensionPairingService::ABILITIES)->plainTextToken;

    $this->withToken($token)->getJson('/api/timer/state')
        ->assertForbidden()
        ->assertJsonPath('error', 'role_not_allowed');
});

it('refuses a token without the ability the route needs', function (): void {
    $token = $this->tapu->user->createToken('timer-extension', ['timer:heartbeat'])->plainTextToken;

    $this->withToken($token)->getJson('/api/timer/state')
        ->assertForbidden()
        ->assertJsonPath('error', 'ability_missing');
});

it('answers 401 token_invalid for a revoked token', function (): void {
    $paired = EXT_GATE_pair($this->tapu);

    app(ExtensionPairingService::class)->revoke($paired['device']);

    $this->withToken($paired['token'])->getJson('/api/timer/state')
        ->assertUnauthorized()
        ->assertJsonPath('error', 'token_invalid')
        ->assertJsonPath('message', 'Reconnect the timer extension from your Profile.');
});

it('opens no web page with a bearer token', function (): void {
    $paired = EXT_GATE_pair($this->tapu);

    $status = $this->withToken($paired['token'])->get('/employee/dashboard')->getStatusCode();

    expect($status)->not->toBe(200);
});

it('answers 404 for an unlisted api path', function (): void {
    $paired = EXT_GATE_pair($this->tapu);

    $this->withToken($paired['token'])->getJson('/api/user')->assertNotFound();
});

it('deletes the tokens of an employee who is deactivated', function (): void {
    $paired = EXT_GATE_pair($this->tapu);
    $admin = Employee::factory()->forRole(RoleName::ADMIN)->create();

    app(EmployeeAdministrationService::class)->deactivate($admin->user, $this->tapu->fresh());

    expect(PersonalAccessToken::query()->where('tokenable_id', $this->tapu->user_id)->count())->toBe(0)
        ->and($paired['device']->fresh()->revoked_at)->not->toBeNull();

    app('auth')->forgetGuards();
    $this->withToken($paired['token'])->getJson('/api/timer/state')->assertUnauthorized();
});

it('refuses a deactivated user whose token somehow survived with 403 account_inactive', function (): void {
    $token = $this->tapu->user->createToken('timer-extension', ExtensionPairingService::ABILITIES)->plainTextToken;

    $this->tapu->user->forceFill(['status' => UserStatus::Inactive])->save();

    $this->withToken($token)->getJson('/api/timer/state')
        ->assertForbidden()
        ->assertJsonPath('error', 'account_inactive');
});
