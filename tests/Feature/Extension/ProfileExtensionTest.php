<?php

use App\Models\Device;
use App\Models\Employee;
use App\Models\User;
use App\Services\ExtensionPairingService;
use App\Support\RoleName;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\PersonalAccessToken;

/*
| Profile → Connect timer extension, and the Admin's revoke on the employee page (Phase 11).
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-09-24 09:00:00');

    // The full seed, for the seeded Admin with a confirmed second factor.
    $this->seed();

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();
    $this->yaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/** @return array{token: string, device: Device} */
function EXT_PROFILE_pair(User $user, string $name): array
{
    $pairing = app(ExtensionPairingService::class);

    return $pairing->exchange($pairing->mintCode($user), $name, (string) Str::uuid());
}

it('offers Tapu the extension and lists his devices', function (): void {
    $paired = EXT_PROFILE_pair($this->tapu, 'Chrome on EXT-PROFILE');

    $this->actingAs($this->tapu)->get('/profile')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('extension.available', true)
            ->has('extension.devices', 1)
            ->where('extension.devices.0.id', $paired['device']->id)
            ->where('extension.devices.0.name', 'Chrome on EXT-PROFILE'));
});

it('does not offer an office employee the extension, and refuses them a code', function (): void {
    $this->actingAs($this->yaseen)->get('/profile')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('extension.available', false));

    $this->actingAs($this->yaseen)->postJson('/profile/extension/code')->assertForbidden();
});

it("answers 404 when disconnecting somebody else's device", function (): void {
    $other = Employee::factory()->forRole(RoleName::REMOTE_EMPLOYEE)->create();
    $paired = EXT_PROFILE_pair($other->user, 'Chrome on EXT-OTHER');

    $this->actingAs($this->tapu)->delete('/profile/extension/devices/'.$paired['device']->id)->assertNotFound();

    expect($paired['device']->fresh()->revoked_at)->toBeNull();
});

it('sends the Admin Tapu\'s devices and nothing for an office employee', function (): void {
    EXT_PROFILE_pair($this->tapu, 'Chrome on EXT-ADMIN');

    $this->actingAs($this->admin)->get('/admin/employees/'.$this->tapu->employee->id)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('extensionDevices', 1));

    $this->actingAs($this->admin)->get('/admin/employees/'.$this->yaseen->employee->id)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('extensionDevices', null));
});

it('lets the Admin revoke a device: the token goes and revoked_at is set', function (): void {
    $paired = EXT_PROFILE_pair($this->tapu, 'Chrome on EXT-REVOKE');
    $tokenId = $paired['device']->token_id;

    $this->actingAs($this->admin)
        ->delete('/admin/employees/'.$this->tapu->employee->id.'/extension-devices/'.$paired['device']->id)
        ->assertRedirect();

    expect(PersonalAccessToken::query()->whereKey($tokenId)->exists())->toBeFalse()
        ->and($paired['device']->fresh()->revoked_at)->not->toBeNull();
});
