<?php

use App\Models\PushSubscription;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| Push notifications: a device's subscription and the person's two switches
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    $this->seed();

    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();
    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
});

function PUSH_body(string $endpoint = 'https://fcm.googleapis.com/fcm/send/abc'): array
{
    return [
        'endpoint' => $endpoint,
        'keys' => ['p256dh' => 'BPublicKeyExample', 'auth' => 'AuthExample'],
        'content_encoding' => 'aes128gcm',
    ];
}

it('stores a device for the signed-in person', function () {
    $this->actingAs($this->tapu)->postJson('/push/subscriptions', PUSH_body())->assertOk();

    expect(PushSubscription::query()->count())->toBe(1);

    $row = PushSubscription::query()->firstOrFail();

    expect($row->user_id)->toBe($this->tapu->id)
        ->and($row->endpoint)->toBe('https://fcm.googleapis.com/fcm/send/abc');
});

it('moves a device to whoever registers the same endpoint again', function () {
    $this->actingAs($this->tapu)->postJson('/push/subscriptions', PUSH_body())->assertOk();
    $this->actingAs($this->admin)->postJson('/push/subscriptions', PUSH_body())->assertOk();

    expect(PushSubscription::query()->count())->toBe(1)
        ->and(PushSubscription::query()->firstOrFail()->user_id)->toBe($this->admin->id);
});

it('does not move a device to somebody who cannot prove they hold it', function () {
    $this->actingAs($this->tapu)->postJson('/push/subscriptions', PUSH_body())->assertOk();

    $this->actingAs($this->admin)
        ->postJson('/push/subscriptions', [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/abc',
            'keys' => ['p256dh' => 'BPublicKeyExample', 'auth' => 'DifferentAuth'],
            'content_encoding' => 'aes128gcm',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('endpoint');

    expect(PushSubscription::query()->count())->toBe(1)
        ->and(PushSubscription::query()->firstOrFail()->user_id)->toBe($this->tapu->id);
});

it('refuses a non-https endpoint', function () {
    $this->actingAs($this->tapu)
        ->postJson('/push/subscriptions', PUSH_body('http://fcm.googleapis.com/fcm/send/abc'))
        ->assertStatus(422);

    expect(PushSubscription::query()->count())->toBe(0);
});

it('forgets a device, and never somebody else\'s', function () {
    $this->actingAs($this->tapu)->postJson('/push/subscriptions', PUSH_body())->assertOk();

    $this->actingAs($this->tapu)
        ->deleteJson('/push/subscriptions', ['endpoint' => 'https://fcm.googleapis.com/fcm/send/abc'])
        ->assertOk();

    expect(PushSubscription::query()->count())->toBe(0);

    $this->actingAs($this->admin)->postJson('/push/subscriptions', PUSH_body('https://fcm.googleapis.com/fcm/send/admin'))->assertOk();

    $this->actingAs($this->tapu)
        ->deleteJson('/push/subscriptions', ['endpoint' => 'https://fcm.googleapis.com/fcm/send/admin'])
        ->assertOk();

    expect(PushSubscription::query()->where('user_id', $this->admin->id)->count())->toBe(1);
});

it('does not let a guest register a device', function () {
    $this->postJson('/push/subscriptions', PUSH_body())->assertStatus(401);

    expect(PushSubscription::query()->count())->toBe(0);
});

it('saves the two switches, and the Profile page carries them', function () {
    $this->actingAs($this->tapu)
        ->put('/profile/push', ['messages' => false, 'alerts' => true])
        ->assertRedirect();

    $fresh = $this->tapu->fresh();

    expect($fresh->push_messages)->toBeFalse()
        ->and($fresh->push_alerts)->toBeTrue();

    $push = $this->actingAs($this->tapu)->get('/profile')->inertiaProps()['push'];

    expect($push)->toHaveKeys(['vapidPublicKey', 'messages', 'alerts']);
});

it('starts new users with both switches on', function () {
    $user = User::factory()->create()->fresh();

    expect($user->push_messages)->toBeTrue()
        ->and($user->push_alerts)->toBeTrue();
});

it('refuses an address that is not a browser push service', function () {
    $this->actingAs($this->tapu)
        ->postJson('/push/subscriptions', PUSH_body('https://169.254.169.254.example.com/latest'))
        ->assertStatus(422)
        ->assertJsonValidationErrors('endpoint');

    $this->actingAs($this->tapu)
        ->postJson('/push/subscriptions', PUSH_body('https://updates.push.services.mozilla.com/wpush/v2/abc'))
        ->assertOk();

    // Chromium's own push host, seen in a real browser run.
    $this->actingAs($this->tapu)
        ->postJson('/push/subscriptions', PUSH_body('https://jmt17.google.com/fcm/send/abc'))
        ->assertOk();

    $this->actingAs($this->tapu)
        ->postJson('/push/subscriptions', PUSH_body('https://evil-google.com/fcm/send/abc'))
        ->assertStatus(422);

    // Other Google hosts serve anybody's content, so they are never a push service.
    $this->actingAs($this->tapu)
        ->postJson('/push/subscriptions', PUSH_body('https://script.google.com/macros/s/abc/exec'))
        ->assertStatus(422);

    $this->actingAs($this->tapu)
        ->postJson('/push/subscriptions', PUSH_body('https://storage.googleapis.com/bucket/x'))
        ->assertStatus(422);

    expect(PushSubscription::query()->count())->toBe(2);
});

it('keeps at most ten devices per person', function () {
    for ($i = 1; $i <= 12; $i++) {
        $this->actingAs($this->tapu)
            ->postJson('/push/subscriptions', PUSH_body("https://fcm.googleapis.com/fcm/send/d{$i}"))
            ->assertOk();

        $this->travel(1)->seconds();
    }

    $endpoints = PushSubscription::query()->where('user_id', $this->tapu->id)->pluck('endpoint');

    expect($endpoints)->toHaveCount(10)
        ->and($endpoints->contains('https://fcm.googleapis.com/fcm/send/d1'))->toBeFalse()
        ->and($endpoints->contains('https://fcm.googleapis.com/fcm/send/d2'))->toBeFalse();
});

it('forgets every device when the password changes', function () {
    foreach (['one', 'two'] as $name) {
        PushSubscription::query()->create([
            'user_id' => $this->tapu->id,
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/'.$name,
            'public_key' => 'BPublicKeyExample',
            'auth_token' => 'AuthExample',
            'content_encoding' => 'aes128gcm',
        ]);
    }

    expect(app(\App\Services\PushService::class)->forgetAllDevices($this->tapu))->toBe(2)
        ->and(PushSubscription::query()->where('user_id', $this->tapu->id)->count())->toBe(0);

    PushSubscription::query()->create([
        'user_id' => $this->tapu->id,
        'endpoint' => 'https://fcm.googleapis.com/fcm/send/three',
        'public_key' => 'BPublicKeyExample',
        'auth_token' => 'AuthExample',
        'content_encoding' => 'aes128gcm',
    ]);

    $this->actingAs($this->tapu)
        ->put('/profile/password', [
            'current_password' => env('SEED_PASSWORD'),
            'password' => 'a-brand-new-long-passphrase',
            'password_confirmation' => 'a-brand-new-long-passphrase',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(PushSubscription::query()->where('user_id', $this->tapu->id)->count())->toBe(0);
});
