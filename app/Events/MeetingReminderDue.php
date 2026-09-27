<?php

namespace App\Events;

use App\Models\Meeting;
use Illuminate\Support\Carbon;

/**
 * A meeting starts within the reminder window and nobody has been told yet (master prompt
 * Part D §12: *"reminder 15 min before"*).
 *
 * ## Why this is a fourth event the brief did not name
 *
 * The brief lists three meeting events and says the notifications must go *"through the
 * existing NotificationService/NotificationDispatcher … do not build a parallel path"*. A
 * console command that called `NotificationService::notify()` itself would be exactly that
 * parallel path: `NotificationDispatcher`'s docblock states that its `subscribe()` map is
 * *"deliberately the only list of 'which events produce notifications' in the application"*,
 * and a command sending its own would make that sentence false.
 *
 * The repo has met this before and answered it the same way twice: `hq:flag-overdue` fires
 * `TaskBecameOverdue` and `hq:notify-due-tomorrow` fires `TaskDueTomorrow`, both for events
 * nobody caused. This is the third of that shape.
 *
 * ## It has no actor, and that is load-bearing
 *
 * Nobody did this; a clock passed a mark. `NotificationService` drops the actor from every
 * recipient list, so an actor here would silently exclude the organiser — and the organiser is
 * the one participant who must be reminded, because it is their meeting and they are the person
 * who has to be in the room to start it. A null actor is what makes Part D's *"participants and
 * the organizer"* come out right without a special case.
 *
 * "Exactly once, however often the scheduler runs" is not decided here either — the command
 * asks `NotificationService::alreadySentFor()` which meetings have had one, and fires this only
 * for the rest. See `SendMeetingReminders`.
 */
class MeetingReminderDue
{
    public function __construct(
        public readonly Meeting $meeting,
        public readonly Carbon $asOf,
    ) {}
}
