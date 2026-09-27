<?php

namespace App\Events;

use App\Models\Meeting;
use App\Models\User;

/**
 * A meeting was called (Phase 7, master prompt Part D §12: *"participants notified"*).
 *
 * Fired AFTER the side effects and inside the same transaction as them — the participant rows
 * exist and the calendar driver's outcome is already on the meeting by the time anything hears
 * about this. A listener that read `$meeting->participants` before the sync would notify an
 * empty room.
 *
 * The actor is the organiser, always. `NotificationService::eligible()` drops the actor from
 * every recipient list, which is how Part D's *"participants notified"* comes to mean
 * "participants other than the person who called it" without this class or the dispatcher
 * saying so.
 */
class MeetingScheduled
{
    public function __construct(
        public readonly Meeting $meeting,
        public readonly User $actor,
    ) {}
}
