<?php

namespace App\Broadcasting;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * `private-notifications.{user}` — one person's own mail, live (master prompt Part D §10).
 *
 * ## What this callback asks, and what it does not
 *
 * Two questions, and the second one is the only one a policy can answer:
 *
 *  1. **Is this channel yours?** `$user->is($subject)`. This is identity, not authorization,
 *     and there is deliberately no `NotificationPolicy::view` to delegate it to — that file
 *     says why: a notification belongs to exactly one person, so "may I see this one" is not
 *     a question worth answering, and an ability that answered it would confirm the row
 *     exists. The channel NAME is the subject here, so the comparison is the whole of it.
 *  2. **Do you have a mailbox at all?** `NotificationPolicy::viewAny`, the same gate
 *     `routes/shared.php` puts on `GET /notifications`. That is the rule that is stated once
 *     and asked twice, and it is the one that moves: the Accountant was refused here for four
 *     phases and is now allowed (decision 5-14), because a leave notification joined the
 *     catalogue — with no edit to this file.
 *
 * Both are needed. The first alone would give a mailbox over the socket to somebody who has
 * none over HTTP, and realtime is a delivery mechanism, not a permission model.
 *
 * `{user}` is implicitly bound, so an id that matches no row is refused by the framework
 * before `join()` runs — 403, the same answer a live user id belonging to somebody else gets.
 * A channel name is not a record: the subscriber supplied it, so there is nothing to conceal
 * by answering 404 instead, and Laravel's broadcast auth has no 404 to give.
 */
class NotificationChannel
{
    public function join(User $user, User $subject): bool
    {
        return $user->is($subject)
            && Gate::forUser($user)->allows('viewAny', Notification::class);
    }
}
