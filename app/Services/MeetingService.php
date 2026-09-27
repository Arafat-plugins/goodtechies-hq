<?php

namespace App\Services;

use App\Events\MeetingCancelled;
use App\Events\MeetingScheduled;
use App\Events\MeetingUpdated;
use App\Exceptions\MeetingStateException;
use App\Models\Meeting;
use App\Models\MeetingNote;
use App\Models\MeetingParticipant;
use App\Models\Task;
use App\Models\User;
use App\Services\Calendar\CalendarLink;
use App\Services\Calendar\CalendarOutcome;
use App\Services\Calendar\MeetLink;
use App\Support\MeetingStatus;
use App\Support\Permission;
use App\Support\RsvpStatus;
use App\Support\UserStatus;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Everything that happens to a meeting (master prompt Part D §12).
 *
 * One door in, like every other service here: the controller slice, the seeder, the reminder
 * command and any later import all come through these methods, so the participant sync, the
 * calendar driver, the activity trail and the events cannot be half-applied by a second path.
 *
 * ## What is in a transaction, and why the calendar driver is inside it
 *
 * Each write method is one `DB::transaction`, and the `CalendarLink` call is **inside** it. A
 * driver that throws therefore leaves no meeting behind, rather than a meeting nobody has a
 * link for. Under `ManualLink` this costs nothing — there is no network call — and under the
 * API driver it is the difference between a failed `events.insert` being a clean error and
 * being a row that looks scheduled with no Google event.
 *
 * The one thing a driver is never allowed to do is write: it returns a `CalendarOutcome` and
 * this class applies it. See `CalendarLink`.
 *
 * ## The privacy method is here rather than in a Resource, on purpose
 *
 * `linkedContextFor()` answers *"what may this viewer know about this meeting's linked project
 * and task"*, and it lives in the service because the controller slice is not the only caller
 * it will ever have — a report, a digest, a search result and an export all need the same
 * answer, and each of them writing their own would be four chances to get it wrong. Part C's
 * rule is that a field the requester may not see is **absent**, and this method is where that
 * absence is produced.
 */
class MeetingService
{
    /**
     * The attributes a create or an edit may set.
     *
     * `status` is not here: the one transition a meeting has is `cancel()`, which is its own
     * method with its own ability and its own notification. `organizer_id` is not here: it is
     * set in the INSERT and is not transferable — a fillable organiser would be a way to hand a
     * meeting to somebody else and lose the right to fix it. `google_event_id` is not here: it
     * belongs to the calendar driver.
     *
     * @var list<string>
     */
    private const FIELDS = [
        'title',
        'start_at',
        'end_at',
        'project_id',
        'task_id',
        'agenda',
        'meet_link',
    ];

    public function __construct(
        private readonly CalendarLink $calendar,
        private readonly ActivityLogger $activity,
        private readonly TaskService $tasks,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Writing
    |--------------------------------------------------------------------------
    */

    /**
     * Call a meeting.
     *
     * The organiser is **always** a participant, and always `accepted`: calling a meeting is
     * already saying you will be at it, and having them in the same table as everybody else is
     * what lets one query answer "who is in this room" without a union. They are still dropped
     * from the notification, because `NotificationService::eligible()` drops the actor from
     * every type — so Part D's *"participants notified"* comes out meaning "everybody but the
     * person who just did it" with nothing here saying so.
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<int>  $participantUserIds  the organiser is added whether or not they are in it
     *
     * @throws AuthorizationException
     * @throws MeetingStateException
     */
    public function schedule(User $organizer, array $attributes, array $participantUserIds = []): Meeting
    {
        if (! Gate::forUser($organizer)->allows('create', Meeting::class)) {
            throw new AuthorizationException('You are not allowed to schedule meetings.');
        }

        $fields = $this->cleanFields($attributes);
        $this->guardTimes($fields['start_at'] ?? null, $fields['end_at'] ?? null);

        return DB::transaction(function () use ($organizer, $fields, $participantUserIds): Meeting {
            $meeting = new Meeting;
            $meeting->fill($fields);
            $meeting->forceFill([
                'organizer_id' => $organizer->getKey(),
                'status' => MeetingStatus::Scheduled,
            ])->save();

            // Inside the transaction, before anything is announced: a driver that throws takes
            // the meeting with it rather than leaving a linkless row behind.
            $this->applyOutcome($meeting, $this->calendar->schedule($meeting));

            $this->syncParticipants($meeting, $participantUserIds, $organizer);

            $this->activity->record($meeting, 'Meeting scheduled', $organizer);

            $meeting->refresh()->load('participantSeats');

            event(new MeetingScheduled($meeting, $organizer));

            return $meeting;
        });
    }

    /**
     * Edit a meeting.
     *
     * Two things are computed here rather than in the listener, because this is the only place
     * that holds the row before and after:
     *
     *   - **whether the time moved**, which is the entire question `MeetingUpdated` exists to
     *     answer. An agenda typo must not ring eleven bells; see that event's docblock.
     *   - **whether to call the calendar driver at all.** `reschedule()` is an HTTP request
     *     under the API driver, and an edit that did not move the meeting has nothing to tell
     *     Google.
     *
     * `$participantUserIds` is nullable and null means *leave the room alone*, which is not the
     * same as `[]` — an empty array is "nobody but the organiser", and a form that simply does
     * not manage participants must not be able to empty the room by omission.
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<int>|null  $participantUserIds  null leaves the participant list untouched
     *
     * @throws AuthorizationException
     * @throws MeetingStateException
     */
    public function update(User $actor, Meeting $meeting, array $attributes, ?array $participantUserIds = null): Meeting
    {
        if ($meeting->isCancelled()) {
            throw MeetingStateException::alreadyCancelled();
        }

        if (! Gate::forUser($actor)->allows('update', $meeting)) {
            throw new AuthorizationException('You are not allowed to edit this meeting.');
        }

        $fields = $this->cleanFields($attributes);

        $previousStart = $meeting->start_at?->copy();
        $previousEnd = $meeting->end_at?->copy();

        $this->guardTimes(
            $fields['start_at'] ?? $previousStart,
            $fields['end_at'] ?? $previousEnd,
        );

        return DB::transaction(function () use (
            $actor, $meeting, $fields, $participantUserIds, $previousStart, $previousEnd
        ): Meeting {
            $meeting->fill($fields)->save();

            $timeChanged = ! $this->sameInstant($previousStart, $meeting->start_at)
                || ! $this->sameInstant($previousEnd, $meeting->end_at);

            if ($timeChanged) {
                $this->applyOutcome($meeting, $this->calendar->reschedule($meeting));
            }

            if ($participantUserIds !== null) {
                $this->syncParticipants($meeting, $participantUserIds, $meeting->organizer ?? $actor);
            }

            $this->activity->record(
                $meeting,
                $timeChanged ? 'Meeting rescheduled' : 'Meeting edited',
                $actor,
            );

            $meeting->refresh()->load('participantSeats');

            event(new MeetingUpdated($meeting, $actor, $timeChanged, $previousStart, $previousEnd));

            return $meeting;
        });
    }

    /**
     * Call a meeting off (Part D §12: *"status Cancelled, participants notified, linked tasks
     * not deleted"*).
     *
     * **Nothing in this method touches a task**, and that is a stronger guarantee than looking
     * at the linked tasks and deciding to leave them alone: there is no code here for a later
     * edit to turn into a cascade. The action items that came out of this meeting keep their
     * `source_meeting_id`, keep their assignees and keep their place on the board.
     *
     * The calendar driver's outcome is recorded on the **activity trail**, not on a column. Under
     * `ManualLink` that outcome is Part D's *"the record notes 'cancel the Meet manually'"* —
     * an event in the meeting's history, attributed and timestamped, rather than a state the
     * meeting is in. Under the API driver it will be the opposite sentence, saying the Google
     * event was deleted. Either way it is one line on one trail and no participant is bothered
     * with it: it is the organiser's job, not the room's.
     *
     * @throws AuthorizationException
     * @throws MeetingStateException
     */
    public function cancel(User $actor, Meeting $meeting): Meeting
    {
        if ($meeting->isCancelled()) {
            throw MeetingStateException::alreadyCancelled();
        }

        if (! Gate::forUser($actor)->allows('cancel', $meeting)) {
            throw new AuthorizationException('You are not allowed to cancel this meeting.');
        }

        return DB::transaction(function () use ($actor, $meeting): Meeting {
            $outcome = $this->calendar->cancel($meeting);

            $meeting->forceFill(['status' => MeetingStatus::Cancelled])->save();

            $this->activity->record($meeting, 'Meeting cancelled', $actor);

            if ($outcome->manualAction !== null) {
                $this->activity->record($meeting, $outcome->manualAction, $actor);
            }

            if ($outcome->remoteEventRemoved) {
                $this->activity->record($meeting, 'Google Calendar event deleted', $actor);
            }

            $meeting->refresh()->load('participantSeats');

            event(new MeetingCancelled($meeting, $actor));

            return $meeting;
        });
    }

    /**
     * Answer an invitation.
     *
     * Only ever about yourself — `MeetingPolicy::rsvp()` says so and this asks it with the
     * subject explicit, so that a future endpoint accepting a `user_id` cannot become a way to
     * answer for somebody else. An Admin may edit and cancel anybody's meeting and may not
     * RSVP to it on their behalf; see the policy.
     *
     * @throws AuthorizationException
     */
    public function rsvp(User $user, Meeting $meeting, RsvpStatus $status): MeetingParticipant
    {
        if (! Gate::forUser($user)->allows('rsvp', [$meeting, $user])) {
            throw new AuthorizationException('You are not allowed to answer for this meeting.');
        }

        $seat = MeetingParticipant::query()
            ->where('meeting_id', $meeting->getKey())
            ->where('user_id', $user->getKey())
            ->firstOrFail();

        if ($seat->rsvp_status === $status) {
            return $seat;
        }

        $seat->forceFill(['rsvp_status' => $status])->save();

        $this->activity->record($meeting, sprintf('RSVP: %s', $status->label()), $user);

        return $seat;
    }

    /**
     * Write the meeting's notes and decisions (Part D §12: *"notes + decisions recorded"*).
     *
     * `updateOrCreate` through the unique index on `meeting_id`, so two people closing the same
     * meeting at the same moment end with one pad and the later text rather than two pads and a
     * coin toss.
     *
     * A pad with nothing on either side is not created — and an existing one emptied of both is
     * deleted, so that "has this meeting been minuted?" stays a question the presence of a row
     * answers.
     *
     * Notes are an edit, so it takes the `update` ability: the organiser or an Admin. A
     * participant who took better notes hands them over; that is a workflow question the client
     * has not been asked, and inventing an answer here would be building ahead of Part D.
     *
     * @throws AuthorizationException
     */
    public function recordNotes(User $actor, Meeting $meeting, ?string $notes, ?string $decisions): ?MeetingNote
    {
        if (! Gate::forUser($actor)->allows('update', $meeting)) {
            throw new AuthorizationException('You are not allowed to write this meeting up.');
        }

        $notes = $this->blankToNull($notes);
        $decisions = $this->blankToNull($decisions);

        if ($notes === null && $decisions === null) {
            MeetingNote::query()->where('meeting_id', $meeting->getKey())->delete();

            return null;
        }

        $note = MeetingNote::query()->updateOrCreate(
            ['meeting_id' => $meeting->getKey()],
            ['notes' => $notes, 'decisions' => $decisions],
        );

        $this->activity->record($meeting, 'Meeting notes recorded', $actor);

        return $note;
    }

    /**
     * Turn an action item into a task (Part D §12: *"action items → **Convert to Task** (tasks
     * get `source_meeting_id`)"*, *"pre-linked project"*).
     *
     * ## It goes through `TaskService::create()` and not round it
     *
     * The repo has refused a second task-creation path twice (decisions 2-9 and 2-36) and this
     * is the third time it would have been convenient: a task born here needs an extra column
     * set, and `TaskService` already owns the machinery for exactly that — `BIRTH_FIELDS`, the
     * attributes only CREATE may set, which `source_meeting_id` now joins beside
     * `recurring_task_id`. So the whole of this method is: check the meeting, force the
     * provenance, hand it over.
     *
     * The consequence is that everything a task gets, an action item gets: its own discussion
     * conversation, its position on the board, its audit row, its assignment notification. A
     * bespoke INSERT here would have produced a task with none of them and nobody would have
     * noticed until somebody tried to comment on it.
     *
     * ## The project is the meeting's
     *
     * Part D says *"pre-linked project"*. A caller may omit `project_id` and get the meeting's;
     * a caller that names a different one is refused rather than obeyed, because *Convert to
     * Task* is not a general task form with a meeting id stapled to it.
     *
     * Note which ability is asked for: **`tasks.create`**, inside `TaskService`, and not
     * `meetings.use`. Converting an action item creates work for somebody, and who may do that
     * is a question the task side already answers — an employee who may see a meeting but may
     * not create tasks cannot mint them here either.
     *
     * @param  array<string, mixed>  $attributes  as TaskService::create() takes them
     * @param  list<int>  $assigneeIds
     *
     * @throws AuthorizationException
     * @throws MeetingStateException
     */
    public function convertActionItem(
        User $actor,
        Meeting $meeting,
        array $attributes,
        array $assigneeIds = [],
        ?int $primaryId = null,
    ): Task {
        if (! Gate::forUser($actor)->allows('view', $meeting)) {
            // The 404-shaped refusal: somebody who cannot see the meeting must not learn it
            // exists by being told they may not convert its action items.
            throw new AuthorizationException('You are not allowed to see this meeting.');
        }

        // A cancelled meeting produces nothing more. `update()` and `rsvp()` get this from the
        // policy for free; this one asked only `view`, so the rule lived in the controller and
        // any second caller — a job, an import, a console command — would not have inherited it.
        // A meeting that was called off should not be minting work a week later.
        if ($meeting->isCancelled()) {
            throw MeetingStateException::alreadyCancelled();
        }

        // **The project has to be one this actor may see, not merely one the meeting links.**
        // The project is taken from the MEETING, and a meeting is visible to everybody in the
        // room — so without this, somebody invited to a review of a project they are not on
        // could mint a task inside it. `TaskPolicy::create` is about the verb and would let
        // them; `Task::visibleTo()` would then hide the result from its own author. That is the
        // worst shape a permission bug takes: it succeeds, and the evidence disappears.
        if ($meeting->project_id !== null) {
            $project = $meeting->project;

            if ($project === null || ! Gate::forUser($actor)->allows('view', $project)) {
                throw new AuthorizationException(
                    'You are not allowed to add work to this meeting\'s project.',
                );
            }
        }

        $named = $attributes['project_id'] ?? null;

        if ($named !== null && (int) $named !== (int) $meeting->project_id) {
            throw MeetingStateException::actionItemProjectMismatch();
        }

        return $this->tasks->create(
            $actor,
            [
                ...$attributes,
                'project_id' => $meeting->project_id,
                // BIRTH_FIELDS: set in the INSERT that creates the task and never editable
                // afterwards, exactly as a generated task's template is.
                'source_meeting_id' => $meeting->getKey(),
            ],
            $assigneeIds,
            $primaryId,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Reading — and the one that decides what a viewer may know
    |--------------------------------------------------------------------------
    */

    /**
     * **What this viewer may know about this meeting's linked project and task.**
     *
     * The load-bearing method of the slice, and the reason it exists is a case that is easy to
     * miss and impossible to fix quietly afterwards:
     *
     * > A meeting can be linked to a project, and a participant may be in the meeting **without
     * > being on the project**.
     *
     * Tapu is invited to "Buffalo Modular — quarterly review". Tapu is not a member of the
     * Buffalo Modular project. `MeetingPolicy::view()` lets him open the meeting — he was
     * invited, and the participant list is the only record of who was. `ProjectPolicy::view()`
     * refuses him the project. Both are correct, and the meeting's payload sits exactly on the
     * seam.
     *
     * Part C is unambiguous about what happens there: *"A field the requester may not see is
     * **absent** from the payload (not null, not masked)."* So this returns a map with the
     * `project` key **missing**, not `['project' => null]` and not `['project_id' => 41]` — a
     * null tells him there is a project he may not see, and an id tells him which one.
     *
     * The same rule applies to the linked task, which has a tighter audience still:
     * `TaskPolicy::view()` gives an employee the tasks ASSIGNED to them, so being on the
     * project is not enough either.
     *
     * ## What callers must do with it
     *
     * Spread it, do not merge it into a fixed shape:
     *
     *     return ['id' => $m->id, 'title' => $m->title, ...$meetings->linkedContextFor($user, $m)];
     *
     * A Resource that declared `'project' => $this->whenLoaded(...)` and then overwrote it here
     * would put the key back. `MeetingPolicyTest` asserts the absence with `array_key_exists`
     * precisely because `=== null` passes for both the right answer and the wrong one.
     *
     * @return array{project?: array{id: int, name: string}, task?: array{id: int, title: string}}
     */
    public function linkedContextFor(User $viewer, Meeting $meeting): array
    {
        $context = [];

        $project = $meeting->relationLoaded('project') ? $meeting->project : $meeting->project()->first();

        if ($project !== null && Gate::forUser($viewer)->allows('view', $project)) {
            // Id and name only. Anything richer about a project leaves the server through
            // ProjectResource, which is where the price, the client and the internal notes are
            // already filtered per viewer (Part C §2).
            $context['project'] = ['id' => (int) $project->getKey(), 'name' => (string) $project->name];
        }

        $task = $meeting->relationLoaded('task') ? $meeting->task : $meeting->task()->first();

        if ($task !== null && Gate::forUser($viewer)->allows('view', $task)) {
            $context['task'] = ['id' => (int) $task->getKey(), 'title' => (string) $task->title];
        }

        return $context;
    }

    /**
     * Everybody who should hear about this meeting: the people in the room.
     *
     * Used by `NotificationDispatcher`, which then applies the per-object `view` gate on top —
     * the object-shaped half of the rule, as it does for every other type.
     *
     * @return Collection<int, User>
     */
    public function participantsOf(Meeting $meeting): Collection
    {
        return new Collection($meeting->participants()->get()->all());
    }

    /*
    |--------------------------------------------------------------------------
    | The rules behind the writes
    |--------------------------------------------------------------------------
    */

    /**
     * Set the room to exactly these people, plus the organiser.
     *
     * ## Who is dropped, and why it is not a role check
     *
     * Anybody who does not hold `meetings.use`, and anybody not active. That is Part D §12's
     * *"the Accountant has no meetings"* enforced at the moment of writing rather than only at
     * the moment of reading: an organiser who types the Accountant's name into the picker gets
     * a meeting without them, and nobody had to name a role to arrange it. It is also what
     * stops a deactivated employee being invited to next week's stand-up.
     *
     * ## The sync is a diff, not a delete-and-reinsert
     *
     * Rebuilding the list would throw away every RSVP each time the organiser edited the
     * agenda. So: the seats that should go, go; the seats that should arrive, arrive at
     * `pending`; the ones that stay, keep their answer. `upsert` through
     * `meeting_participants_one_seat_each` rather than a read-then-write, so two saves in the
     * same second cannot both decide a seat is missing.
     *
     * @param  list<int>  $userIds
     */
    private function syncParticipants(Meeting $meeting, array $userIds, User $organizer): void
    {
        $wanted = User::query()
            ->whereIn('id', array_map('intval', $userIds))
            ->where('status', UserStatus::Active->value)
            ->get()
            ->filter(fn (User $user): bool => $user->hasPermission(Permission::MeetingsUse))
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        // The organiser is in the room whatever the form said. They hold `meetings.use` by
        // definition — MeetingPolicy::create() asked for it before any of this ran.
        $organizerId = (int) $organizer->getKey();
        $wanted = array_values(array_unique([...$wanted, $organizerId]));

        MeetingParticipant::query()
            ->where('meeting_id', $meeting->getKey())
            ->whereNotIn('user_id', $wanted)
            ->delete();

        $now = now();

        MeetingParticipant::query()->upsert(
            array_map(fn (int $id): array => [
                'meeting_id' => (int) $meeting->getKey(),
                'user_id' => $id,
                // The organiser's answer is not in doubt; everybody else starts unanswered.
                // `upsert` updates nothing, so an existing seat keeps the RSVP it already has.
                'rsvp_status' => $id === $organizerId ? RsvpStatus::Accepted->value : RsvpStatus::Pending->value,
                'created_at' => $now,
                'updated_at' => $now,
            ], $wanted),
            ['meeting_id', 'user_id'],
            [],
        );
    }

    /**
     * Apply whatever the calendar driver learned. Null means *leave what is on the row alone*,
     * never *clear it* — otherwise the manual driver's empty outcome would wipe the link the
     * organiser had just pasted into the same form.
     */
    private function applyOutcome(Meeting $meeting, CalendarOutcome $outcome): void
    {
        if (! $outcome->touchesMeeting()) {
            return;
        }

        $meeting->forceFill(array_filter([
            'google_event_id' => $outcome->googleEventId,
            'meet_link' => $outcome->meetLink,
        ], fn (mixed $value): bool => $value !== null))->save();
    }

    /**
     * The attributes a write may set, cast and cleaned.
     *
     * The Meet link's SHAPE is checked here as well as in the Form Request the controller slice
     * writes, and both call `MeetLink::looksValid()` rather than holding a regex: this service
     * is reachable from a seeder and a console command, neither of which passes through a
     * request. An empty string means "clear it" and becomes null; anything non-empty that is
     * not a Meet link is refused rather than silently dropped, because silently dropping it
     * would show the organiser a saved meeting with no link and no explanation.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function cleanFields(array $attributes): array
    {
        $fields = array_intersect_key($attributes, array_flip(self::FIELDS));

        foreach (['start_at', 'end_at'] as $key) {
            if (array_key_exists($key, $fields) && $fields[$key] !== null) {
                $fields[$key] = $this->toCarbon($fields[$key]);
            }
        }

        if (array_key_exists('agenda', $fields)) {
            $fields['agenda'] = $this->blankToNull($fields['agenda']);
        }

        if (array_key_exists('meet_link', $fields)) {
            $link = trim((string) ($fields['meet_link'] ?? ''));

            if ($link === '') {
                $fields['meet_link'] = null;
            } elseif (! MeetLink::looksValid($link)) {
                throw MeetingStateException::meetLinkNotRecognised();
            } else {
                $fields['meet_link'] = $link;
            }
        }

        return $fields;
    }

    /**
     * A meeting has to end after it starts.
     *
     * Checked here so that a person gets a sentence, and checked again by
     * `meetings_end_after_start` so that the promise does not depend on this method being
     * called — the same division of labour the leave overlap rule has.
     *
     * @throws MeetingStateException
     */
    private function guardTimes(mixed $start, mixed $end): void
    {
        if ($start === null || $end === null) {
            throw MeetingStateException::timeMissing();
        }

        $start = $this->toCarbon($start);
        $end = $this->toCarbon($end);

        if ($end->lessThanOrEqualTo($start)) {
            throw MeetingStateException::endBeforeStart();
        }
    }

    /**
     * @throws MeetingStateException
     */
    private function toCarbon(mixed $value): Carbon
    {
        if ($value instanceof Carbon) {
            return $value->copy();
        }

        try {
            return Carbon::parse((string) $value);
        } catch (\Throwable) {
            throw MeetingStateException::timeMissing();
        }
    }

    /**
     * Compared to the second, because that is the resolution the column stores and the
     * resolution a person picks a meeting time at. Two Carbons differing by microseconds are
     * the same instant as far as "did the time move?" is concerned, and treating them as
     * different would ring eleven bells every time somebody re-saved an unchanged form.
     */
    private function sameInstant(?Carbon $a, ?Carbon $b): bool
    {
        if ($a === null || $b === null) {
            return $a === $b;
        }

        return $a->startOfSecond()->equalTo($b->copy()->startOfSecond());
    }

    private function blankToNull(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
