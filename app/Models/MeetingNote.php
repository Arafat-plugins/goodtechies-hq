<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What was said and what was settled, for one meeting (master prompt Part D §12: *"notes +
 * decisions recorded"*).
 *
 * **One row per meeting**, and that is a unique index on `meeting_id` rather than a convention
 * — see the `2026_10_01_0003` migration. `MeetingService::recordNotes()` writes through
 * `updateOrCreate`, so two people closing the same meeting at the same moment end with one pad
 * and the later text, not two pads and a coin toss.
 *
 * **The action items are not here.** An action item becomes a task, and the task is where it
 * lives, carrying `source_meeting_id` back. There is no `meeting_action_items` table and there
 * must not be one: it would be a second to-do list, invisible to the board, the calendar, My
 * Tasks and every report that counts work.
 *
 * Who may read this is not this model's business and is never asked of it directly: the notes
 * are reached through the meeting, and `MeetingPolicy::view()` is what guards the meeting.
 *
 * @property int $id
 * @property int $meeting_id
 * @property string|null $notes
 * @property string|null $decisions
 */
#[Fillable(['meeting_id', 'notes', 'decisions'])]
class MeetingNote extends Model
{
    /**
     * @return BelongsTo<Meeting, $this>
     */
    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }

    /**
     * Is there anything on this pad at all?
     *
     * A row with both columns empty is a row somebody opened the notes panel and closed it
     * again; the service refuses to create one, and this is how a caller asks.
     */
    public function isEmpty(): bool
    {
        return trim((string) $this->notes) === '' && trim((string) $this->decisions) === '';
    }
}
