<?php

namespace App\Broadcasting;

use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * `private-tasks.{user}` — "a task you can see changed", for one person's Tasks screens (flow F1).
 *
 * Shaped exactly like `NotificationChannel`, and for the same two reasons:
 *
 *  1. **Is this channel yours?** `$user->is($subject)`. Identity, not authorization — the channel
 *     NAME is the subject, so nobody, an Admin included, may listen on somebody else's.
 *  2. **Do you have a Tasks screen at all?** `TaskPolicy::viewAny`, the gate every Tasks route
 *     asks. Somebody with no `tasks.view` gets no tasks channel over the socket either.
 *
 * What arrives here was decided on the SERVER, per task, by `TaskPolicy::view` before and after
 * the change (`TaskService::viewerIds()`), and it is `{task_id, kind}` only — so this callback
 * never has to answer "may they see this task". A frame tells the screen to re-read; the re-read
 * is what `Task::visibleTo()` answers.
 *
 * A separate channel rather than `notifications.{user}`, because reusing the bell's would change
 * what the bell's listeners receive; the bell is out of this flow's reach.
 *
 * `{user}` is implicitly bound, so an id that matches no row is refused with 403 before `join()`.
 */
class UserTasksChannel
{
    public function join(User $user, User $subject): bool
    {
        return $user->is($subject)
            && Gate::forUser($user)->allows('viewAny', Task::class);
    }
}
