<?php

use App\Models\Notification;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\SettingsService;
use App\Support\NotificationChannel;
use App\Support\NotificationTab;
use App\Support\NotificationType;
use Database\Seeders\SettingsSeeder;

/*
|--------------------------------------------------------------------------
| The notification engine
|--------------------------------------------------------------------------
|
| Master prompt §11: event → recipients → priority → dedup/group → stored row.
| The two halves worth proving here are the two that are easy to get wrong and
| impossible to see afterwards:
|
|   - GROUPING HAPPENS AT DISPATCH. Twelve events inside the window are ONE
|     row carrying count: 12, written that way. A screen that grouped at
|     display time would look identical and be wrong, so the assertions below
|     count ROWS in the database, never rendered output.
|   - RECIPIENTS COME FROM PERMISSIONS. The Accountant is never named by these
|     tests as a role to exclude; they are handed to notify() like anybody
|     else and fall out because they hold no tasks.* key.
|
*/

beforeEach(function () {
    $this->seed();

    $this->notifications = app(NotificationService::class);
    $this->settings = app(SettingsService::class);

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();
    $this->yaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();
    $this->accountant = User::where('email', 'accountant@goodtechies.test')->firstOrFail();

    $this->task = Task::query()->forEmployee($this->tapu->employee)->notArchived()->orderBy('id')->firstOrFail();

    // These tests count rows absolutely, so they start from an empty mailbox.
    Notification::query()->delete();
});

/*
|--------------------------------------------------------------------------
| The dedup rule
|--------------------------------------------------------------------------
*/

it('collapses twelve events on one object into one row with a count of twelve', function () {
    foreach (range(1, 12) as $i) {
        $this->notifications->notify(
            NotificationType::TaskCommented,
            $this->task,
            [$this->tapu],
            ['title' => $this->task->title],
            $this->admin,
        );
    }

    $rows = Notification::query()->forUser($this->tapu)->get();

    expect($rows)->toHaveCount(1)
        ->and((int) $rows->first()->count)->toBe(12)
        // Grouped at dispatch: the count is IN the row, not computed by whoever reads it.
        ->and($rows->first()->summary())->toBe(sprintf('12 new comments in "%s"', $this->task->title));
})->group('phase2');

it('starts a second row once the group window has passed', function () {
    // §11's default is 2 minutes, seeded in SettingsSeeder.
    expect(SettingsSeeder::DEFAULTS[NotificationService::WINDOW_SETTING])->toBe(2);

    foreach (range(1, 12) as $i) {
        $this->notifications->notify(
            NotificationType::TaskCommented,
            $this->task,
            [$this->tapu],
            ['title' => $this->task->title],
            $this->admin,
        );
    }

    // The thirteenth, three minutes later — outside the window, so it is its own row.
    $this->travel(3)->minutes();

    $this->notifications->notify(
        NotificationType::TaskCommented,
        $this->task,
        [$this->tapu],
        ['title' => $this->task->title],
        $this->admin,
    );

    $rows = Notification::query()->forUser($this->tapu)->orderBy('id')->get();

    expect($rows)->toHaveCount(2)
        ->and((int) $rows[0]->count)->toBe(12)
        ->and((int) $rows[1]->count)->toBe(1);
})->group('phase2');

it('reads the window from settings and not from a constant', function () {
    // Widen it far past the three-minute gap that produced a second row above.
    $this->settings->set(NotificationService::WINDOW_SETTING, 60, $this->admin);

    $this->notifications->notify(NotificationType::TaskCommented, $this->task, [$this->tapu], [], $this->admin);
    $this->travel(30)->minutes();
    $this->notifications->notify(NotificationType::TaskCommented, $this->task, [$this->tapu], [], $this->admin);

    expect(Notification::query()->forUser($this->tapu)->count())->toBe(1)
        ->and((int) Notification::query()->forUser($this->tapu)->first()->count)->toBe(2);

    // And narrowing it to zero turns grouping off rather than grouping everything.
    $this->settings->set(NotificationService::WINDOW_SETTING, 0, $this->admin);

    $this->notifications->notify(NotificationType::TaskCommented, $this->task, [$this->tapu], [], $this->admin);

    expect(Notification::query()->forUser($this->tapu)->count())->toBe(2);
})->group('phase2');

it('groups per object and per type, never across either', function () {
    $other = Task::query()->forEmployee($this->tapu->employee)->notArchived()->where('id', '!=', $this->task->id)->firstOrFail();

    $this->notifications->notify(NotificationType::TaskCommented, $this->task, [$this->tapu], [], $this->admin);
    $this->notifications->notify(NotificationType::TaskCommented, $this->task, [$this->tapu], [], $this->admin);
    // Same type, different task.
    $this->notifications->notify(NotificationType::TaskCommented, $other, [$this->tapu], [], $this->admin);
    // Same task, different type.
    $this->notifications->notify(NotificationType::TaskStatusChanged, $this->task, [$this->tapu], [], $this->admin);

    $rows = Notification::query()->forUser($this->tapu)->orderBy('id')->get();

    expect($rows)->toHaveCount(3)
        ->and($rows->pluck('count')->map('intval')->all())->toBe([2, 1, 1]);
})->group('phase2');

it('groups per recipient, so two people each get their own row', function () {
    foreach (range(1, 3) as $i) {
        $this->notifications->notify(
            NotificationType::TaskCommented,
            $this->task,
            [$this->tapu, $this->admin],
            [],
            null,
        );
    }

    expect(Notification::query()->forUser($this->tapu)->count())->toBe(1)
        ->and(Notification::query()->forUser($this->admin)->count())->toBe(1)
        ->and((int) Notification::query()->forUser($this->admin)->first()->count)->toBe(3);
})->group('phase2');

it('does not let a row somebody has already read absorb the next event', function () {
    $first = $this->notifications->notify(NotificationType::TaskCommented, $this->task, [$this->tapu], [], $this->admin)->first();

    $this->notifications->markRead($this->tapu, $first);

    // Still inside the window, but the group is closed: they have seen it, so the next comment
    // has to be a notification of its own or they would never hear about it.
    $this->notifications->notify(NotificationType::TaskCommented, $this->task, [$this->tapu], [], $this->admin);

    expect(Notification::query()->forUser($this->tapu)->count())->toBe(2)
        ->and($this->notifications->unreadCount($this->tapu))->toBe(1);
})->group('phase2');

/*
|--------------------------------------------------------------------------
| Recipients
|--------------------------------------------------------------------------
*/

it('gives a task notification to nobody who lacks the permission it requires', function () {
    // The Accountant is handed to the engine exactly like everyone else. Nothing in
    // NotificationService names their role; they hold no tasks.* key, and that is the whole
    // reason they get nothing.
    expect($this->accountant->hasPermission(NotificationType::TaskCommented->requires()))->toBeFalse();

    $this->notifications->notify(
        NotificationType::TaskCommented,
        $this->task,
        [$this->accountant, $this->tapu],
        [],
        $this->admin,
    );

    expect(Notification::query()->forUser($this->accountant)->count())->toBe(0)
        ->and(Notification::query()->forUser($this->tapu)->count())->toBe(1);
})->group('phase2');

it('never notifies the person who caused the event', function () {
    $this->notifications->notify(
        NotificationType::TaskCommented,
        $this->task,
        [$this->admin, $this->tapu],
        [],
        $this->admin,
    );

    expect(Notification::query()->forUser($this->admin)->count())->toBe(0)
        ->and(Notification::query()->forUser($this->tapu)->count())->toBe(1);
})->group('phase2');

it('skips a deactivated recipient', function () {
    $this->tapu->forceFill(['status' => 'inactive'])->save();

    $this->notifications->notify(NotificationType::TaskCommented, $this->task, [$this->tapu->fresh()], [], $this->admin);

    expect(Notification::query()->count())->toBe(0);
})->group('phase2');

/*
|--------------------------------------------------------------------------
| Reading
|--------------------------------------------------------------------------
*/

it('marks one read, idempotently, and counts what is left', function () {
    $one = $this->notifications->notify(NotificationType::TaskAssigned, $this->task, [$this->tapu], [], $this->admin)->first();
    $this->travel(5)->minutes();
    $this->notifications->notify(NotificationType::TaskCommented, $this->task, [$this->tapu], [], $this->admin);

    expect($this->notifications->unreadCount($this->tapu))->toBe(2);

    $this->notifications->markRead($this->tapu, $one);
    $readAt = $one->fresh()->read_at;

    $this->travel(1)->minute();
    $this->notifications->markRead($this->tapu, $one->fresh());

    expect($this->notifications->unreadCount($this->tapu))->toBe(1)
        // Idempotent: a second call does not move the timestamp.
        ->and($one->fresh()->read_at->toIso8601String())->toBe($readAt->toIso8601String());
})->group('phase2');

it('refuses to mark somebody else\'s notification read', function () {
    $tapus = $this->notifications->notify(NotificationType::TaskAssigned, $this->task, [$this->tapu], [], $this->admin)->first();

    $this->notifications->markRead($this->admin, $tapus);

    expect($tapus->fresh()->is_read)->toBeFalse();
})->group('phase2');

it('marks all read in one pass and leaves nobody else\'s alone', function () {
    $this->notifications->notify(NotificationType::TaskAssigned, $this->task, [$this->tapu, $this->admin], [], null);
    $this->travel(5)->minutes();
    $this->notifications->notify(NotificationType::TaskCommented, $this->task, [$this->tapu, $this->admin], [], null);

    expect($this->notifications->markAllRead($this->tapu))->toBe(2)
        ->and($this->notifications->unreadCount($this->tapu))->toBe(0)
        ->and($this->notifications->unreadCount($this->admin))->toBe(2);
})->group('phase2');

/*
|--------------------------------------------------------------------------
| The catalogue
|--------------------------------------------------------------------------
*/

it('delivers on the in-app channel and on no other', function () {
    // The channels list exists so Phase 12 can switch a channel on. If this test fails, that
    // is the change happening — deliberately or by accident — and it should be noticed.
    foreach (NotificationType::cases() as $type) {
        expect($type->channels())->toBe([NotificationChannel::InApp], $type->value.' must be in-app only in the MVP');
    }
})->group('phase2');

it('puts every built type on a built tab and fakes none of the others', function () {
    // **Leave joined the built tabs in Phase 5**, which is `NotificationTab::isBuilt()` working
    // as designed rather than an exception: it is derived from the catalogue, so a tab lights
    // up by a type naming it and by nothing else. Messages, Meetings and Payroll are still
    // empty and still say "arrives in Phase N" instead of showing an empty list that looks
    // like a bug.
    expect(NotificationTab::Tasks->isBuilt())->toBeTrue()
        ->and(NotificationTab::System->isBuilt())->toBeTrue()
        ->and(NotificationTab::Leave->isBuilt())->toBeTrue()
        ->and(NotificationTab::Messages->isBuilt())->toBeFalse()
        ->and(NotificationTab::Meetings->isBuilt())->toBeFalse()
        ->and(NotificationTab::Payroll->isBuilt())->toBeFalse();

    foreach (NotificationType::cases() as $type) {
        expect($type->tab())->toBeIn([NotificationTab::Tasks, NotificationTab::System, NotificationTab::Leave]);
    }
})->group('phase2');

it('counts unread per tab without a query per tab', function () {
    $project = Project::where('name', 'Buffalo Modular — SEO')->firstOrFail();

    $this->notifications->notify(NotificationType::TaskCommented, $this->task, [$this->admin], [], null);
    $this->notifications->notify(NotificationType::ProjectCancelled, $project, [$this->admin], [], null);

    $counts = $this->notifications->unreadByTab($this->admin);

    expect($counts[NotificationTab::All->value])->toBe(2)
        ->and($counts[NotificationTab::Tasks->value])->toBe(1)
        ->and($counts[NotificationTab::System->value])->toBe(1)
        ->and($counts[NotificationTab::Payroll->value])->toBe(0);
})->group('phase2');

it('remembers which objects it has already sent a type about', function () {
    $other = Task::query()->forEmployee($this->tapu->employee)->notArchived()->where('id', '!=', $this->task->id)->firstOrFail();

    $this->notifications->notify(NotificationType::TaskOverdue, $this->task, [$this->tapu], []);

    expect($this->notifications->alreadySentFor(NotificationType::TaskOverdue, [$this->task, $other]))
        ->toBe([(int) $this->task->id])
        // A different type about the same task is a different question.
        ->and($this->notifications->alreadySentFor(NotificationType::TaskCommented, [$this->task]))
        ->toBe([]);
})->group('phase2');

/*
|--------------------------------------------------------------------------
| Resolving — what CLOSES a row, as opposed to what opens one (decision 2-48)
|--------------------------------------------------------------------------
|
| A notification is a request for attention, and attention has been paid when
| the person acts on the thing. The engine's half of that rule is here; the
| walk through a real review — reviewer rules, assignee resubmits — is in
| NotificationDispatcherTest.
|
| Every assertion below keeps the two states apart on purpose. `is_read` is
| "you looked", and decision 2-33 hangs off it. `resolved_at` is "you dealt
| with it". Collapsing them would have been one column cheaper and would have
| closed the group as well as quietened it.
|
*/

it('closes the actor\'s own row when they do the thing it asked for', function () {
    // Tapu is asked to look at the task…
    $asked = $this->notifications
        ->notify(NotificationType::TaskSubmittedForReview, $this->task, [$this->tapu], [], $this->admin)
        ->first();

    expect($this->notifications->unreadCount($this->tapu))->toBe(1);

    // …and then rules on it. The verdict's own notification names Tapu as the actor, which is
    // the whole input the rule needs: nothing here mentions tasks, statuses or reviews.
    $this->notifications->notify(NotificationType::TaskCompleted, $this->task, [$this->admin], [], $this->tapu);

    $asked = $asked->fresh();

    expect($asked->resolved_at)->not->toBeNull()
        // Not read. They never opened it — they answered it.
        ->and($asked->is_read)->toBeFalse()
        ->and($asked->read_at)->toBeNull()
        // The count is the server's number and it follows.
        ->and($this->notifications->unreadCount($this->tapu))->toBe(0)
        // And it is out of both lists the screens read.
        ->and($this->notifications->recent($this->tapu)->pluck('id')->all())->not->toContain($asked->id)
        ->and($this->notifications->page($this->tapu, NotificationTab::All)->pluck('id')->all())
        ->not->toContain($asked->id);
})->group('phase2');

it('closes only the actor\'s row, never a second reviewer\'s', function () {
    // Two people are asked the same question. One of them answers it.
    $this->notifications->notify(
        NotificationType::TaskSubmittedForReview,
        $this->task,
        [$this->tapu, $this->yaseen],
        [],
        $this->admin,
    );

    $this->notifications->notify(NotificationType::TaskCompleted, $this->task, [$this->admin], [], $this->tapu);

    // Yaseen has not looked at his and has not acted on it, so nothing about it has changed.
    // Silently emptying his bell would lose the only sign he had that he was asked.
    expect($this->notifications->unreadCount($this->tapu))->toBe(0)
        ->and($this->notifications->unreadCount($this->yaseen))->toBe(1)
        ->and(Notification::query()->forUser($this->yaseen)->first()->resolved_at)->toBeNull();
})->group('phase2');

it('closes nothing for a type that names no resolving act', function () {
    // Nothing answers a comment. Reading it is not answering it, and replying to it is not
    // either — a thread that cleared itself when you spoke in it would hide the reply.
    $comment = $this->notifications
        ->notify(NotificationType::TaskCommented, $this->task, [$this->tapu], [], $this->admin)
        ->first();

    $this->notifications->notify(NotificationType::TaskCompleted, $this->task, [$this->admin], [], $this->tapu);

    expect($comment->fresh()->resolved_at)->toBeNull()
        ->and($this->notifications->unreadCount($this->tapu))->toBe(1);
})->group('phase2');

it('closes nothing when a date drove the event and nobody acted', function () {
    $asked = $this->notifications
        ->notify(NotificationType::TaskSubmittedForReview, $this->task, [$this->tapu], [], $this->admin)
        ->first();

    // hq:flag-overdue passes no actor. Nobody did anything, so nothing is answered.
    $this->notifications->notify(NotificationType::TaskOverdue, $this->task, [$this->tapu], []);

    expect($asked->fresh()->resolved_at)->toBeNull();
})->group('phase2');

it('brings a resolved row back when the same subject asks again, rather than starting a new one', function () {
    // This is the half that would have broken if resolving had been spelled `is_read`: the
    // group would have been closed as well as quietened, the resubmission would have written a
    // fresh row of one, and the reviewer would have been told for the second time exactly what
    // they were told the first (decision 2-46).
    $asked = $this->notifications
        ->notify(NotificationType::TaskSubmittedForReview, $this->task, [$this->tapu], [], $this->admin)
        ->first();

    $this->notifications->notify(NotificationType::TaskCompleted, $this->task, [$this->admin], [], $this->tapu);

    expect($asked->fresh()->resolved_at)->not->toBeNull();

    $again = $this->notifications
        ->notify(NotificationType::TaskSubmittedForReview, $this->task, [$this->tapu], ['title' => 'A task'], $this->admin)
        ->first();

    expect($again->id)->toBe($asked->id)
        ->and((int) $again->count)->toBe(2)
        ->and($again->resolved_at)->toBeNull()
        ->and($this->notifications->unreadCount($this->tapu))->toBe(1)
        ->and($again->summary())->toBe('"A task" is waiting for your review again')
        ->and(Notification::query()->forUser($this->tapu)->count())->toBe(1);
})->group('phase2');

it('leaves a row the actor had already READ closed, resolved or not', function () {
    // 2-33, asserted from the other side: reading still closes a group even when the reader is
    // the person who went on to act. The read row does not absorb, so a new one is written.
    $asked = $this->notifications
        ->notify(NotificationType::TaskSubmittedForReview, $this->task, [$this->tapu], [], $this->admin)
        ->first();

    $this->notifications->markRead($this->tapu, $asked);
    $this->notifications->notify(NotificationType::TaskCompleted, $this->task, [$this->admin], [], $this->tapu);

    // Already read means already out of the count, so there was nothing left to resolve.
    expect($asked->fresh()->resolved_at)->toBeNull();

    $this->notifications->notify(NotificationType::TaskSubmittedForReview, $this->task, [$this->tapu], [], $this->admin);

    expect(Notification::query()->forUser($this->tapu)->count())->toBe(2)
        ->and($this->notifications->unreadCount($this->tapu))->toBe(1);
})->group('phase2');

it('leaves a resolved row out of "mark all read", so it can still come back', function () {
    $asked = $this->notifications
        ->notify(NotificationType::TaskSubmittedForReview, $this->task, [$this->tapu], [], $this->admin)
        ->first();

    $this->notifications->notify(NotificationType::TaskCompleted, $this->task, [$this->admin], [], $this->tapu);

    expect($this->notifications->markAllRead($this->tapu))->toBe(0)
        ->and($asked->fresh()->is_read)->toBeFalse();
})->group('phase2');

it('names a resolving act on the type and nowhere else', function () {
    // The fact is one line in the enum. Everything else in the engine reads it, which is what
    // makes adding a resolving act in Phase 5 or 9 a case here and nothing else.
    expect(NotificationType::TaskSubmittedForReview->resolvedBy())
        ->toBe([NotificationType::TaskStatusChanged, NotificationType::TaskCompleted])
        ->and(NotificationType::TaskCommented->resolvedBy())->toBe([])
        // resolves() is the same fact read backwards, derived rather than written twice.
        ->and(NotificationType::TaskCompleted->resolves())->toBe([NotificationType::TaskSubmittedForReview])
        ->and(NotificationType::TaskAssigned->resolves())->toBe([]);

    foreach (NotificationType::cases() as $type) {
        foreach ($type->resolvedBy() as $act) {
            expect($act->resolves())->toContain($type);
        }
    }
})->group('phase2');
