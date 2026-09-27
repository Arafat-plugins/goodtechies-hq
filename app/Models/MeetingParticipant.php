<?php

namespace App\Models;

use App\Support\RsvpStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One seat at one meeting, and the answer given for it.
 *
 * ## Why this pivot earns a model and `task_assignees` does not
 *
 * The repo's rule is that a pivot gets a model when something other than the pair is a subject
 * in its own right. `task_assignees` carries `is_primary`, which is read through
 * `Task::primaryAssignee()` and never on its own, so it has no model. This one is different in
 * two ways:
 *
 *   - **`rsvp_status` is written by the person named on the row, not by the parent's owner.**
 *     `MeetingPolicy::rsvp()` is "a participant, and only for themselves", which is an ability
 *     about THIS row — so there has to be a row to pass to a policy and to look up by
 *     `(meeting, user)`.
 *   - **The participant list is read from the user's side as often as from the meeting's.**
 *     "My meetings" starts here, and `Meeting::scopeVisibleTo()` asks this table by
 *     `whereHas`, which needs the relation to exist.
 *
 * It carries no logic of its own beyond the cast: everything that decides anything is in
 * `MeetingService` or `MeetingPolicy`.
 *
 * @property int $id
 * @property int $meeting_id
 * @property int $user_id
 * @property RsvpStatus $rsvp_status
 */
#[Fillable(['meeting_id', 'user_id', 'rsvp_status'])]
class MeetingParticipant extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rsvp_status' => RsvpStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Meeting, $this>
     */
    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
