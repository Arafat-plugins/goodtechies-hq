<?php

namespace App\Events;

use App\Models\Meeting;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * A meeting was edited (Phase 7).
 *
 * ## `$timeChanged` is the whole reason this class carries more than a meeting
 *
 * Part D §11's bell has to stay worth looking at, and an edit is the event most likely to ruin
 * that: fixing a typo in an agenda is an edit, adding a line to it is an edit, pasting the Meet
 * link in five minutes later is an edit. None of those is news to the eleven other people in
 * the room. **A time that moved is.**
 *
 * So the diff is computed where both values are in hand — inside `MeetingService::update()`,
 * which is the only thing holding the row before and after — and travels on the event as a
 * single boolean. `NotificationDispatcher` reads it and notifies nobody when it is false.
 *
 * It is not computed in the dispatcher because by then the model has been saved and
 * `getOriginal()` is the freshly written value; it is not computed twice because a rule stated
 * twice is a rule that gets changed once.
 *
 * The previous times ride along so the trail and any later screen can say what it moved FROM.
 * They are nullable only for the theoretical caller that has no previous state; every real one
 * has both.
 */
class MeetingUpdated
{
    public function __construct(
        public readonly Meeting $meeting,
        public readonly User $actor,
        /** Did `start_at` or `end_at` actually change? The only question the bell cares about. */
        public readonly bool $timeChanged = false,
        public readonly ?Carbon $previousStartAt = null,
        public readonly ?Carbon $previousEndAt = null,
    ) {}
}
