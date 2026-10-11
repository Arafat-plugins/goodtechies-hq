<?php

use App\Models\AuditLog;
use App\Models\User;
use App\Services\ConversationService;
use App\Services\MessageService;
use App\Support\AuditEvent;

/*
| Polish 044: the app-wide announcement banner is the PINNED announcement — one at a time, set
| by an Admin from the message's menu or taken off with the banner's Unpin button. Nothing
| pinned, or the pinned message deleted, means no banner.
*/

beforeEach(function () {
    $this->seed();

    $this->conversations = app(ConversationService::class);
    $this->messages = app(MessageService::class);
    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();
    $this->channel = $this->conversations->announcements();
    $this->url = fn ($message) => "/messages/{$this->channel->id}/messages/{$message->id}/pin";
});

function pinBanner($test, User $user): ?array
{
    return $test->actingAs($user)->get('/messages')->assertOk()->inertiaProps()['announcement'];
}

it('shows no banner until an announcement is pinned', function () {
    $this->messages->post($this->admin, $this->channel, 'Office closed on Thursday.');

    expect(pinBanner($this, $this->tapu))->toBeNull();
});

it('lets the Admin pin an announcement, which becomes the banner for everybody', function () {
    $message = $this->messages->post($this->admin, $this->channel, 'Office closed on Thursday.');

    $this->actingAs($this->admin)->postJson(($this->url)($message))
        ->assertOk()
        ->assertJsonPath('message.is_pinned', true)
        ->assertJsonPath('message.can_pin', true);

    $banner = pinBanner($this, $this->tapu);

    expect($banner['message_id'])->toBe($message->id)
        ->and($banner['body'])->toBe('Office closed on Thursday.')
        ->and($banner['can_unpin'])->toBeFalse()
        ->and(pinBanner($this, $this->admin)['can_unpin'])->toBeTrue()
        ->and(AuditLog::where('event', AuditEvent::MessagePinned->value)->count())->toBe(1);
});

it('keeps one pin at a time: pinning another replaces it', function () {
    $first = $this->messages->post($this->admin, $this->channel, 'First');
    $second = $this->messages->post($this->admin, $this->channel, 'Second');

    $this->actingAs($this->admin)->postJson(($this->url)($first))->assertOk();
    $this->actingAs($this->admin)->postJson(($this->url)($second))->assertOk();

    expect(pinBanner($this, $this->tapu)['message_id'])->toBe($second->id)
        ->and($this->channel->fresh()->pinned_message_id)->toBe($second->id);
});

it('takes the banner away when unpinned', function () {
    $message = $this->messages->post($this->admin, $this->channel, 'Office closed on Thursday.');
    $this->messages->pin($this->admin, $message, true);

    $this->actingAs($this->admin)->deleteJson(($this->url)($message))
        ->assertOk()
        ->assertJsonPath('message.is_pinned', false);

    expect(pinBanner($this, $this->tapu))->toBeNull()
        ->and(AuditLog::where('event', AuditEvent::MessageUnpinned->value)->count())->toBe(1);
});

it('takes the banner away when the pinned announcement is deleted', function () {
    $message = $this->messages->post($this->admin, $this->channel, 'Office closed on Thursday.');
    $this->messages->pin($this->admin, $message, true);

    $this->actingAs($this->admin)->deleteJson("/messages/{$this->channel->id}/messages/{$message->id}")->assertOk();

    expect(pinBanner($this, $this->tapu))->toBeNull()
        ->and($this->channel->fresh()->pinned_message_id)->toBeNull();
});

it('refuses pinning to people who cannot post announcements, and outside the announcements channel', function () {
    $message = $this->messages->post($this->admin, $this->channel, 'Office closed on Thursday.');

    $this->actingAs($this->tapu)->postJson(($this->url)($message))->assertForbidden();

    $team = $this->conversations->team();
    $chat = $this->messages->post($this->admin, $team, 'Standup at 10');

    $this->actingAs($this->admin)
        ->postJson("/messages/{$team->id}/messages/{$chat->id}/pin")
        ->assertForbidden();

    expect($this->channel->fresh()->pinned_message_id)->toBeNull();
});
