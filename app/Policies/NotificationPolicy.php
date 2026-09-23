<?php

namespace App\Policies;

use App\Models\User;
use App\Support\NotificationType;

/**
 * Who may reach the bell and the Notification Center.
 *
 * There is only one ability, and there is deliberately no `view`, `update` or `delete`. A
 * notification belongs to exactly one person, so "may I see this one" is not a question worth
 * answering: the controllers scope every lookup to the signed-in user and `firstOrFail()`, so
 * somebody else's row is **404 rather than 403** (Part C §1 — a record you may not see is
 * absent, not refused). A policy that answered 403 here would confirm the row exists.
 *
 * ## Why viewAny is not simply "is signed in"
 *
 * Because the Accountant must reach no notification route in this phase, and must reach one the
 * moment Phase 9 gives them a payroll notification — and neither of those is a rule about
 * Accountants. It is a rule about the catalogue: you may open your mailbox if there is a kind
 * of mail you could receive. Today the catalogue is task types and the cancelled-project prompt,
 * every one of which requires `tasks.view`; the Accountant holds no `tasks.*` key and so falls
 * out here the same way they fall out of TaskPolicy, which does not name them either.
 *
 * Adding `payroll.view_own` to a Phase 9 notification type opens these routes to them with no
 * edit to this file. That is the intended behaviour and the reason the question is asked of
 * NotificationType rather than of a role list.
 */
class NotificationPolicy extends Policy
{
    public function viewAny(User $user): bool
    {
        if (! $user->isActive()) {
            return false;
        }

        foreach (NotificationType::cases() as $type) {
            if ($user->hasPermission($type->requires())) {
                return true;
            }
        }

        return false;
    }
}
