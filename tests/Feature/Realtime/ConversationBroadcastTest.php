<?php

use App\Events\ConversationActivity;
use App\Exceptions\FileStateException;
use App\Models\Conversation;
use App\Models\Notification;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\ConversationService;
use App\Services\MessageService;
use App\Support\NotificationType;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| A posted message rings the conversation channel
|--------------------------------------------------------------------------
|
| POLISH-BACKLOG §A.2, item 1, in the client's words: they sent a message as
| Shahadat to Yaseen and Yaseen saw nothing until he reloaded. The channel
| `conversation.{id}` and its policy-backed auth callback were built in Phase 6
| and NOTHING EVER BROADCAST ON THEM — the seam was in place and the thing that
| hangs off it was never built.
|
| This file is the server half of that. `ConversationBroadcaster` turns one
| `MessagePosted` into one `ConversationActivity`, which broadcasts two ids on
| the channel that already existed.
|
| ## The frame is a doorbell, not a payload
|
| `broadcastWith()` is `conversation_id` and `message_id` and nothing else. The
| screen answers a ping by re-reading `GET /messages/{conversation}`, so what it
| paints is what the policy built. The KEY SET is asserted below, not just the
| values: that is the test that stops somebody adding the body to the frame and
| quietly making the socket a second place where "what may this person see" is
| decided.
|
| ## Constants and helpers are global in Pest
|
| So everything defined here is prefixed CONVERSATION_BROADCAST_.
|
*/

const CONVERSATION_BROADCAST_BODY = 'Pushed the redirect map to staging — have a look when you can.';

beforeEach(function () {
    $this->seed();
    Storage::fake(config('filesystems.default'));

    $this->conversations = app(ConversationService::class);
    $this->messages = app(MessageService::class);

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();
    $this->yaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();

    $this->tapusTask = Task::query()
        ->forEmployee($this->tapu->employee)
        ->notArchived()
        ->orderBy('id')
        ->firstOrFail();

    $this->tapusProject = Project::findOrFail($this->tapusTask->project_id);
});

/**
 * One room of each of the five types, and somebody entitled to post in it.
 *
 * The announcements channel is the one that needs a particular author — reading it is
 * `messages.use`, posting to it is `announcements.send` — so the actor travels with the room
 * rather than being assumed.
 *
 * @return array{0: Conversation, 1: User}
 */
function CONVERSATION_BROADCAST_room($test, string $kind): array
{
    return match ($kind) {
        'dm' => [$test->conversations->dmBetween($test->admin, $test->yaseen), $test->admin],
        'team' => [$test->conversations->team(), $test->tapu],
        'project' => [$test->conversations->forProject($test->tapusProject), $test->tapu],
        'task' => [$test->conversations->forTask($test->tapusTask), $test->tapu],
        'announcement' => [$test->conversations->announcements(), $test->admin],
    };
}

/**
 * Collect ConversationActivity through the REAL dispatcher.
 *
 * `Event::fake()` is the right tool for "was this dispatched, with what" and it is what the
 * tests below use. It cannot answer "does a rolled-back post broadcast", because `EventFake`
 * records an event the moment `dispatch()` is called and knows nothing about
 * `ShouldDispatchAfterCommit` — under a fake, a dispatch parked on a transaction and a dispatch
 * that happened look identical. So the transaction tests listen for real.
 *
 * @param  list<ConversationActivity>  $into
 */
function CONVERSATION_BROADCAST_collect(array &$into): void
{
    Event::listen(ConversationActivity::class, function (ConversationActivity $event) use (&$into): void {
        $into[] = $event;
    });
}

/*
|--------------------------------------------------------------------------
| Every conversation type rings, because there is one event behind all five
|--------------------------------------------------------------------------
|
| A DM, the team channel, a project channel, a task discussion and an
| announcement are all MessagePosted and all have a conversation.{id} channel.
| The listener has no `match` on the type and must never grow one — that is how
| four of the five end up working.
|
*/

it('broadcasts the two ids when a message is posted', function (string $kind) {
    [$conversation, $actor] = CONVERSATION_BROADCAST_room($this, $kind);

    Event::fake([ConversationActivity::class]);

    $message = $this->messages->post($actor, $conversation, CONVERSATION_BROADCAST_BODY);

    Event::assertDispatched(
        ConversationActivity::class,
        fn (ConversationActivity $event): bool => $event->conversationId === (int) $conversation->getKey()
            && $event->messageId === (int) $message->getKey(),
    );
    Event::assertDispatchedTimes(ConversationActivity::class, 1);
})->with([
    // The client's own case first: Shahadat → Yaseen, the DM that did not move.
    'dm' => ['dm'],
    'team channel' => ['team'],
    'project channel' => ['project'],
    'task discussion' => ['task'],
    'announcement' => ['announcement'],
])->group('phase6', 'realtime');

it('rings once per message, not once per recipient', function () {
    // The bell's event is per PERSON (NotificationFeedChanged) because a feed belongs to one
    // reader. This one is per ROOM: everybody watching the thread is on one channel, and a
    // per-recipient broadcast here would be N frames saying the same thing.
    $team = $this->conversations->team();

    Event::fake([ConversationActivity::class]);

    $this->messages->post($this->admin, $team, CONVERSATION_BROADCAST_BODY);
    $this->messages->post($this->tapu, $team, 'Looking now.');

    Event::assertDispatchedTimes(ConversationActivity::class, 2);
})->group('phase6', 'realtime');

/*
|--------------------------------------------------------------------------
| The frame itself
|--------------------------------------------------------------------------
*/

it('broadcasts on the private conversation channel that already existed', function () {
    $event = new ConversationActivity(12, 41);

    $channels = $event->broadcastOn();

    expect($channels)->toHaveCount(1)
        ->and($channels[0])->toBeInstanceOf(PrivateChannel::class)
        // `private-` is Laravel's prefix on the wire; the name the channel is REGISTERED under
        // in routes/channels.php is `conversation.{conversation}`, and this must match it or
        // the subscription authorises a channel nothing sends on.
        ->and((string) $channels[0])->toBe('private-conversation.12');
})->group('phase6', 'realtime');

it('is named conversation.message on the wire', function () {
    expect((new ConversationActivity(12, 41))->broadcastAs())->toBe('conversation.message');
})->group('phase6', 'realtime');

it('puts exactly two keys in the frame and nothing else', function () {
    // THE test of this slice. A socket subscriber is authorised for the ROOM
    // (ConversationPolicy::view); whether they may read a given MESSAGE is MessagePolicy's
    // question and it is asked by the HTTP read. The moment this frame carries the body or the
    // author, the frame becomes a second place that answers the second question — so the key
    // set is asserted, not just the values.
    $payload = (new ConversationActivity(12, 41))->broadcastWith();

    expect(array_keys($payload))->toBe(['conversation_id', 'message_id'])
        ->and($payload)->toBe([
            'conversation_id' => 12,
            'message_id' => 41,
        ]);
})->group('phase6', 'realtime');

it('carries no body, author or timestamp however the message was written', function () {
    // The same assertion made against a real post rather than a hand-built event, so a payload
    // assembled from the message later cannot slip past the unit-shaped test above.
    $dm = $this->conversations->dmBetween($this->admin, $this->yaseen);

    $collected = [];
    CONVERSATION_BROADCAST_collect($collected);

    $message = $this->messages->post(
        $this->admin,
        $dm,
        '@'.$this->yaseen->name.' '.CONVERSATION_BROADCAST_BODY,
        mentionIds: [$this->yaseen->id],
    );

    expect($collected)->toHaveCount(1);

    $payload = $collected[0]->broadcastWith();

    expect(array_keys($payload))->toBe(['conversation_id', 'message_id'])
        ->and($payload['message_id'])->toBe((int) $message->getKey())
        ->and(json_encode($payload))->not->toContain($this->admin->name)
        ->and(json_encode($payload))->not->toContain(CONVERSATION_BROADCAST_BODY);
})->group('phase6', 'realtime');

/*
|--------------------------------------------------------------------------
| A post that does not happen does not ring
|--------------------------------------------------------------------------
|
| MessageService::post() fires MessagePosted INSIDE the write transaction,
| deliberately, so a post that rolls back notifies nobody. Notifications inherit
| that for free — they are rows in the same transaction. A broadcast does not:
| queue connections here are `after_commit => false`, so an eagerly queued
| BroadcastEvent would already be on Redis when the transaction rolled back.
| ConversationActivity is ShouldDispatchAfterCommit for exactly that reason, and
| this is the test of it.
|
*/

it('broadcasts nothing when the write rolls back', function () {
    $dm = $this->conversations->dmBetween($this->admin, $this->yaseen);

    $collected = [];
    CONVERSATION_BROADCAST_collect($collected);

    // A successful post first, so the collector is known to be listening — a test that only
    // ever asserts "nothing happened" passes just as well when nothing is wired at all.
    $this->messages->post($this->admin, $dm, 'This one commits.');
    expect($collected)->toHaveCount(1);

    $collected = [];

    try {
        DB::transaction(function () use ($dm): void {
            $this->messages->post($this->admin, $dm, 'This one does not.');

            throw new RuntimeException('something later in the request failed');
        });
    } catch (RuntimeException) {
        // The caller's problem. Ours is that nothing was announced about it.
    }

    expect($collected)->toBe([])
        ->and(Conversation::find($dm->getKey())->messages()->where('body', 'This one does not.')->exists())->toBeFalse();
})->group('phase6', 'realtime');

it('broadcasts nothing when the policy refuses the post', function () {
    // The accountant holds no `messages.use`, so `post` is refused before the transaction opens.
    $dm = $this->conversations->dmBetween($this->admin, $this->yaseen);

    Event::fake([ConversationActivity::class]);

    expect(fn () => $this->messages->post($this->tapu, $dm, CONVERSATION_BROADCAST_BODY))
        ->toThrow(AuthorizationException::class);

    Event::assertNotDispatched(ConversationActivity::class);
})->group('phase6', 'realtime');

it('broadcasts nothing when the upload is refused', function () {
    $team = $this->conversations->team();

    Event::fake([ConversationActivity::class]);

    expect(fn () => $this->messages->post(
        $this->admin,
        $team,
        null,
        UploadedFile::fake()->create('payload.exe', 12, 'application/x-msdownload'),
    ))->toThrow(FileStateException::class);

    Event::assertNotDispatched(ConversationActivity::class);
})->group('phase6', 'realtime');

/*
|--------------------------------------------------------------------------
| A polling deployment is a first-class citizen
|--------------------------------------------------------------------------
|
| The client's own machine runs BROADCAST_CONNECTION=log with no Reverb
| (§A.2 item 3). Whatever is built has to be harmless there, or it does not work
| on their desk.
|
*/

it('writes the frame to the log on a polling deployment and does not fail the post', function () {
    config(['broadcasting.default' => 'log']);

    Log::spy();

    $dm = $this->conversations->dmBetween($this->admin, $this->yaseen);

    $message = $this->messages->post($this->admin, $dm, CONVERSATION_BROADCAST_BODY);

    // The post itself is untouched: the row is written and returned exactly as it is on a box
    // with a socket. The broadcast is a log line beside it.
    expect($message->exists)->toBeTrue()
        ->and($message->body)->toBe(CONVERSATION_BROADCAST_BODY);

    Log::shouldHaveReceived('info')
        ->withArgs(fn (string $line): bool => str_contains($line, 'private-conversation.'.$dm->getKey())
            && str_contains($line, 'conversation.message'))
        ->atLeast()->once();
})->group('phase6', 'realtime');

it('says the same thing on every connection', function () {
    // log, null and reverb are the three in config/broadcasting.php. The payload is assembled
    // from two ints, so there is nothing for a connection to change — asserted rather than
    // assumed, the way PollingFallbackTest asserts it for the bell.
    $frames = [];

    foreach (['reverb', 'log', 'null'] as $connection) {
        config(['broadcasting.default' => $connection]);

        $frames[$connection] = (new ConversationActivity(12, 41))->broadcastWith();
    }

    expect($frames['log'])->toBe($frames['reverb'])
        ->and($frames['null'])->toBe($frames['reverb']);
})->group('phase6', 'realtime');

it('hands the socket call to the queue, so a stopped Reverb cannot fail a post', function () {
    // ShouldBroadcast, not ShouldBroadcastNow. The HTTP call to Reverb happens in a worker: on
    // a box where Reverb is down it costs a retried job, not a 500 on a message that has
    // already been written. This is the same argument as NotificationFeedChanged's.
    config(['broadcasting.default' => 'reverb']);

    Queue::fake();

    $dm = $this->conversations->dmBetween($this->admin, $this->yaseen);

    $this->messages->post($this->admin, $dm, CONVERSATION_BROADCAST_BODY);

    Queue::assertPushed(
        BroadcastEvent::class,
        fn (BroadcastEvent $job): bool => $job->event instanceof ConversationActivity,
    );
})->group('phase6', 'realtime');

/*
|--------------------------------------------------------------------------
| The listener that was already there still does its job
|--------------------------------------------------------------------------
|
| One guard assertion, not a second copy of NotificationDispatcherTest: the
| point is only that a second subscriber on MessagePosted did not displace the
| first.
|
*/

it('leaves the notifications alone', function () {
    Notification::query()->delete();

    $dm = $this->conversations->dmBetween($this->admin, $this->yaseen);
    $this->messages->post($this->admin, $dm, CONVERSATION_BROADCAST_BODY);

    // A DM still notifies the other half.
    expect(Notification::where('user_id', $this->yaseen->getKey())
        ->where('type', NotificationType::MessageReceived->value)->count())->toBe(1);

    // A mention still notifies, and still suppresses the comment row for the same person (6-5).
    $discussion = $this->conversations->forTask($this->tapusTask);

    $this->messages->post(
        $this->admin,
        $discussion,
        '@'.$this->tapu->name.' is this one done?',
        mentionIds: [$this->tapu->id],
    );

    expect(Notification::where('user_id', $this->tapu->getKey())
        ->where('type', NotificationType::MessageMentioned->value)->count())->toBe(1)
        ->and(Notification::where('user_id', $this->tapu->getKey())
            ->where('type', NotificationType::TaskCommented->value)->count())->toBe(0);
})->group('phase6', 'realtime');
