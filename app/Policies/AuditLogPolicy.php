<?php

namespace App\Policies;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\Permission;

/**
 * Who may read the audit log. Nobody may write it, and that is not this file's decision.
 *
 * ## One ability, because there is one question
 *
 * `audit.view` is Part C §1's *View audit log* row: **ADMIN ✅, everybody else ❌**. It is asked
 * once, for the screen, and there is deliberately no `view(User, AuditLog)` here.
 *
 * That absence is the rule, not an omission. **Every row in this table is in scope for anybody
 * holding `audit.view`** — the log's whole purpose is that the one person allowed to read it can
 * read all of it, including the rows about themselves and the rows about the Admin who granted
 * them the key. So a per-row check could only ever return true, and a 404 for "a row outside
 * your scope" would be describing a scope that does not exist. Part C's absence rule (a record
 * the requester may not see is 404 by id) has nothing to narrow here: the refusal is at the
 * route, for the whole screen, and it is a 403 because a route's existence is not sensitive
 * (Part B §3 rule 1).
 *
 * It follows that `AuditLogResource` may carry a restricted field — a salary, a project price —
 * in `old_value` or `new_value`, and **must**: that is what the log is for (Part C §4 names
 * *salary changed* and *project price changed* as events that must be recorded with old and new
 * values). The field-absence rule applies to the endpoints that serve the *record*; this one
 * serves the history of the act, to the one role allowed to read history. What keeps it from
 * leaking is that the resource has exactly one caller and the route has exactly one gate.
 *
 * ## There is no `create`, `update` or `delete`, and none can be added here
 *
 * `audit_logs` is append-only **at the database**: its migration runs
 * `REVOKE UPDATE, DELETE, TRUNCATE ON audit_logs FROM hq_app` immediately after `CREATE TABLE`,
 * and because `hq_app` is not the table's owner it cannot grant them back to itself (Part B §3
 * rule 3). `tests/Feature/Database/AuditLogAppendOnlyTest.php` proves it by running raw UPDATE,
 * DELETE and TRUNCATE as `hq_app` inside a savepoint and asserting `42501 insufficient_privilege`
 * each time. `AuditLog` refuses `updating` and `deleting` at the model as well.
 *
 * So a policy method permitting a write would not be a security hole — it would be a lie, a
 * `true` in front of a statement Postgres refuses. Inserting is `App\Services\AuditLogger`'s and
 * nothing else's, and it asks no policy: a log that could be talked out of recording something
 * is not a log. Acceptance criterion 11 is *"the audit log … is not editable through the
 * application by any role, including ADMIN"*, and the way that is achieved is that there is no
 * route, no controller action and no policy ability for it.
 */
class AuditLogPolicy extends Policy
{
    /**
     * Read the log. The screen's only question.
     */
    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::AuditView);
    }

    /**
     * Read one entry — the same question, because every row is in scope (see the class docblock).
     *
     * It exists so that a caller which happens to hold a row can ask about it and get the same
     * answer as the list did, rather than being tempted to invent a scope. The `$log` is unused
     * on purpose.
     */
    public function view(User $user, AuditLog $log): bool
    {
        return $this->viewAny($user);
    }
}
