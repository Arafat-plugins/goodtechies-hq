<?php

use App\Events\TaskCompleted;
use App\Events\TaskStatusChanged;
use App\Events\TaskSubmittedForReview;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskService;
use App\Support\TaskStatus;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

/*
|--------------------------------------------------------------------------
| A task that moves rings its own channel — with its id and nothing else
|--------------------------------------------------------------------------
|
| POLISH-BACKLOG §A.3, the task-detail line: *"status changes already broadcast
| and should paint"*. They broadcast from Phase 2 and nothing painted, and when
| Phase 12's polish pass made the screen paint it found two things wrong on the
| server side of the same seam.
|
| ## The frame is a doorbell, not a payload
|
| `broadcastWith()` carried `status`, `status_label`, `tone` and `at`, and the
| task detail page composed a sentence out of `status_label`. Nothing leaked —
| every one of the four was in the `TaskResource` payload the same reader already
| held — but a screen that PAINTS out of a frame is a screen whose correctness
| depends on the frame staying as narrow as the policy, and the policy is not in
| the frame. It is one id now, and the screen re-reads. The KEY SET is asserted
| below, not just the values: that is the test that stops the label coming back.
|
| ## `ShouldDispatchAfterCommit` is load-bearing and was missing
|
| `TaskService::transition()` fires all three of these INSIDE its write
| transaction, and `config/queue.php` sets `after_commit => false` on all four
| connections (§E.4). So a plain `ShouldBroadcast` pushes its BroadcastEvent job
| the instant the event is dispatched — and `transition()` can still throw after
| that point, or a caller can roll the whole request back, in which case every
| open board has already been told a card moved that did not.
|
| `Event::fake()` cannot detect this. `EventFake` records a dispatch the moment
| `dispatch()` is called and knows nothing about the commit it is parked on, so
| under a fake a deferred dispatch and an eager one look identical. The
| transaction tests below therefore listen for real, through
| `TASK_BROADCAST_collect()`.
|
| ## Constants and helpers are global in Pest
|
| So everything defined here is prefixed TASK_BROADCAST_.
|
*/

/** The three events that share `BroadcastsTaskStatus`. One move fires exactly one of them. */
const TASK_BROADCAST_EVENTS = [
    TaskStatusChanged::class,
    TaskSubmittedForReview::class,
    TaskCompleted::class,
];

beforeEach(function () {
    $this->seed();

    $this->tasks = app(TaskService::class);
    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();

    // A task sitting in Backlog, so `Backlog → Todo` is available and is the plain
    // TaskStatusChanged rather than either of the two destinations with their own event.
    $this->task = Task::query()
        ->notArchived()
        ->where('status', TaskStatus::Backlog->value)
        ->orderBy('id')
        ->firstOrFail();
});

/**
 * Collect the status events through the REAL dispatcher.
 *
 * See the header: a fake cannot answer "does a rolled-back transition broadcast", because it
 * records the dispatch rather than the commit. Every test that is about the transaction listens
 * for real; the ones that are only about the frame build the event by hand.
 *
 * @param  list<object>  $into
 */
function TASK_BROADCAST_collect(array &$into): void
{
    foreach (TASK_BROADCAST_EVENTS as $event) {
        Event::listen($event, function (object $fired) use (&$into): void {
            $into[] = $fired;
        });
    }
}

/*
|--------------------------------------------------------------------------
| The frame
|--------------------------------------------------------------------------
*/

it('puts exactly one key in the frame and nothing else', function (string $class) {
    // THE test of this slice. A subscriber is authorised for the TASK (TaskPolicy::view, which
    // is `TaskChannel`'s callback); what they may then be told about it is `TaskResource`'s
    // question and it is asked by the HTTP read. The moment this frame carries the status or
    // its label, the frame becomes a second place that answers the second question.
    $payload = (new $class(
        $this->task,
        $this->admin,
        TaskStatus::Backlog,
        TaskStatus::Todo,
    ))->broadcastWith();

    expect(array_keys($payload))->toBe(['task_id'])
        ->and($payload)->toBe(['task_id' => (int) $this->task->getKey()]);
})->with([
    // Only the general event takes four constructor arguments; the other two are covered by the
    // real-transition test below, which is the stronger assertion anyway.
    'status changed' => [TaskStatusChanged::class],
])->group('phase12', 'realtime');

it('is named status.changed on the wire, whichever of the three fired', function () {
    $names = [
        (new TaskStatusChanged($this->task, $this->admin, TaskStatus::Backlog, TaskStatus::Todo))->broadcastAs(),
        (new TaskSubmittedForReview($this->task, $this->admin))->broadcastAs(),
        (new TaskCompleted($this->task, $this->admin))->broadcastAs(),
    ];

    // One name for all three: a screen watching a task wants "this task moved" and should not
    // have to know which of §11's notifications went out beside it.
    expect($names)->toBe(['status.changed', 'status.changed', 'status.changed']);
})->group('phase12', 'realtime');

it('broadcasts on the private task channel that already existed', function () {
    $channels = (new TaskCompleted($this->task, $this->admin))->broadcastOn();

    expect($channels)->toHaveCount(1)
        ->and($channels[0])->toBeInstanceOf(PrivateChannel::class)
        // Exactly the string `routes/channels.php` binds to `TaskChannel`, whose callback is
        // `TaskPolicy::view`. No new channel was invented for this.
        ->and($channels[0]->name)->toBe('private-task.'.$this->task->getKey());
})->group('phase12', 'realtime');

it('carries no status, label, tone or timestamp however the task moved', function () {
    // The same assertion made against a real transition rather than a hand-built event, so a
    // payload assembled from the task later cannot slip past the unit-shaped test above.
    $collected = [];
    TASK_BROADCAST_collect($collected);

    $this->tasks->transition($this->admin, $this->task, TaskStatus::Todo);

    expect($collected)->toHaveCount(1);

    $payload = $collected[0]->broadcastWith();
    $json = json_encode($payload);

    expect(array_keys($payload))->toBe(['task_id'])
        ->and($payload['task_id'])->toBe((int) $this->task->getKey())
        ->and($json)->not->toContain('todo')
        ->and($json)->not->toContain(TaskStatus::Todo->label())
        ->and($json)->not->toContain(TaskStatus::Todo->tone())
        ->and($json)->not->toContain($this->admin->name);
})->group('phase12', 'realtime');

it('fires one event per move and not two', function () {
    // `transition()` picks ONE of the three — a move to In review fires TaskSubmittedForReview
    // INSTEAD of TaskStatusChanged, never as well. The transport inherits that for free, and a
    // second frame for one move would make the board re-read twice for one card.
    $collected = [];
    TASK_BROADCAST_collect($collected);

    $this->tasks->transition($this->admin, $this->task, TaskStatus::Todo);
    $this->tasks->transition($this->admin, $this->task->refresh(), TaskStatus::InProgress);
    $this->tasks->transition(
        $this->admin,
        $this->task->refresh(),
        TaskStatus::InReview,
        workSummary: 'Redirect map pushed to staging and spot-checked.',
    );

    expect($collected)->toHaveCount(3)
        ->and($collected[0])->toBeInstanceOf(TaskStatusChanged::class)
        ->and($collected[1])->toBeInstanceOf(TaskStatusChanged::class)
        ->and($collected[2])->toBeInstanceOf(TaskSubmittedForReview::class);
})->group('phase12', 'realtime');

/*
|--------------------------------------------------------------------------
| A move that does not happen does not ring
|--------------------------------------------------------------------------
*/

it('declares ShouldDispatchAfterCommit on every status event', function (string $class) {
    // The cheap structural guard, so a fourth status event added next phase cannot ship without
    // it. The behavioural test is below; this one is what fails in the same commit as the
    // mistake rather than in the phase after it.
    $interfaces = class_implements($class);

    expect($interfaces)->toHaveKey(ShouldBroadcast::class)
        ->and($interfaces)->toHaveKey(ShouldDispatchAfterCommit::class);
})->with([
    'status changed' => [TaskStatusChanged::class],
    'submitted for review' => [TaskSubmittedForReview::class],
    'completed' => [TaskCompleted::class],
])->group('phase12', 'realtime');

it('broadcasts nothing when the transition rolls back', function () {
    $collected = [];
    TASK_BROADCAST_collect($collected);

    // A successful move first, so the collector is known to be listening — a test that only
    // ever asserts "nothing happened" passes just as well when nothing is wired at all.
    $this->tasks->transition($this->admin, $this->task, TaskStatus::Todo);
    expect($collected)->toHaveCount(1);

    $collected = [];
    $before = $this->task->refresh()->status;

    try {
        DB::transaction(function (): void {
            $this->tasks->transition($this->admin, $this->task->refresh(), TaskStatus::InProgress);

            throw new RuntimeException('something later in the request failed');
        });
    } catch (RuntimeException) {
        // The caller's problem. Ours is that no board was told a card moved that did not.
    }

    expect($collected)->toBe([])
        ->and($this->task->refresh()->status)->toBe($before);
})->group('phase12', 'realtime');

/*
|--------------------------------------------------------------------------
| The socket call is the queue's problem, not the drag's
|--------------------------------------------------------------------------
*/

it('hands the socket call to the queue, so a stopped Reverb cannot fail a drag', function () {
    // ShouldBroadcast, not ShouldBroadcastNow. On a box where Reverb is down this costs a
    // retried job rather than a 500 on the drag that already moved the card — the same argument
    // as NotificationFeedChanged's and ConversationActivity's.
    config(['broadcasting.default' => 'reverb']);

    Queue::fake();

    $this->tasks->transition($this->admin, $this->task, TaskStatus::Todo);

    Queue::assertPushed(
        BroadcastEvent::class,
        fn (BroadcastEvent $job): bool => $job->event instanceof TaskStatusChanged,
    );
})->group('phase12', 'realtime');

it('says the same thing on every broadcast connection', function () {
    // A polling deployment is a first-class citizen (§A.4). `log` and `null` are what a machine
    // without Reverb is configured with, and the frame must not depend on which one is set — the
    // screens re-read either way and the only difference is how soon.
    $frames = [];

    foreach (['reverb', 'log', 'null'] as $connection) {
        config(['broadcasting.default' => $connection]);

        $frames[$connection] = (new TaskStatusChanged(
            $this->task,
            $this->admin,
            TaskStatus::Backlog,
            TaskStatus::Todo,
        ))->broadcastWith();
    }

    expect($frames['log'])->toBe($frames['reverb'])
        ->and($frames['null'])->toBe($frames['reverb']);
})->group('phase12', 'realtime');
