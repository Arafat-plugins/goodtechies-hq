<?php

namespace App\Http\Resources;

use App\Models\Employee;
use App\Models\User;
use App\Support\AttendanceDay;
use App\Support\Permission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Route;

/**
 * The only way a colleague leaves the server on the Team directory (Part D §2's WORK → Team).
 *
 * ## The list of keys IS the feature
 *
 * The plan's row for this screen ends with six words: *"no salary, no tracking data"*. So this
 * is a field-level serializer in the shape `ProjectResource` and `ClientResource` set, and the
 * assertion in `tests/Feature/Team/TeamDirectoryTest.php` is on the EXACT key list rather than
 * on the absence of a few names anybody happened to think of. A spot check for `salary` would
 * have passed on a payload carrying `base_pay`.
 *
 * Ten keys leave here:
 *
 *   id · name · role · role_label · availability · availability_label · availability_tone ·
 *   holiday_name · is_you · dm_url
 *
 * ## What is deliberately absent, and why each one
 *
 *   - **Every salary field.** There is no money anywhere near this query. `employee_salaries`
 *     is Phase 9's and is not joined, loaded or mentioned.
 *   - **`tracking_mode`.** The plan forbids tracking data, and this is the field that says
 *     which machine watches somebody. Note that *Remote* still appears as an availability WORD
 *     — the plan's own example list is "present / remote-tracking / on leave" — but the word is
 *     a status and the mode is a setting, and only one of the two is being asked for here.
 *   - **`worked_minutes`, `tracked_minutes`, `clock_in`, `clock_out`.** The whole of the
 *     tracking data. `AttendanceService::dayFor()` computes them, this throws them away, and
 *     the Attendance screens — which are scoped by `Employee::attendanceVisibleTo()` — remain
 *     the only place they are readable. A colleague's arrival time is not directory material.
 *   - **`employee_number`, `phone`, `email`, `joining_date`, `employment_type`, `manager_id`.**
 *     None of them is on the plan's line, and every field this carries is a field somebody has
 *     to review for privacy. The cheapest review is the one with nothing in it.
 *   - **Anything countable.** No task count, no hours, no streak, no rank, no order other than
 *     alphabetical. Part H §1 forbids a productivity score and
 *     `tests/Feature/Team/NoScoreTest.php` greps this payload for the vocabulary.
 *
 * ## `dm_url` is resolved here, per reader
 *
 * The same reasoning `NotificationResource` gives for its deep link: the answer depends on the
 * READER, so it cannot be stored and must not be assembled in Vue from an id and a template.
 * Null means *no button* — for yourself, for somebody who cannot be messaged, and for a build
 * where the Messages route does not exist. A control the endpoint would refuse is not drawn
 * (DESIGN.md §5.11).
 *
 * @mixin Employee
 */
class TeamMemberResource extends JsonResource
{
    public function __construct(
        Employee $employee,
        /**
         * Today, as `AttendanceService::dayFor()` answered it — or **null for somebody whose
         * day nothing tracks**, which is not the same as a day with no record.
         *
         * The Accountant has no schedule, so `dayFor()` would call every day of their life an
         * Off Day. That is a true statement about an empty schedule and a false one about the
         * person, and printing it would be this screen inventing an answer. Who is tracked is
         * `Employee::scopeTracked()`'s sentence — tracking mode, never a role — and the
         * controller asks it with that scope rather than restating it.
         */
        private readonly ?AttendanceDay $day,
    ) {
        parent::__construct($employee);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $viewer = $request->user();
        $user = $this->resource->user;
        $isYou = $viewer !== null && $user !== null && $viewer->is($user);

        return [
            'id' => (int) $this->resource->getKey(),
            'name' => $user?->name ?? 'Unknown',

            // The role as the key and as the word. The key is what a test asserts on; the word
            // is what a person reads, and it is composed on the server so the directory and
            // the sidebar's role line cannot spell EMPLOYEE two ways.
            'role' => $this->resource->role?->name?->value,
            'role_label' => $this->roleLabel(),

            // Today, in Part D §8's vocabulary, from the ONE method that answers it
            // (`AttendanceService::dayFor()`, decisions 4-9 and 5-2). Null carries the two
            // cases the screen must not confuse: a tracked person who has not clocked in yet,
            // and somebody nothing tracks at all. `availability_label` tells them apart.
            'availability' => $this->day?->status?->value,
            'availability_label' => $this->availabilityLabel(),
            'availability_tone' => $this->day?->status?->tone(),

            // Why the office is shut, when it is. A company holiday is a fact about the
            // company, not about the person, and it travels whatever the status is — the same
            // rule `AttendanceDay` states. Null on an ordinary day.
            'holiday_name' => $this->day?->holidayName,

            'is_you' => $isYou,
            'dm_url' => $isYou ? null : $this->dmUrl($viewer, $user),
        ];
    }

    /**
     * "Remote employee", not "REMOTE_EMPLOYEE".
     */
    private function roleLabel(): string
    {
        $role = $this->resource->role?->name?->value;

        return $role === null ? 'No role' : ucfirst(strtolower(str_replace('_', ' ', $role)));
    }

    /**
     * The word beside the name.
     *
     * Three cases and no fourth: a status the day has, *No record yet* for a tracked person
     * whose day has not happened, and *Not tracked* for somebody the roster has nothing to say
     * about. Never *Absent* by guess — Absent is what the 23:55 sweep decides, and putting it
     * on somebody's name at ten in the morning would be this screen accusing them.
     */
    private function availabilityLabel(): string
    {
        if ($this->day === null) {
            return 'Not tracked';
        }

        return $this->day->status?->label() ?? 'No record yet';
    }

    /**
     * Where the DM button goes, or null when there is no button.
     *
     * `POST /messages/direct/{user}` opens or creates the one-to-one conversation and redirects
     * into it; it is the other half of the Messages page and it re-checks everything below, so
     * nothing here is a permission — it is the question "will that endpoint refuse this, in
     * which case do not draw the control".
     *
     * **The one rule in this phase that does not go through a policy.** Whether somebody may be
     * sent a direct message is `active + messages.use`, and there is no ability on
     * `ConversationPolicy` that states it for a User (its abilities all take a Conversation,
     * and the DM row may not exist yet). `MessageController` holds the same two lines in a
     * private method. It is asked here as a PERMISSION and never as a role, which is the half
     * of decision 2-31 that can be honoured; the other half is a finding, recorded in the
     * Phase 6 report.
     */
    private function dmUrl(?User $viewer, ?User $subject): ?string
    {
        if ($viewer === null || $subject === null || ! Route::has('messages.direct')) {
            return null;
        }

        $mayMessage = fn (User $one): bool => $one->isActive() && $one->hasPermission(Permission::MessagesUse);

        return $mayMessage($viewer) && $mayMessage($subject)
            ? route('messages.direct', $subject->getKey())
            : null;
    }
}
