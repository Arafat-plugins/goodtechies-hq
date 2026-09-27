<?php

namespace App\Http\Resources;

use App\Models\Employee;
use App\Models\Project;
use App\Models\User;
use App\Models\UserProjectPermission;
use App\Services\EmployeeAdministrationService;
use App\Support\EmploymentType;
use App\Support\TaskBucket;
use App\Support\Weekday;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * The only way a personnel record leaves the server (master prompt Part B §3 rule 1).
 *
 * # This resource must never carry a salary
 *
 * Not `base_salary`, not `allowance`, not `net_salary`, not a total, not a currency figure of any
 * kind, under any condition and for any requester. `employee_salaries` is Phase 9's table and it
 * has its own surface — `/salaries`, behind `can:payroll.approve` — with its own permission and
 * its own tests. **A personnel screen that happened to include a salary column would be the
 * single worst leak in this application**: it is the one screen every Admin opens for ordinary
 * reasons, it is reached with `roles.manage` rather than a payroll key, and the figure would be
 * on it for the rest of the product's life before anybody noticed. So there is no branch here
 * that could add one, and `tests/Feature/Workforce/EmployeePrivacyTest.php` asserts it against
 * the ENCODED response of every endpoint rather than against this file — a promise in a docblock
 * that only this file keeps is a promise somebody deletes.
 *
 * # Privacy is per field and per requester
 *
 * A field the requester may not see is **absent** from the array, never null and never masked,
 * so a payload cannot leak a value by its shape (Part C §1). The split is:
 *
 *   - the **roster** fields — who they are, what they do, whether they are still here — go to
 *     anybody `EmployeePolicy::view` lets through, which is a holder of `roles.manage` or the
 *     person themselves;
 *   - the **record** fields — email, phone, employment type, joining date, manager, last
 *     sign-in — are personal data about one colleague and go only to a reader `::view` allows,
 *     which is the same gate asked explicitly so that widening the routes cannot widen the
 *     payload;
 *   - the **detail** fields — memberships, grants, when the login was switched off — are behind
 *     the `employee_detail` request attribute as well, the way `TaskResource` keeps
 *     `available_transitions` off a list of two hundred rows.
 *
 * `projects` is a REFERENCE list and not the project payload: id, name, status and the role on
 * the project, which is what "they are on this" needs, and not one commercial or finance field —
 * those leave the server through `ProjectResource` and through nothing else. The same shape
 * `TaskResource::taskStubs()` uses for a dependency, for the same reason.
 *
 * @mixin Employee
 */
class EmployeeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $mayRead = $user !== null && Gate::forUser($user)->allows('view', $this->resource);
        $detail = $request->attributes->get('employee_detail') === true;

        $data = [
            'id' => $this->id,
            'name' => $this->resource->user?->name,
            'employee_number' => $this->employee_number,

            'role' => $this->resource->role?->name?->value,
            'tracking_mode' => $this->tracking_mode?->value,

            'status' => $this->status?->value,
            // UserStatus carries no label(): active/inactive read straight back as words, the
            // way ClientResource reads a client's.
            'status_label' => $this->status === null ? null : Str::headline($this->status->value),

            'schedule' => $this->schedule(),
            'schedule_summary' => $this->scheduleSummary(),

            'projects_count' => (int) ($this->resource->projects_count ?? 0),
            'project_permissions_count' => (int) ($this->resource->project_permissions_count ?? 0),

            // What deactivating this person would leave behind, counted BEFORE the act so the
            // confirmation can state it (spec §47's "open tasks flagged for reassignment"). It
            // travels on the list rows too, because the confirmation opens from both screens.
            'impact' => [
                'open_task_count' => (int) ($this->resource->open_task_count ?? 0),
                // `?assignee_id=&bucket=open` — the two filters `TaskService::filters()` really
                // reads, and `TaskBucket::Open` is the same definition the count was made with
                // (`EmployeeAdministrationService::openTaskIds()`), so the number and the list it
                // links to cannot disagree.
                'open_tasks_url' => route('admin.tasks.index', [
                    'assignee_id' => $this->id,
                    'bucket' => TaskBucket::Open->value,
                ], absolute: false),
            ],

            'url' => route('admin.employees.show', $this->resource, absolute: false),
            'is_you' => $user !== null && $this->user_id === $user->id,
            'permissions' => $this->permissions($user),
        ];

        if ($mayRead) {
            $data['email'] = $this->resource->user?->email;
            $data['phone'] = $this->phone;
            $data['employment_type'] = $this->employment_type;
            $data['employment_type_label'] = EmploymentType::tryFrom((string) $this->employment_type)?->label();
            $data['joining_date'] = $this->joining_date?->toDateString();
            $data['manager'] = $this->manager();
            $data['last_login_at'] = $this->resource->user?->last_login_at?->toIso8601String();
        }

        if ($mayRead && $detail) {
            $data['deactivated_at'] = app(EmployeeAdministrationService::class)
                ->deactivatedAt($this->resource)?->toIso8601String();
            $data['projects'] = $this->projects();
            $data['project_permissions'] = $this->grants($user);
        }

        return $data;
    }

    /**
     * The working week, in the shape `ScheduleService::rowFor()` already sends — one week, one
     * serialization, so the Work Schedule editor and this screen cannot describe it differently.
     *
     * Null is a real answer and the screen says so: a tracked employee with no schedule cannot
     * clock in and is never marked absent, which is the thing an Admin most needs to see here.
     *
     * @return array<string, mixed>|null
     */
    private function schedule(): ?array
    {
        $schedule = $this->resource->schedule;

        if ($schedule === null) {
            return null;
        }

        return [
            'working_days' => $this->workingDays(),
            'working_hours_per_day' => (float) $schedule->working_hours_per_day,
            // `H:i`, never the column's `H:i:s` — the editor's control is a `time` input and
            // seconds it cannot show are seconds it would silently drop on the next save.
            'start_time' => $schedule->start_time === null
                ? null
                : substr((string) $schedule->start_time, 0, 5),
            'office_or_remote' => $schedule->office_or_remote,
        ];
    }

    /**
     * "Sun, Mon, Tue · 8 h/day · starts 09:00" — the server's own sentence for the week.
     *
     * The client can compose the same words from `schedule`; this is sent so the list and the
     * detail print one sentence that the server owns, and so a row that carries no schedule
     * carries no sentence rather than an invented one.
     */
    private function scheduleSummary(): ?string
    {
        $schedule = $this->resource->schedule;

        if ($schedule === null) {
            return null;
        }

        $days = array_map(
            fn (Weekday $day): string => $day->shortLabel(),
            array_filter(
                array_map(fn (string $key): ?Weekday => Weekday::tryFrom($key), $this->workingDays()),
            ),
        );

        return sprintf(
            '%s · %s h/day · %s',
            $days === [] ? 'No working days' : implode(', ', $days),
            rtrim(rtrim(number_format((float) $schedule->working_hours_per_day, 2, '.', ''), '0'), '.'),
            $schedule->start_time === null
                ? 'no start time'
                : 'starts '.substr((string) $schedule->start_time, 0, 5),
        );
    }

    /**
     * The working days in `Weekday`'s order, never the order they happen to be stored in.
     *
     * @return list<string>
     */
    private function workingDays(): array
    {
        $stored = $this->resource->schedule?->working_days;
        $stored = is_array($stored) ? $stored : [];

        return array_values(array_filter(
            Weekday::values(),
            fn (string $day): bool => in_array($day, $stored, true),
        ));
    }

    /**
     * @return array{id: int, name: string|null}|null
     */
    private function manager(): ?array
    {
        $manager = $this->resource->manager;

        return $manager === null ? null : [
            'id' => $manager->id,
            'name' => $manager->user?->name,
        ];
    }

    /**
     * The projects they are on — a reference list, not the project payload. See the class
     * docblock: no client, no price, no contract, no profitability, ever.
     *
     * Read from the loaded relation only, so a row that did not ask for memberships does not
     * become a query.
     *
     * @return list<array<string, mixed>>
     */
    private function projects(): array
    {
        if (! $this->resource->relationLoaded('projects')) {
            return [];
        }

        return $this->resource->projects
            ->map(fn (Project $project): array => [
                'id' => $project->id,
                'name' => $project->name,
                'role_on_project' => $project->pivot?->role_on_project,
                'status' => $project->status?->value,
                'status_label' => $project->status?->label(),
                'url' => route('admin.projects.show', $project, absolute: false),
            ])
            ->values()
            ->all();
    }

    /**
     * The project-level grants of Part C §1, from the loaded relation.
     *
     * There is no `granted_by` key, and that is a fact about the table rather than a choice:
     * `user_project_permissions` has no such column and this phase adds no migration. Who made
     * the grant is in `audit_logs` under `permission.changed`, which is where the answer belongs
     * anyway.
     *
     * @return list<array<string, mixed>>
     */
    private function grants(?User $user): array
    {
        if (! $this->resource->relationLoaded('projectPermissionGrants')) {
            return [];
        }

        $canRevoke = $user !== null
            && Gate::forUser($user)->allows('managePermissions', $this->resource);

        /** @var iterable<int, UserProjectPermission> $grants */
        $grants = $this->resource->getRelation('projectPermissionGrants');

        $rows = [];

        foreach ($grants as $grant) {
            $rows[] = [
                'id' => $grant->id,
                'project' => [
                    'id' => $grant->project?->id,
                    'name' => $grant->project?->name,
                    'url' => $grant->project === null
                        ? null
                        : route('admin.projects.show', $grant->project, absolute: false),
                ],
                'permission' => [
                    'key' => $grant->permission?->key?->value,
                    'label' => $grant->permission?->key === null
                        ? null
                        : Str::headline(str_replace('.', ' ', $grant->permission->key->value)),
                ],
                'granted_at' => $grant->created_at?->toIso8601String(),
                'can_revoke' => $canRevoke,
            ];
        }

        return $rows;
    }

    /**
     * What this requester may do to this record, answered by the policy rather than inferred —
     * so the screen never draws a control the endpoint would refuse, and never has to know a
     * role name to decide (decisions 2-28, 2-31).
     *
     * There is still no `can_update` key, and there is still no employee-update endpoint: the two
     * writable fields of Part D §2's *"role/schedule/tracking_mode"* each answer for themselves
     * (`can_change_role`, `can_change_tracking_mode`), and the schedule is the Work Schedule
     * editor's. An absent key means no, and a key that promised a control with nothing behind it
     * would be worse than the missing control.
     *
     * @return array<string, bool>
     */
    private function permissions(?User $user): array
    {
        if ($user === null) {
            return [
                'can_change_role' => false,
                'can_change_tracking_mode' => false,
                'can_deactivate' => false,
                'can_reactivate' => false,
                'can_reset_password' => false,
                'can_manage_permissions' => false,
            ];
        }

        $gate = Gate::forUser($user);

        return [
            'can_change_role' => $gate->allows('changeRole', $this->resource),
            'can_change_tracking_mode' => $gate->allows('changeTrackingMode', $this->resource),
            'can_deactivate' => $gate->allows('deactivate', $this->resource),
            'can_reactivate' => $gate->allows('reactivate', $this->resource),
            'can_reset_password' => $gate->allows('resetPassword', $this->resource),
            'can_manage_permissions' => $gate->allows('managePermissions', $this->resource),
        ];
    }
}
