<?php

use App\Events\InboxActivity;
use App\Models\Conversation;
use App\Models\Project;
use App\Models\User;
use App\Services\ConversationService;
use App\Services\MessageService;
use App\Support\UserStatus;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| The inbox doorbell (decision 12-78)
|--------------------------------------------------------------------------
|
| Every posted message rings `inbox.message` on the own `notifications.{user}`
| channel of each person the policy lets view the conversation — author
| excluded, inactive users excluded, ids only, after commit.
|
| Helpers are global in Pest, so everything here is prefixed INBOX_ACTIVITY_.
|
*/

const INBOX_ACTIVITY_BODY = 'Ringing the inbox, not the thread.';

beforeEach(function () {
    $this->seed();
    Storage::fake(config('filesystems.default'));

    $this->conversations = app(ConversationService::class);
    $this->messages = app(MessageService::class);

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->yaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();
    $this->accountant = User::where('email', 'accountant@goodtechies.test')->firstOrFail();
});

/**
 * The one InboxActivity a post dispatched, under `Event::fake()`.
 */
function INBOX_ACTIVITY_dispatched(): InboxActivity
{
    Event::assertDispatchedTimes(InboxActivity::class, 1);

    return Event::dispatched(InboxActivity::class)->first()[0];
}

/**
 * @return list<string>
 */
function INBOX_ACTIVITY_channels(InboxActivity $event): array
{
    return array_map(fn ($channel): string => (string) $channel, $event->broadcastOn());
}

/**
 * The policy's own answer: every active user except the author who may view the conversation.
 *
 * @return list<string>
 */
function INBOX_ACTIVITY_expected(Conversation $conversation, User $author): array
{
    return User::query()
        ->where('status', UserStatus::Active)
        ->whereKeyNot($author->getKey())
        ->orderBy('id')
        ->get()
        ->filter(fn (User $user): bool => Gate::forUser($user)->allows('view', $conversation))
        ->map(fn (User $user): string => 'private-notifications.'.$user->getKey())
        ->values()
        ->all();
}

it('rings only the other participant of a DM', function () {
    $dm = $this->conversations->dmBetween($this->admin, $this->yaseen);

    Event::fake([InboxActivity::class]);

    $message = $this->messages->post($this->admin, $dm, INBOX_ACTIVITY_BODY);

    $event = INBOX_ACTIVITY_dispatched();

    expect($event->conversationId)->toBe((int) $dm->getKey())
        ->and($event->messageId)->toBe((int) $message->getKey())
        ->and($event->authorId)->toBe((int) $this->admin->getKey())
        ->and(INBOX_ACTIVITY_channels($event))->toBe(['private-notifications.'.$this->yaseen->getKey()]);
})->group('realtime');

it('rings exactly the project channel viewers the policy allows, minus the author', function () {
    // A project the employee Yaseen cannot see: he is the non-member under test.
    $project = Project::query()->orderBy('id')->get()
        ->first(fn (Project $candidate): bool => Gate::forUser($this->yaseen)->denies('view', $candidate));

    expect($project)->not->toBeNull();

    $channel = $this->conversations->forProject($project);

    Event::fake([InboxActivity::class]);

    $this->messages->post($this->admin, $channel, INBOX_ACTIVITY_BODY);

    $recipients = INBOX_ACTIVITY_channels(INBOX_ACTIVITY_dispatched());

    expect($recipients)->toBe(INBOX_ACTIVITY_expected($channel, $this->admin))
        ->and($recipients)->not->toBeEmpty()
        ->and($recipients)->not->toContain('private-notifications.'.$this->admin->getKey())
        ->and($recipients)->not->toContain('private-notifications.'.$this->yaseen->getKey())
        ->and($recipients)->not->toContain('private-notifications.'.$this->accountant->getKey());
})->group('realtime');

it('puts exactly the two ids in the frame', function () {
    $payload = (new InboxActivity(12, 41, 3))->broadcastWith();

    expect(array_keys($payload))->toBe(['conversation_id', 'message_id'])
        ->and($payload)->toBe(['conversation_id' => 12, 'message_id' => 41]);
})->group('realtime');

it('is named inbox.message on the wire', function () {
    expect((new InboxActivity(12, 41, 3))->broadcastAs())->toBe('inbox.message');
})->group('realtime');

it('is dispatched only after the commit', function () {
    expect(new InboxActivity(12, 41, 3))->toBeInstanceOf(ShouldDispatchAfterCommit::class);
})->group('realtime');

it('never rings an inactive user', function () {
    $team = $this->conversations->team();
    $yaseensChannel = 'private-notifications.'.$this->yaseen->getKey();

    Event::fake([InboxActivity::class]);

    // Active, Yaseen is rung — so the absence below is the status, not the room.
    $this->messages->post($this->admin, $team, INBOX_ACTIVITY_BODY);
    expect(INBOX_ACTIVITY_channels(INBOX_ACTIVITY_dispatched()))->toContain($yaseensChannel);

    $this->yaseen->forceFill(['status' => UserStatus::Inactive])->save();

    $event = new InboxActivity((int) $team->getKey(), 1, (int) $this->admin->getKey());

    expect(INBOX_ACTIVITY_channels($event))->not->toContain($yaseensChannel)
        ->and(INBOX_ACTIVITY_channels($event))->not->toBeEmpty();
})->group('realtime');
