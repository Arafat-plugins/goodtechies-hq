<?php

namespace App\Models;

use App\Support\MeetingStatus;
use App\Support\Permission;
use App\Support\RoleName;
use App\Support\RsvpStatus;
use Database\Factories\MeetingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * One meeting: when it is, who is in it, what it is about, and whether it was called off
 * (master prompt Part D §12).
 *
 * ## Three things this model refuses to store
 *
 *   1. **Whether it has happened.** `hasHappened()` reads `end_at`. See `MeetingStatus` for the
 *      argument; the short version is that a stored copy of a clock reading is wrong every time
 *      a job does not run.
 *   2. **Whether the reminder went out.** That is a `meeting.reminder` row in `notifications`,
 *      which `NotificationService::alreadySentFor()` asks about. See `SendMeetingReminders`.
 *   3. **The linked project's or task's NAME, anywhere a viewer can reach without being asked
 *      about.** A participant can be in a meeting about a project they are not on — see the
 *      privacy note below, which is the load-bearing paragraph of this slice.
 *
 * ## Membership is stored here, unlike a conversation's
 *
 * Decision 2-24 says a conversation's audience is COMPUTED and `conversation_members` grants
 * nothing. A meeting is the other case and the difference is real: a conversation's audience is
 * derivable from its subject, and a meeting's is not — somebody chose who to invite, and that
 * choice is the only record of it. So `meeting_participants` is read by `MeetingPolicy::view()`
 * and it does grant something. The rule that survives intact is the one underneath both: the
 * answer comes from whatever is actually true, never from a role name.
 *
 * ## The privacy consequence, stated once here and tested once in MeetingCalendarLinkTest
 *
 * Because membership is chosen rather than derived, these two facts are independent:
 *
 *   - Tapu is a **participant** of "Buffalo Modular — quarterly review";
 *   - Tapu is **not a member** of the Buffalo Modular project.
 *
 * So Tapu may open the meeting (the participant row says so) and may **not** learn the
 * project's name (`ProjectPolicy::view` says so). Part C is explicit about what that means:
 * the field is **absent** from his payload — not null, not the bare id, not the string
 * "Hidden". `MeetingService::linkedContextFor()` is the one method that answers this, and
 * every payload the controller slice builds must call it rather than reading
 * `$meeting->project?->name`.
 *
 * @property int $id
 * @property string $title
 * @property Carbon $start_at
 * @property Carbon $end_at
 * @property int|null $project_id
 * @property int|null $task_id
 * @property string|null $google_event_id
 * @property string|null $meet_link
 * @property int $organizer_id
 * @property string|null $agenda
 * @property MeetingStatus $status
 */
#[Fillable([
    // What the organiser types, and the only columns an edit form may reach.
    //
    // `status` is absent: the one transition this model has is `cancel`, and MeetingService is
    // its only caller — an edit endpoint that accepted `status` would be a second way to call a
    // meeting off, with none of the notification or calendar work behind it. The same reasoning
    // Phase 1 applied to a project's status and Phase 2 to a task's.
    //
    // `organizer_id` is absent because it is set in the INSERT and is not transferable: Part D
    // §12 gives edit rights to "the organizer and any Admin", so a fillable organiser would be
    // a way to hand your own meeting to somebody else and lose the right to fix it.
    //
    // `google_event_id` is absent because it belongs to the calendar driver, not to a human.
    // `meet_link` IS fillable, because under the manual driver a human is exactly who fills it.
    'title',
    'start_at',
    'end_at',
    'project_id',
    'task_id',
    'agenda',
    'meet_link',
])]
// Decision 10-18: `search_vector` is a STORED GENERATED tsvector of this row's own
// searchable text. `select *` loads it (~282 B a row on `tasks`, measured with
// `pg_column_size`), and it belongs in no payload — so it is hidden from every
// `toArray()`, `toJson()` and `dd()`. Hidden, not dropped: search reads the column.
#[Hidden(['search_vector'])]
class Meeting extends Model
{
    /** @use HasFactory<MeetingFactory> */
    use HasFactory;

    /**
     * How long before a meeting its reminder is due (Part D §12: *"reminder 15 min before"*).
     *
     * A constant and **not** a `settings` key: the settings list in Part D §20 is closed
     * ("Nothing else is added without a recorded decision") and fifteen minutes is the spec's
     * own number rather than a dial anybody asked to turn. `SendMeetingReminders` reads it,
     * `MeetingReminderTest` reads it, and neither hard-codes a 15.
     */
    public const REMINDER_LEAD_MINUTES = 15;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_at' => 'datetime',
            'end_at' => 'datetime',
            'status' => MeetingStatus::class,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    /**
     * @return BelongsTo<User, $this>
     */
    public function organizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'organizer_id');
    }

    /**
     * The project this meeting is about, when it is about one.
     *
     * **Never read straight into a payload.** Whether the viewer may know it is a policy
     * question — see the class docblock and `MeetingService::linkedContextFor()`.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * The task this meeting is about, when it is about one. Same warning as `project()`.
     *
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * Everybody in the room, the organiser included. `rsvp_status` rides on the pivot.
     *
     * @return BelongsToMany<User, $this>
     */
    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'meeting_participants')
            ->withPivot('rsvp_status')
            ->withTimestamps();
    }

    /**
     * The seat rows themselves, for the times the RSVP is the subject rather than the person.
     *
     * @return HasMany<MeetingParticipant, $this>
     */
    public function participantSeats(): HasMany
    {
        return $this->hasMany(MeetingParticipant::class);
    }

    /**
     * The one pad of notes and decisions. One row, enforced by a unique index on `meeting_id`.
     *
     * @return HasOne<MeetingNote, $this>
     */
    public function note(): HasOne
    {
        return $this->hasOne(MeetingNote::class);
    }

    /**
     * The tasks that came out of this meeting's action items.
     *
     * They survive the meeting being deleted (`source_meeting_id` is `nullOnDelete`), which is
     * the whole point of the column — see the `2026_10_01_0004` migration.
     *
     * @return HasMany<Task, $this>
     */
    public function actionItemTasks(): HasMany
    {
        return $this->hasMany(Task::class, 'source_meeting_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * The meetings this person may see, as a QUERY — so a list ends at "no rows" and a lookup
     * by id ends at 404, rather than a controller filtering afterwards (decision 2-37).
     *
     * It is `MeetingPolicy::view()` in SQL, and the two are written to agree line for line:
     *
     *   - no `meetings.use`, or not active → nothing;
     *   - an Admin → everything;
     *   - anybody else → the meetings they organise or are a participant of.
     *
     * @param  Builder<Meeting>  $query
     * @return Builder<Meeting>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if (! $user->isActive() || ! $user->hasPermission(Permission::MeetingsUse)) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->hasRole(RoleName::ADMIN)) {
            return $query;
        }

        return $query->where(function (Builder $scoped) use ($user): void {
            $scoped->where('organizer_id', $user->getKey())
                ->orWhereHas('participantSeats', fn (Builder $seat) => $seat->where('user_id', $user->getKey()));
        });
    }

    /**
     * Meetings that have not been called off.
     *
     * @param  Builder<Meeting>  $query
     * @return Builder<Meeting>
     */
    public function scopeNotCancelled(Builder $query): Builder
    {
        return $query->where('status', MeetingStatus::Scheduled->value);
    }

    /**
     * The meetings whose reminder is due at this instant: still on, not started, and starting
     * inside the lead window.
     *
     * `start_at > $asOf` is the "not for one that already started" half, and it is a strict
     * comparison on purpose — a meeting starting this very second is starting, not upcoming,
     * and a reminder that lands with it is noise. The upper bound is inclusive, so a meeting
     * exactly fifteen minutes out is in.
     *
     * This scope does **not** ask whether a reminder was already sent. That question belongs to
     * the notifications table and is asked in one batch query by the command — see
     * `SendMeetingReminders`.
     *
     * @param  Builder<Meeting>  $query
     * @return Builder<Meeting>
     */
    public function scopeReminderDue(Builder $query, ?Carbon $asOf = null): Builder
    {
        $asOf ??= now();

        return $query->notCancelled()
            ->where('start_at', '>', $asOf)
            ->where('start_at', '<=', $asOf->copy()->addMinutes(self::REMINDER_LEAD_MINUTES));
    }

    /**
     * Meetings that overlap a window — the calendar's question, asked the way a calendar means
     * it: a meeting counts if any part of it falls inside, not only if it starts inside.
     *
     * @param  Builder<Meeting>  $query
     * @return Builder<Meeting>
     */
    public function scopeOverlapping(Builder $query, Carbon $from, Carbon $to): Builder
    {
        return $query->where('start_at', '<', $to)->where('end_at', '>', $from);
    }

    /*
    |--------------------------------------------------------------------------
    | Questions about one meeting
    |--------------------------------------------------------------------------
    */

    /**
     * Has this meeting taken place? A clock reading, computed every time, never stored.
     *
     * Note what it is NOT: a cancelled meeting whose `end_at` is in the past answers `true`
     * here, because the clock has passed it — and `isCancelled()` is the separate question that
     * says it never happened. Two questions, two methods; conflating them into one stored
     * `completed` status is exactly what `MeetingStatus` refuses.
     */
    public function hasHappened(?Carbon $asOf = null): bool
    {
        return $this->end_at !== null && $this->end_at->lessThan($asOf ?? now());
    }

    public function isCancelled(): bool
    {
        return $this->status === MeetingStatus::Cancelled;
    }

    /**
     * Is this meeting still to come, and still on?
     */
    public function isUpcoming(?Carbon $asOf = null): bool
    {
        return ! $this->isCancelled()
            && $this->start_at !== null
            && $this->start_at->greaterThan($asOf ?? now());
    }

    public function isOrganizer(User $user): bool
    {
        return (int) $this->organizer_id === (int) $user->getKey();
    }

    /**
     * Is this person in the room?
     *
     * Reads the loaded relation when there is one and queries when there is not, so a policy
     * call inside a loop over an eager-loaded list is not N queries — and so a caller that
     * forgot to eager-load still gets the right answer rather than a silent `false`.
     */
    public function hasParticipant(User $user): bool
    {
        $id = (int) $user->getKey();

        if ($this->relationLoaded('participantSeats')) {
            return $this->participantSeats->contains(fn (MeetingParticipant $seat): bool => (int) $seat->user_id === $id);
        }

        return $this->participantSeats()->where('user_id', $id)->exists();
    }

    /**
     * This person's answer to the invitation, or null if they are not in the room.
     */
    public function rsvpOf(User $user): ?RsvpStatus
    {
        $seat = $this->relationLoaded('participantSeats')
            ? $this->participantSeats->first(fn (MeetingParticipant $row): bool => (int) $row->user_id === (int) $user->getKey())
            : $this->participantSeats()->where('user_id', $user->getKey())->first();

        return $seat?->rsvp_status;
    }

    /**
     * How long it runs, in minutes. The database guarantees this is positive
     * (`meetings_end_after_start`), so no caller has to defend against a negative.
     */
    public function durationMinutes(): int
    {
        return (int) $this->start_at->diffInMinutes($this->end_at);
    }

    /**
     * The `StatusBadge` key, with the clock folded in — which is the whole reason a meeting's
     * tone is not simply `status->tone()`.
     *
     * Three readings from two stored values: called off, already over, still to come. A stored
     * `completed` status would have made this a one-liner and made every other query wrong; see
     * `MeetingStatus`.
     */
    public function tone(?Carbon $asOf = null): string
    {
        if ($this->isCancelled()) {
            return MeetingStatus::Cancelled->tone();
        }

        return $this->hasHappened($asOf) ? 'done' : MeetingStatus::Scheduled->tone();
    }

    /**
     * The one-word state a screen prints beside that tone. Never the tone alone — DESIGN.md
     * §5.6, and §1.4's measurement of how close two of the eight status colours sit under
     * deuteranopia.
     */
    public function stateLabel(?Carbon $asOf = null): string
    {
        if ($this->isCancelled()) {
            return MeetingStatus::Cancelled->label();
        }

        return $this->hasHappened($asOf) ? 'Held' : MeetingStatus::Scheduled->label();
    }
}
