<?php

use App\Events\NotificationFeedChanged;
use App\Models\Notification;
use App\Models\Task;
use App\Models\User;
use App\Services\NotificationService;
use App\Support\NotificationType;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

/*
|--------------------------------------------------------------------------
| The polling fallback returns the same payload
|--------------------------------------------------------------------------
|
| Part E, Phase 6: "Polling fallback via `.env` `BROADCAST_CONNECTION=log` +
| `VITE_REALTIME=polling` (build-time)" and "polling fallback returns the same
| payload".
|
| The fallback is a supported mode, not dead code: Part B says a 10–15 s poll
| is acceptable in the MVP "if Reverb is troublesome", and the VPS may well run
| that way for a week while somebody works out why the socket will not stay up.
| So the two transports have to be two ways of hearing ONE payload, and that is
| enforced structurally — `NotificationService::feed()` builds it,
| `NotificationController::recent()` returns it and
| `NotificationFeedChanged::broadcastWith()` broadcasts it. This file asserts
| they are identical rather than trusting that sentence.
|
| The trap it exists for is real and was live for an afternoon: a notification's
| deep link is resolved **against the reader's own surface**, off the request —
| and a broadcast is assembled in a queued job with no request, so every link
| came back null and the socket's payload was quietly the poorer of the two.
| `feed()` takes an optional request for exactly that reason, and the third test
| below is the one that would have caught it.
|
*/

beforeEach(function () {
    $this->seed();

    $this->notifications = app(NotificationService::class);

    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();
    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();

    $this->task = Task::query()->forEmployee($this->tapu->employee)->notArchived()->orderBy('id')->firstOrFail();

    Notification::query()->delete();
});

/** Two notifications of Tapu's, one grouped, so the payload has something to differ about. */
function realtimeSeedFeed(NotificationService $service, Task $task, User $tapu, User $admin): void
{
    $service->notify(NotificationType::TaskAssigned, $task, [$tapu], ['title' => $task->title], $admin);
    test()->travel(5)->minutes();
    $service->notify(NotificationType::TaskCommented, $task, [$tapu], ['title' => $task->title], $admin);
    $service->notify(NotificationType::TaskCommented, $task, [$tapu], ['title' => $task->title], $admin);
}

it('broadcasts exactly what the poll returns', function () {
    realtimeSeedFeed($this->notifications, $this->task, $this->tapu, $this->admin);

    $polled = $this->actingAs($this->tapu)->getJson('/notifications/recent')->assertOk()->json();

    // The socket's half, assembled the way the queued job assembles it: no request, no session,
    // nothing but the user the event names.
    $broadcast = (new NotificationFeedChanged($this->tapu))->broadcastWith();

    expect($broadcast)->toBe($polled);
})->group('phase6', 'realtime');

it('says the same thing whichever connection is configured', function () {
    realtimeSeedFeed($this->notifications, $this->task, $this->tapu, $this->admin);

    $payloads = [];

    foreach (['reverb', 'log', 'null'] as $connection) {
        config(['broadcasting.default' => $connection]);

        $payloads[$connection] = [
            'polled' => $this->actingAs($this->tapu)->getJson('/notifications/recent')->assertOk()->json(),
            'broadcast' => (new NotificationFeedChanged($this->tapu))->broadcastWith(),
        ];
    }

    // Six assemblies of the same feed, three connections, two transports. One answer.
    expect($payloads['log'])->toBe($payloads['reverb'])
        ->and($payloads['null'])->toBe($payloads['reverb'])
        ->and($payloads['reverb']['broadcast'])->toBe($payloads['reverb']['polled']);
})->group('phase6', 'realtime');

it('resolves the deep link in the broadcast payload, not only in the polled one', function () {
    // The specific regression: `NotificationResource` builds `/employee/tasks/41` for Tapu and
    // `/admin/tasks/41` for an Admin, off `$request->user()`. A broadcast has no request.
    realtimeSeedFeed($this->notifications, $this->task, $this->tapu, $this->admin);

    $broadcast = (new NotificationFeedChanged($this->tapu))->broadcastWith();

    expect($broadcast['notifications'][0]['link'])->toBe(url('/employee/tasks/'.$this->task->getKey()));

    // And it is the READER's surface, not the task's: the same row broadcast to an Admin points
    // at the Admin screen. Two people, one task, two links, neither one stored.
    $this->notifications->notify(NotificationType::TaskCommented, $this->task, [$this->admin], ['title' => $this->task->title], $this->tapu);

    $adminBroadcast = (new NotificationFeedChanged($this->admin))->broadcastWith();

    expect($adminBroadcast['notifications'][0]['link'])->toBe(url('/admin/tasks/'.$this->task->getKey()));
})->group('phase6', 'realtime');

it('writes the payload to the log when the log connection is the fallback', function () {
    // `log` is what a VPS without Reverb runs, and it is the reason the payload is still built:
    // somebody working out why the bell is quiet can read what would have been sent.
    config(['broadcasting.default' => 'log']);

    Log::spy();

    realtimeSeedFeed($this->notifications, $this->task, $this->tapu, $this->admin);

    Log::shouldHaveReceived('info')
        ->withArgs(fn (string $message): bool => str_contains($message, 'private-notifications.'.$this->tapu->getKey())
            && str_contains($message, 'feed.changed'))
        ->atLeast()->once();
})->group('phase6', 'realtime');

/*
|--------------------------------------------------------------------------
| Every write announces, and only to the person whose feed changed
|--------------------------------------------------------------------------
*/

it('announces to each recipient when a notification is delivered', function () {
    Event::fake([NotificationFeedChanged::class]);

    $this->notifications->notify(
        NotificationType::TaskCommented,
        $this->task,
        [$this->tapu, $this->admin],
        ['title' => $this->task->title],
    );

    Event::assertDispatched(
        NotificationFeedChanged::class,
        fn (NotificationFeedChanged $event): bool => $event->user->is($this->tapu),
    );
    Event::assertDispatched(
        NotificationFeedChanged::class,
        fn (NotificationFeedChanged $event): bool => $event->user->is($this->admin),
    );
    Event::assertDispatchedTimes(NotificationFeedChanged::class, 2);
})->group('phase6', 'realtime');

it('announces a grouped delivery too', function () {
    // The row did not appear — its count went up and its summary now reads "2 new comments in
    // …", which is a different sentence on the same bell.
    $this->notifications->notify(NotificationType::TaskCommented, $this->task, [$this->tapu], ['title' => $this->task->title]);

    Event::fake([NotificationFeedChanged::class]);

    $this->notifications->notify(NotificationType::TaskCommented, $this->task, [$this->tapu], ['title' => $this->task->title]);

    Event::assertDispatchedTimes(NotificationFeedChanged::class, 1);
})->group('phase6', 'realtime');

it('announces when a row is marked read, and not twice for a row that already was', function () {
    $notification = $this->notifications
        ->notify(NotificationType::TaskAssigned, $this->task, [$this->tapu], ['title' => $this->task->title])
        ->first();

    Event::fake([NotificationFeedChanged::class]);

    $this->notifications->markRead($this->tapu, $notification);
    $this->notifications->markRead($this->tapu, $notification->fresh());

    Event::assertDispatchedTimes(NotificationFeedChanged::class, 1);
})->group('phase6', 'realtime');

it('announces mark-all-read once, and not at all when there was nothing unread', function () {
    $this->notifications->notify(NotificationType::TaskAssigned, $this->task, [$this->tapu], ['title' => $this->task->title]);

    Event::fake([NotificationFeedChanged::class]);

    $this->notifications->markAllRead($this->tapu);
    $this->notifications->markAllRead($this->tapu);

    Event::assertDispatchedTimes(NotificationFeedChanged::class, 1);
})->group('phase6', 'realtime');

it('announces to the actor when their own rows are resolved, even though nobody was notified', function () {
    // Decision 2-48's case, and the one the bell would otherwise get wrong: Shahadat is the
    // reviewer, the creator and the actor, so approving writes ZERO rows — and takes a review
    // request out of his own bell. A bell that only updated when somebody else was told would
    // keep showing it.
    $this->notifications->notify(
        NotificationType::TaskSubmittedForReview,
        $this->task,
        [$this->admin],
        ['title' => $this->task->title],
        $this->tapu,
    );

    Event::fake([NotificationFeedChanged::class]);

    $this->notifications->notify(
        NotificationType::TaskCompleted,
        $this->task,
        [],
        ['title' => $this->task->title],
        $this->admin,
    );

    Event::assertDispatched(
        NotificationFeedChanged::class,
        fn (NotificationFeedChanged $event): bool => $event->user->is($this->admin),
    );
    Event::assertDispatchedTimes(NotificationFeedChanged::class, 1);
})->group('phase6', 'realtime');
