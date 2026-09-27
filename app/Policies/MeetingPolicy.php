<?php

namespace App\Policies;

use App\Models\Meeting;
use App\Models\User;
use App\Support\Permission;
use App\Support\RoleName;

/**
 * Who may see, edit, cancel and answer a meeting (master prompt Part C; Part D §12).
 *
 * Part D §12 gives four clauses and this file is those four clauses:
 *
 * > *"any non-Accountant active user creates a meeting; the organizer and any Admin edit or
 * > cancel it; participants see it; the Accountant has no meetings."*
 *
 * ## The Accountant is not named here, and that is the enforcement
 *
 * *"The Accountant has no meetings"* is spelled `meetings.use` — a permission they hold none of
 * — exactly as Phase 6 spelled *"the Accountant has no messaging routes"* as `messages.use`
 * (decisions 2-13, 2-31). `viewAny()` and `create()` ask for the key and the Accountant falls
 * out of both without appearing in either. `Meeting::scopeVisibleTo()` asks the same key first,
 * so they get an empty list rather than a refusal, and a 404 on any id.
 *
 * ## The Admin override IS a role, and the brief's premise about that was wrong
 *
 * The brief that commissioned this slice said *"no policy in this codebase names a role"*. Six
 * do — `ProjectPolicy`, `TaskPolicy`, `ClientPolicy`, `RecurringTaskPolicy`, `TagPolicy` and
 * `FilePolicy` — and every one of them uses `hasRole(RoleName::ADMIN)` for precisely this: an
 * administrative override on somebody else's record. `FilePolicy::delete()` is the closest
 * parallel, and reads *"the uploader or an Admin"* where this reads *"the organizer or an
 * Admin"*.
 *
 * The alternative was to invent a key — `meetings.manage_others`, say — and grant it to ADMIN
 * alone. That was rejected because it would be a **new key for a scope**, which Part C §1
 * forbids in as many words: *"The 🟡 cells are implemented as the key plus a scope check in the
 * Policy … never as a separate key."* "The organiser, or an administrator" is a scope on
 * `meetings.use`, and this is where a scope belongs.
 *
 * So the division is: the **capability** is a permission (and the Accountant's exclusion rides
 * on it, with their role named nowhere); the **scope within that capability** is the organiser,
 * the participant list, and the Admin override.
 *
 * ## 404 or 403 — which refusal each denial becomes
 *
 * Part C is explicit and the two are not interchangeable:
 *
 * | ability  | who passes                              | a refusal is | because                                                        |
 * | -------- | --------------------------------------- | ------------ | -------------------------------------------------------------- |
 * | `viewAny` | holds `meetings.use`, active            | **403**      | about the person and the feature, not about any record          |
 * | `view`    | organiser · participant · Admin         | **404**      | they must not learn the meeting exists                          |
 * | `update`  | organiser · Admin                       | **403**      | they can already see it; the ACT is refused, not the record     |
 * | `cancel`  | organiser · Admin                       | **403**      | same                                                            |
 * | `rsvp`    | a participant, for themselves only      | both         | 404 if they cannot see it at all, 403 if they can but are not in it |
 *
 * The controller slice makes that real the way every other slice in this repo does: it resolves
 * ids through `Meeting::visibleTo($user)->findOrFail()`, so a meeting this person may not see
 * is *not found* rather than *forbidden* — the 404 is what the lookup does, not a decision the
 * controller takes. Then it calls the ability, and a `false` there is Laravel's 403.
 *
 * Getting that backwards is the bug this table exists to prevent: a 403 on `view` tells an
 * employee that a meeting with that id exists and that they were not invited, which is a fact
 * about other people's calendars that Part C does not let them have.
 *
 * ## `update` deliberately does not ask whether the meeting is over
 *
 * A task is read-only once archived, and it would have been easy to make a meeting read-only
 * once `end_at` has passed. It is not, because the notes and decisions Part D §12 asks for are
 * written **after** the meeting, by definition, and because correcting the record of a meeting
 * that has happened is a normal thing to want. What is refused after the fact is nothing; what
 * is refused on a CANCELLED meeting is editing, because editing something called off is how a
 * meeting comes back from the dead without anybody being told.
 */
class MeetingPolicy extends Policy
{
    /**
     * The Meetings list and calendar.
     *
     * The key and nothing else: what they see in it is `Meeting::scopeVisibleTo()`'s business,
     * and an employee with no meetings gets an empty calendar rather than a refusal.
     */
    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::MeetingsUse);
    }

    /**
     * Calling a meeting. Part D §12: *"any non-Accountant active user creates a meeting"* —
     * which is the key, and the key is the whole of it. No seniority, no project membership:
     * two developers agreeing to talk at four o'clock do not need an Admin.
     */
    public function create(User $user): bool
    {
        return $this->allows($user, Permission::MeetingsUse);
    }

    /**
     * Seeing one meeting. **A refusal here is a 404, never a 403** — see the table above.
     *
     * The organiser, anybody in the room, or an Admin. Note what is *not* here: being a member
     * of the meeting's linked project buys nothing. A project channel's audience is derivable
     * and a meeting's is not — somebody chose who to invite, and a colleague on the same
     * project was not chosen. The inverse of that is the privacy case this slice is really
     * about, and it is handled in `MeetingService::linkedContextFor()`: a participant who is
     * NOT on the linked project may open the meeting and must not learn the project's name.
     */
    public function view(User $user, Meeting $meeting): bool
    {
        if (! $this->allows($user, Permission::MeetingsUse)) {
            return false;
        }

        if ($user->hasRole(RoleName::ADMIN)) {
            return true;
        }

        return $meeting->isOrganizer($user) || $meeting->hasParticipant($user);
    }

    /**
     * Editing one. **A refusal here is a 403.**
     *
     * Part D §12: *"the organizer and any Admin edit or cancel it"*. A participant who is
     * neither may not edit — and the record is not hidden from them, so the honest answer is
     * *you may not do that*, not *there is no such thing*.
     *
     * A cancelled meeting is read-only for everyone, the organiser included. Reviving one by
     * editing it would leave eleven people holding a cancellation notice for a meeting that is
     * back on; the way to have it again is to schedule it, which notifies them.
     */
    public function update(User $user, Meeting $meeting): bool
    {
        if ($meeting->isCancelled() || ! $this->view($user, $meeting)) {
            return false;
        }

        return $meeting->isOrganizer($user) || $user->hasRole(RoleName::ADMIN);
    }

    /**
     * Calling it off. **A refusal here is a 403.**
     *
     * The same people as `update()` and, unlike `update()`, still allowed on a meeting that has
     * already happened — an organiser who forgot to cancel yesterday's call should still be able
     * to mark it as called off, and the participants should still be told. What is refused is
     * cancelling something already cancelled, which would fire a second round of notifications
     * for no new fact.
     */
    public function cancel(User $user, Meeting $meeting): bool
    {
        if ($meeting->isCancelled() || ! $this->view($user, $meeting)) {
            return false;
        }

        return $meeting->isOrganizer($user) || $user->hasRole(RoleName::ADMIN);
    }

    /**
     * Answering an invitation. A participant, **and only for themselves**.
     *
     * The second half is the part worth writing down: an Admin may edit and cancel anybody's
     * meeting, and may not answer for them. Nobody may. An RSVP is a statement about whether a
     * named person will be somewhere, and there is no seniority under which somebody else's
     * attendance becomes your fact to assert. The ability therefore takes the SUBJECT as well —
     * the row and the person it is about — and the organiser's own `accepted` row is the one
     * exception the service writes without asking, because calling a meeting is already saying
     * you will be at it.
     *
     * An Admin who is a participant may RSVP, as the participant they are, and not as an Admin.
     * A cancelled meeting takes no answers: there is nothing left to accept.
     */
    public function rsvp(User $user, Meeting $meeting, ?User $subject = null): bool
    {
        $subject ??= $user;

        if ((int) $subject->getKey() !== (int) $user->getKey()) {
            return false;
        }

        if ($meeting->isCancelled() || ! $this->allows($user, Permission::MeetingsUse)) {
            return false;
        }

        return $meeting->hasParticipant($user);
    }
}
