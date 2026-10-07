<?php

use App\Exceptions\MeetingStateException;
use App\Models\ActivityLog;
use App\Models\Conversation;
use App\Models\Meeting;
use App\Models\Notification;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\MeetingService;
use App\Support\MeetingStatus;
use App\Support\NotificationType;
use App\Support\RsvpStatus;
use App\Support\TaskStatus;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| MeetingService — master prompt Part D §12
|--------------------------------------------------------------------------
|
| Every rule Part D's meeting paragraph states, and the two it implies:
|
|   - the organiser is always in the room, and is never notified about
|     their own meeting;
|   - the Accountant cannot be put in one, and nobody had to name them;
|   - `end_at > start_at` is refused with a sentence and refused again by
|     the database;
|   - a meeting may link a project, a task, both or neither;
|   - cancelling sets the status, notifies the room and KEEPS the linked
|     tasks — and deleting the meeting outright keeps them too, which is
|     the stronger rule nobody writes down until it goes wrong.
|
| Every constant and helper in this file is prefixed MEETING_: a Pest
| file's globals are the whole suite's (AGENTS.md).
|
*/

beforeEach(function () {
    $this->seed();

    $this->meetings = app(MeetingService::class);

    $this->shahadat = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->faruk = User::where('email', 'faruk@goodtechies.test')->firstOrFail();
    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();
    $this->yaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();
    $this->accountant = User::where('email', 'accountant@goodtechies.test')->firstOrFail();

    // Tapu is a member of this project; Yaseen is not. That asymmetry is the privacy case,
    // and MeetingPolicyTest is where it is proved.
    $this->project = Project::where('name', 'Buffalo Modular — SEO')->firstOrFail();

    // The seeder's four demo meetings would make every count in this file an arithmetic
    // puzzle. They are proved separately by the seeder running at all.
    Meeting::query()->delete();
    Notification::query()->delete();
});

/** A start time far enough out that no reminder sweep in this file could reach it. */
function MEETING_startAt(int $daysFromNow = 3): Carbon
{
    return Carbon::today()->addDays($daysFromNow)->setTime(14, 0);
}

/**
 * The notification rows of one type about one meeting, whoever they went to.
 *
 * @return Collection<int, Notification>
 */
function MEETING_rowsFor(Meeting $meeting, NotificationType $type)
{
    return Notification::query()
        ->where('group_key', sprintf('%s:%s:%d', $type->value, $meeting->getMorphClass(), $meeting->getKey()))
        ->get();
}

/**
 * @return array<string, mixed>
 */
function MEETING_attributes(array $overrides = []): array
{
    $start = MEETING_startAt();

    return [
        'title' => 'Quarterly review',
        'start_at' => $start,
        'end_at' => $start->copy()->addHour(),
    ] + $overrides;
}

/*
|--------------------------------------------------------------------------
| Creating
|--------------------------------------------------------------------------
*/

it('creates a meeting with its participants', function () {
    $meeting = $this->meetings->schedule(
        $this->shahadat,
        MEETING_attributes(['agenda' => 'Rankings, content plan, budget.']),
        [$this->tapu->id, $this->yaseen->id],
    );

    expect($meeting->status)->toBe(MeetingStatus::Scheduled)
        ->and($meeting->organizer_id)->toBe($this->shahadat->id)
        ->and($meeting->agenda)->toBe('Rankings, content plan, budget.')
        ->and($meeting->participants()->pluck('users.id')->sort()->values()->all())
        ->toBe(collect([$this->shahadat->id, $this->tapu->id, $this->yaseen->id])->sort()->values()->all());
});

it('always seats the organizer, even when the form did not name them', function () {
    $meeting = $this->meetings->schedule($this->shahadat, MEETING_attributes(), [$this->tapu->id]);

    expect($meeting->hasParticipant($this->shahadat))->toBeTrue()
        // Calling a meeting is already saying you will be at it.
        ->and($meeting->rsvpOf($this->shahadat))->toBe(RsvpStatus::Accepted)
        ->and($meeting->rsvpOf($this->tapu))->toBe(RsvpStatus::Pending);
});

it('refuses to seat somebody who holds no meetings.use, without naming their role', function () {
    $meeting = $this->meetings->schedule(
        $this->shahadat,
        MEETING_attributes(),
        [$this->tapu->id, $this->accountant->id],
    );

    expect($meeting->hasParticipant($this->accountant))->toBeFalse()
        ->and($meeting->hasParticipant($this->tapu))->toBeTrue();
});

it('refuses to let somebody who holds no meetings.use schedule one at all', function () {
    $this->meetings->schedule($this->accountant, MEETING_attributes(), [$this->tapu->id]);
})->throws(AuthorizationException::class);

it('notifies the participants and not the organizer', function () {
    $meeting = $this->meetings->schedule(
        $this->shahadat,
        MEETING_attributes(),
        [$this->tapu->id, $this->yaseen->id],
    );

    $rows = MEETING_rowsFor($meeting, NotificationType::MeetingScheduled);

    expect($rows->pluck('user_id')->sort()->values()->all())
        ->toBe(collect([$this->tapu->id, $this->yaseen->id])->sort()->values()->all());
});

it('lets a meeting link a project, a task, both or neither', function () {
    $task = Task::query()->where('project_id', $this->project->id)->firstOrFail();

    $neither = $this->meetings->schedule($this->shahadat, MEETING_attributes(['title' => 'Neither']));
    $projectOnly = $this->meetings->schedule($this->shahadat, MEETING_attributes([
        'title' => 'Project only', 'project_id' => $this->project->id,
    ]));
    $taskOnly = $this->meetings->schedule($this->shahadat, MEETING_attributes([
        'title' => 'Task only', 'task_id' => $task->id,
    ]));
    $both = $this->meetings->schedule($this->shahadat, MEETING_attributes([
        'title' => 'Both', 'project_id' => $this->project->id, 'task_id' => $task->id,
    ]));

    expect([$neither->project_id, $neither->task_id])->toBe([null, null])
        ->and([$projectOnly->project_id, $projectOnly->task_id])->toBe([$this->project->id, null])
        ->and([$taskOnly->project_id, $taskOnly->task_id])->toBe([null, $task->id])
        ->and([$both->project_id, $both->task_id])->toBe([$this->project->id, $task->id]);
});

/*
|--------------------------------------------------------------------------
| end_at > start_at — a sentence here, a CHECK in the database
|--------------------------------------------------------------------------
*/

it('refuses a meeting that ends before it starts', function () {
    $start = MEETING_startAt();

    $this->meetings->schedule($this->shahadat, [
        'title' => 'Backwards',
        'start_at' => $start,
        'end_at' => $start->copy()->subHour(),
    ]);
})->throws(MeetingStateException::class);

it('refuses a meeting of zero length', function () {
    $start = MEETING_startAt();

    $this->meetings->schedule($this->shahadat, [
        'title' => 'Instantaneous',
        'start_at' => $start,
        'end_at' => $start->copy(),
    ]);
})->throws(MeetingStateException::class);

it('refuses a backwards range in the database as well as in the service', function () {
    $start = MEETING_startAt();

    // Straight past the service, the way a seeder, an import or a bulk update would reach the
    // table. The promise is the CHECK constraint, not the `if`.
    Meeting::query()->create([
        'title' => 'Smuggled in',
        'start_at' => $start,
        'end_at' => $start->copy()->subMinute(),
        'organizer_id' => $this->shahadat->id,
    ]);
})->throws(QueryException::class);

/*
|--------------------------------------------------------------------------
| Editing
|--------------------------------------------------------------------------
*/

it('updates a meeting and reports whether the time moved', function () {
    $meeting = $this->meetings->schedule($this->shahadat, MEETING_attributes(), [$this->tapu->id]);
    Notification::query()->delete();

    $this->meetings->update($this->shahadat, $meeting, ['agenda' => 'A typo, fixed.']);

    expect($meeting->fresh()->agenda)->toBe('A typo, fixed.')
        // An agenda edit is not news. Part D §11's bell has to stay worth looking at.
        ->and(MEETING_rowsFor($meeting, NotificationType::MeetingUpdated))->toHaveCount(0);

    $moved = MEETING_startAt(5);
    $this->meetings->update($this->shahadat, $meeting, [
        'start_at' => $moved,
        'end_at' => $moved->copy()->addHour(),
    ]);

    expect($meeting->fresh()->start_at->equalTo($moved))->toBeTrue()
        ->and(MEETING_rowsFor($meeting, NotificationType::MeetingUpdated)->pluck('user_id')->all())
        ->toBe([$this->tapu->id]);
});

it('leaves the room alone when an edit says nothing about participants', function () {
    $meeting = $this->meetings->schedule($this->shahadat, MEETING_attributes(), [$this->tapu->id]);

    $this->meetings->update($this->shahadat, $meeting, ['title' => 'Renamed']);

    expect($meeting->fresh()->participants()->count())->toBe(2);
});

it('keeps an existing RSVP when the participant list is re-synced', function () {
    $meeting = $this->meetings->schedule($this->shahadat, MEETING_attributes(), [$this->tapu->id]);
    $this->meetings->rsvp($this->tapu, $meeting, RsvpStatus::Accepted);

    $this->meetings->update($this->shahadat, $meeting->fresh(), [], [$this->tapu->id, $this->yaseen->id]);

    $meeting = $meeting->fresh();

    expect($meeting->rsvpOf($this->tapu))->toBe(RsvpStatus::Accepted)
        ->and($meeting->rsvpOf($this->yaseen))->toBe(RsvpStatus::Pending);
});

it('refuses an edit that would leave the meeting ending before it starts', function () {
    $meeting = $this->meetings->schedule($this->shahadat, MEETING_attributes(), [$this->tapu->id]);

    $this->meetings->update($this->shahadat, $meeting, [
        'end_at' => $meeting->start_at->copy()->subMinute(),
    ]);
})->throws(MeetingStateException::class);

/*
|--------------------------------------------------------------------------
| Cancelling
|--------------------------------------------------------------------------
*/

it('cancels a meeting, notifies the room and keeps the linked tasks', function () {
    $task = Task::query()->where('project_id', $this->project->id)->firstOrFail();

    $meeting = $this->meetings->schedule(
        $this->shahadat,
        MEETING_attributes(['project_id' => $this->project->id, 'task_id' => $task->id]),
        [$this->tapu->id],
    );

    // An action item that came out of it, so "linked tasks not deleted" has two kinds of link
    // to be true of.
    $actionItem = $this->meetings->convertActionItem($this->shahadat, $meeting, [
        'title' => 'Draft the Q4 content plan',
        'status' => TaskStatus::Todo->value,
    ], [$this->tapu->employee->id]);

    Notification::query()->delete();

    $this->meetings->cancel($this->shahadat, $meeting);

    expect($meeting->fresh()->status)->toBe(MeetingStatus::Cancelled)
        ->and(MEETING_rowsFor($meeting, NotificationType::MeetingCancelled)->pluck('user_id')->all())
        ->toBe([$this->tapu->id])
        // Part D §12: "linked tasks not deleted".
        ->and(Task::query()->whereKey($task->id)->exists())->toBeTrue()
        ->and(Task::query()->whereKey($actionItem->id)->exists())->toBeTrue()
        ->and($actionItem->fresh()->source_meeting_id)->toBe($meeting->id);
});

it('refuses to cancel a meeting twice', function () {
    $meeting = $this->meetings->schedule($this->shahadat, MEETING_attributes(), [$this->tapu->id]);
    $this->meetings->cancel($this->shahadat, $meeting);

    $this->meetings->cancel($this->shahadat, $meeting->fresh());
})->throws(MeetingStateException::class);

it('refuses to edit a meeting that has been cancelled', function () {
    $meeting = $this->meetings->schedule($this->shahadat, MEETING_attributes(), [$this->tapu->id]);
    $this->meetings->cancel($this->shahadat, $meeting);

    $this->meetings->update($this->shahadat, $meeting->fresh(), ['title' => 'Back on, apparently']);
})->throws(MeetingStateException::class);

it('lets an admin who is not the organizer cancel somebody else\'s meeting', function () {
    $meeting = $this->meetings->schedule($this->shahadat, MEETING_attributes(), [$this->tapu->id]);

    $this->meetings->cancel($this->faruk, $meeting);

    expect($meeting->fresh()->status)->toBe(MeetingStatus::Cancelled);
});

it('refuses to let a participant who is not the organizer cancel it', function () {
    $meeting = $this->meetings->schedule($this->shahadat, MEETING_attributes(), [$this->tapu->id]);

    $this->meetings->cancel($this->tapu, $meeting);
})->throws(AuthorizationException::class);

/*
|--------------------------------------------------------------------------
| RSVP, notes, and the convert-to-task seam
|--------------------------------------------------------------------------
*/

it('lets a participant answer for themselves and nobody else', function () {
    $meeting = $this->meetings->schedule($this->shahadat, MEETING_attributes(), [$this->tapu->id]);

    $this->meetings->rsvp($this->tapu, $meeting, RsvpStatus::Declined);

    expect($meeting->fresh()->rsvpOf($this->tapu))->toBe(RsvpStatus::Declined);

    // Yaseen is not in the room at all.
    expect(fn () => $this->meetings->rsvp($this->yaseen, $meeting->fresh(), RsvpStatus::Accepted))
        ->toThrow(AuthorizationException::class);
});

it('keeps one pad of notes per meeting however many times it is written', function () {
    $meeting = $this->meetings->schedule($this->shahadat, MEETING_attributes(), [$this->tapu->id]);

    $this->meetings->recordNotes($this->shahadat, $meeting, 'Talked about rankings.', 'Approved the budget.');
    $this->meetings->recordNotes($this->shahadat, $meeting, 'Talked about rankings and content.', 'Approved the budget.');

    expect($meeting->fresh()->note()->count())->toBe(1)
        ->and($meeting->fresh()->note->notes)->toBe('Talked about rankings and content.');
});

it('stamps a converted action item with the meeting it came from and the meeting\'s project', function () {
    $meeting = $this->meetings->schedule(
        $this->shahadat,
        MEETING_attributes(['project_id' => $this->project->id]),
        [$this->tapu->id],
    );

    $task = $this->meetings->convertActionItem($this->shahadat, $meeting, [
        'title' => 'Rewrite the home model pages',
        'status' => TaskStatus::Todo->value,
    ], [$this->tapu->employee->id]);

    expect($task->source_meeting_id)->toBe($meeting->id)
        ->and($task->project_id)->toBe($this->project->id)
        ->and($task->isActionItem())->toBeTrue()
        // It went through TaskService, so it got everything a task gets — including its own
        // discussion conversation (decision 2-9's third application). A bespoke INSERT here
        // would have produced a task nobody could comment on.
        ->and(Conversation::query()->where('linked_task_id', $task->id)->exists())->toBeTrue();
});

it('deletes the meeting without deleting the task it produced', function () {
    $meeting = $this->meetings->schedule(
        $this->shahadat,
        MEETING_attributes(['project_id' => $this->project->id]),
        [$this->tapu->id],
    );

    $task = $this->meetings->convertActionItem($this->shahadat, $meeting, [
        'title' => 'Fix the redirect map',
        'status' => TaskStatus::Todo->value,
    ], [$this->tapu->employee->id]);

    $meeting->delete();

    $task = Task::query()->find($task->id);

    // The work is still owed; what it lost is only its memory of where it came from.
    expect($task)->not->toBeNull()
        ->and($task->source_meeting_id)->toBeNull()
        ->and($task->title)->toBe('Fix the redirect map');
});

it('refuses to convert an action item onto a project the meeting is not about', function () {
    $other = Project::where('name', 'Heat Gap — SEO Retainer')->firstOrFail();

    $meeting = $this->meetings->schedule(
        $this->shahadat,
        MEETING_attributes(['project_id' => $this->project->id]),
        [$this->tapu->id],
    );

    $this->meetings->convertActionItem($this->shahadat, $meeting, [
        'title' => 'Wrong project',
        'status' => TaskStatus::Todo->value,
        'project_id' => $other->id,
    ]);
})->throws(MeetingStateException::class);

/*
|--------------------------------------------------------------------------
| The activity trail
|--------------------------------------------------------------------------
*/

it('writes the meeting\'s history to the activity trail rather than to columns on the row', function () {
    $meeting = $this->meetings->schedule($this->shahadat, MEETING_attributes(), [$this->tapu->id]);
    $moved = MEETING_startAt(6);
    $this->meetings->update($this->shahadat, $meeting, ['start_at' => $moved, 'end_at' => $moved->copy()->addHour()]);
    $this->meetings->cancel($this->shahadat, $meeting->fresh());

    $trail = ActivityLog::query()
        ->where('object_type', $meeting->getMorphClass())
        ->where('object_id', $meeting->id)
        ->orderBy('id')
        ->pluck('description')
        ->all();

    expect($trail)->toContain('Meeting scheduled')
        ->and($trail)->toContain('Meeting rescheduled')
        ->and($trail)->toContain('Meeting cancelled');

    // And none of it is stored as state on the meeting: there is no completed status, no
    // cancelled_at and no reminder flag to drift.
    expect(Schema::hasColumn('meetings', 'completed_at'))->toBeFalse()
        ->and(Schema::hasColumn('meetings', 'reminder_sent_at'))->toBeFalse();
});

it('reads whether a meeting has happened off the clock and never off the status', function () {
    $past = Meeting::factory()
        ->organisedBy($this->shahadat)
        ->at(Carbon::now()->subHours(3), Carbon::now()->subHours(2))
        ->create();

    expect($past->hasHappened())->toBeTrue()
        // Still `scheduled`: the status records whether it was called off, not where the clock
        // is. See MeetingStatus.
        ->and($past->status)->toBe(MeetingStatus::Scheduled)
        ->and($past->tone())->toBe('done')
        ->and($past->stateLabel())->toBe('Completed');
});
