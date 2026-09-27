<?php

namespace App\Events;

use App\Models\Meeting;
use App\Models\User;

/**
 * A meeting was called off (Phase 7, master prompt Part D §12: *"Cancel: status Cancelled,
 * participants notified, linked tasks not deleted"*).
 *
 * Fired after the status is written, after the calendar driver has had its turn and after the
 * driver's outcome has been recorded on the activity trail — inside the same transaction as all
 * three. What it does NOT carry is any instruction about Google: that is the organiser's to
 * read on the meeting's own trail, not eleven people's to receive in a bell.
 *
 * Linked tasks are untouched and there is nothing on this event about them, because there is
 * nothing to say: `MeetingService::cancel()` does not look at them at all, which is a stronger
 * guarantee than looking at them and deciding to leave them alone.
 */
class MeetingCancelled
{
    public function __construct(
        public readonly Meeting $meeting,
        public readonly User $actor,
    ) {}
}
