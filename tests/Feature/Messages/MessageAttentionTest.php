<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Notification;
use App\Models\User;
use App\Services\ConversationService;
use App\Services\MessageService;
use App\Services\NotificationService;
use App\Support\NotificationType;
use Illuminate\Http\Request;

/*
|--------------------------------------------------------------------------
| Messaging polish: notifications follow what is actually on screen
|--------------------------------------------------------------------------
|
| A thread is marked read only when the reader is looking at it (`?read=1`, or a `through`
| mark), never by rendering the page or by a background re-read (`?read=0`). Reading a
| conversation reads the notifications about it, and the bell no longer carries message
| notifications at all — the top bar's Messages icon counts those, from the same read state.
*/

const ATTN_URL = '/messages';

beforeEach(function () {
    $this->seed();

    $this->conversations = app(ConversationService::class);
    $this->messages = app(MessageService::class);
    $this->notifications = app(NotificationService::class);

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();

    $this->team = $this->conversations->team();
    $this->dm = $this->conversations->dmBetween($this->tapu, $this->admin);

    // Start every test with Tapu caught up on both.
    $this->conversations->markRead($this->tapu, $this->team);
    $this->conversations->markRead($this->tapu, $this->dm);
});

function ATTN_unread(object $test, $conversation): int
{
    return $test->conversations->readState($test->tapu, $conversation)['unread_count'];
}

it('re-reads a thread without marking it when the reader is not looking', function () {
    $this->messages->post($this->admin, $this->team, 'Posted while Tapu was in another tab');

    $this->actingAs($this->tapu)
        ->getJson(ATTN_URL.'/'.$this->team->id.'?read=0')
        ->assertOk()
        ->assertJsonPath('unread_count', 1);

    expect(ATTN_unread($this, $this->team))->toBe(1);

    $this->actingAs($this->tapu)->getJson(ATTN_URL.'/'.$this->team->id.'?read=1')->assertOk();

    expect(ATTN_unread($this, $this->team))->toBe(0);
})->group('messaging-polish');

it('does not mark the open thread read just by rendering the page', function () {
    $this->messages->post($this->admin, $this->team, 'Opened in a background tab');

    $this->actingAs($this->tapu)
        ->get(ATTN_URL.'?conversation='.$this->team->id)
        ->assertOk();

    expect(ATTN_unread($this, $this->team))->toBe(1);
})->group('messaging-polish');

it('marks read through the message the reader saw and no further', function () {
    $seen = $this->messages->post($this->admin, $this->team, 'On screen');
    $this->travel(1)->seconds();
    $this->messages->post($this->admin, $this->team, 'Not drawn yet');

    $this->actingAs($this->tapu)
        ->postJson(ATTN_URL.'/'.$this->team->id.'/read', ['through' => $seen->id])
        ->assertNoContent();

    expect(ATTN_unread($this, $this->team))->toBe(1);
})->group('messaging-polish');

it('never moves the unread line backwards', function () {
    $first = $this->messages->post($this->admin, $this->team, 'One');
    $this->travel(1)->seconds();
    $second = $this->messages->post($this->admin, $this->team, 'Two');

    $this->actingAs($this->tapu)->postJson(ATTN_URL.'/'.$this->team->id.'/read', ['through' => $second->id])->assertNoContent();
    $this->actingAs($this->tapu)->postJson(ATTN_URL.'/'.$this->team->id.'/read', ['through' => $first->id])->assertNoContent();

    expect(ATTN_unread($this, $this->team))->toBe(0);
})->group('messaging-polish');

it('ignores a through id from another conversation', function () {
    $elsewhere = $this->messages->post($this->admin, $this->dm, 'In the DM');
    $this->messages->post($this->admin, $this->team, 'In the team channel');

    $this->actingAs($this->tapu)
        ->postJson(ATTN_URL.'/'.$this->team->id.'/read', ['through' => $elsewhere->id])
        ->assertNoContent();

    expect(ATTN_unread($this, $this->team))->toBe(1);
})->group('messaging-polish');

it('keeps direct messages out of the bell and clears them when the conversation is read', function () {
    $this->messages->post($this->admin, $this->dm, 'Are you there?');

    $row = Notification::query()->forUser($this->tapu)->where('type', NotificationType::MessageReceived->value)->first();

    expect($row)->not->toBeNull()
        ->and($row->is_read)->toBeFalse();

    $feed = $this->actingAs($this->tapu)->getJson('/notifications/recent')->assertOk()->json();

    expect(collect($feed['notifications'])->pluck('type'))->not->toContain(NotificationType::MessageReceived->value)
        ->and($feed['unread_count'])->toBe($this->notifications->unreadCount($this->tapu));

    $this->actingAs($this->tapu)->getJson(ATTN_URL.'/'.$this->dm->id.'?read=1')->assertOk();

    expect($row->fresh()->is_read)->toBeTrue();
})->group('messaging-polish');

it('leaves the notification unread while newer messages are still unread', function () {
    $first = $this->messages->post($this->admin, $this->dm, 'First');
    $this->travel(1)->seconds();
    $this->messages->post($this->admin, $this->dm, 'Second');

    $this->actingAs($this->tapu)
        ->postJson(ATTN_URL.'/'.$this->dm->id.'/read', ['through' => $first->id])
        ->assertNoContent();

    $row = Notification::query()->forUser($this->tapu)->where('type', NotificationType::MessageReceived->value)->firstOrFail();

    expect($row->is_read)->toBeFalse();
})->group('messaging-polish');

it('counts several messages that arrived while the reader was away', function () {
    foreach (['One', 'Two', 'Three'] as $body) {
        $this->messages->post($this->admin, $this->dm, $body);
    }

    $this->actingAs($this->tapu)->getJson(ATTN_URL.'/'.$this->dm->id.'?read=0')->assertOk();

    expect(ATTN_unread($this, $this->dm))->toBe(3);
})->group('messaging-polish');

it('opens the newest unread conversation for the top bar icon', function () {
    $this->messages->post($this->admin, $this->team, 'Older, unread');
    $this->travel(1)->seconds();
    $this->messages->post($this->admin, $this->dm, 'Newer, unread');

    $props = $this->actingAs($this->tapu)->get(ATTN_URL.'?unread=1')->assertOk()->inertiaPage()['props'];

    expect($props['active']['conversation_id'])->toBe($this->dm->id);
})->group('messaging-polish');

it('sends per-conversation unread counts with the shell, only where there is something unread', function () {
    $this->messages->post($this->admin, $this->dm, 'Hello');

    $shell = $this->actingAs($this->tapu)->get('/employee/dashboard', [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(Request::create('/')),
        'X-Inertia-Partial-Component' => 'Employee/Dashboard',
        'X-Inertia-Partial-Data' => 'shell',
    ])->assertOk()->json('props.shell');

    expect($shell['unreadByConversation'])->toBe([(string) $this->dm->id => 1])
        ->and($shell['unreadMessages'])->toBe(1);
})->group('messaging-polish');
