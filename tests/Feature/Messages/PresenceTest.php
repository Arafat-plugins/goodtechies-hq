<?php

use App\Models\User;
use App\Services\ConversationService;
use App\Services\MessageService;

/*
|--------------------------------------------------------------------------
| Presence: last seen, the `online` channel, and the DM "seen" tick (12-79)
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    $this->seed();

    $this->conversations = app(ConversationService::class);
    $this->messages = app(MessageService::class);

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();
    $this->accountant = User::where('email', 'accountant@goodtechies.test')->firstOrFail();
});

it('records last seen on a heartbeat, at most every 30 seconds', function () {
    $this->actingAs($this->tapu)->postJson('/presence/heartbeat')->assertNoContent();

    $first = $this->tapu->fresh()->last_seen_at;
    expect($first)->not->toBeNull();

    $this->travel(10)->seconds();
    $this->actingAs($this->tapu)->postJson('/presence/heartbeat')->assertNoContent();

    expect($this->tapu->fresh()->last_seen_at->equalTo($first))->toBeTrue();

    $this->travel(31)->seconds();
    $this->actingAs($this->tapu)->postJson('/presence/heartbeat')->assertNoContent();

    expect($this->tapu->fresh()->last_seen_at->greaterThan($first))->toBeTrue();
})->group('messaging-12-79');

it('sends a guest to log in', function () {
    $this->post('/presence/heartbeat')->assertRedirect('/login');
})->group('messaging-12-79');

it('authorizes a messaging user on the online channel and refuses the Accountant', function () {
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-app-key',
        'broadcasting.connections.reverb.secret' => 'test-app-secret',
        'broadcasting.connections.reverb.app_id' => 'test-app-id',
    ]);
    require base_path('routes/channels.php');

    $auth = fn (User $user) => $this->actingAs($user)->postJson('/broadcasting/auth', [
        'socket_id' => '12345.67890',
        'channel_name' => 'presence-online',
    ]);

    $ok = $auth($this->tapu)->assertOk();
    expect(json_decode((string) $ok->json('channel_data'), true))
        ->toMatchArray(['user_id' => $this->tapu->id, 'user_info' => ['id' => $this->tapu->id, 'name' => $this->tapu->name]]);

    $auth($this->accountant)->assertForbidden();
})->group('messaging-12-79');

it('puts the peer and their last seen on a DM thread, and ticks seen once they read', function () {
    $this->tapu->forceFill(['last_seen_at' => now()->subMinutes(5)])->saveQuietly();

    $dm = $this->conversations->dmBetween($this->admin, $this->tapu);
    $mine = $this->messages->post($this->admin, $dm, 'Did you see this?');

    $before = $this->actingAs($this->admin)->getJson('/messages/'.$dm->id)->assertOk();

    expect($before->json('peer.id'))->toBe($this->tapu->id)
        ->and($before->json('peer.name'))->toBe($this->tapu->name)
        ->and($before->json('peer.last_seen_at'))->toBe($this->tapu->fresh()->last_seen_at->toIso8601String())
        ->and(collect($before->json('messages'))->firstWhere('id', $mine->id)['seen'])->toBeFalse();

    $this->travel(1)->seconds();
    $this->conversations->markRead($this->tapu, $dm);

    $after = $this->actingAs($this->admin)->getJson('/messages/'.$dm->id)->assertOk();

    expect(collect($after->json('messages'))->firstWhere('id', $mine->id)['seen'])->toBeTrue();

    // The reader's side of the same message carries no tick, and a channel has no peer.
    $theirs = $this->actingAs($this->tapu)->getJson('/messages/'.$dm->id)->assertOk();
    expect(collect($theirs->json('messages'))->firstWhere('id', $mine->id)['seen'])->toBeNull();

    $this->actingAs($this->admin)
        ->getJson('/messages/'.$this->conversations->team()->id)
        ->assertOk()
        ->assertJsonPath('peer', null);
})->group('messaging-12-79');
