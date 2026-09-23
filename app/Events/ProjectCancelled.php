<?php

namespace App\Events;

use App\Models\Project;
use App\Models\User;

/**
 * A project was cancelled with open tasks still on it (spec Part D §21: "Project cancelled →
 * open tasks prompted bulk-close/reassign").
 *
 * This is the Phase 1 side effect finally connected to something. ProjectService recorded the
 * intent in an activity line ending "(Phase 2)" and could do no more, because there was no
 * engine to prompt anybody through. There is one now.
 *
 * It carries the ids of the tasks that were open at the moment of cancellation — a count in the
 * notification and a list in the payload, so the prompt can say how much work it is asking for
 * and the screen that answers it knows which tasks it is being asked about. The tasks themselves
 * are NOT touched: cancelling a project does not cancel work, it asks a person to decide.
 */
class ProjectCancelled
{
    /**
     * @param  list<int>  $openTaskIds
     */
    public function __construct(
        public readonly Project $project,
        public readonly User $actor,
        public readonly array $openTaskIds,
    ) {}
}
