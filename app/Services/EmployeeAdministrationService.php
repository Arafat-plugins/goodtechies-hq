<?php

namespace App\Services;

use App\Exceptions\SelfModificationException;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Permission as PermissionModel;
use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use App\Models\UserProjectPermission;
use App\Support\AuditEvent;
use App\Support\Permission;
use App\Support\RoleName;
use App\Support\TaskBucket;
use App\Support\TrackingMode;
use App\Support\UserStatus;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Employees / Users & Roles (master prompt Part D §2, Phase 12) — the whole of the write side.
 *
 * Part D §2 settles the shape in one line: *"Users & Roles is the same screen family (Employees
 * list → employee detail → role/schedule/tracking_mode)"*. One family, so one service: reading
 * the list, reading one record, hiring, changing a role, changing a tracking mode, switching a
 * login off and on again, and the project-level grants of Part C §1 all live here, and every one
 * of them is audited. The schedule is the third of Part D §2's three and the one exception:
 * `ScheduleService` is the only writer of `schedules`, and `create()` calls it rather than
 * restating what a working week may look like.
 *
 * ## Two rules this class is the single statement of
 *
 * **Nobody acts on their own account.** `guard()` says it once — you may not change your own
 * role, change your own tracking mode, deactivate yourself, reactivate yourself or grant
 * yourself a project permission — and every method that needs it calls it rather than
 * re-testing `user_id === actor->id`. The
 * actor's own permission check is split out into `guardActor()` because hiring has no subject to
 * compare against, and that is the only reason the split exists.
 *
 * **Nothing is ever deleted** (Part B §3 rule 11). There is no delete method here and there is
 * no route for one. A departure is `status = inactive` on both rows, the sessions ended and the
 * remember token rotated; the user row, the tasks, the tracked time, the attendance, the leave
 * and the messages all stay.
 *
 * ## Deactivation surfaces the open tasks, and never reassigns them
 *
 * Spec §47: *"Employee leaves company → status=inactive, login disabled, **open tasks flagged
 * for reassignment**, history retained."* Flagged, not reassigned — the same word Part D §5 uses
 * for an assignee on leave, where Part D §21 spells out the consequence: *"Flag … to Admin; **no
 * auto-reassign**"*. So the count travels on every payload this service produces, before the
 * act, and `deactivate()` writes the ids it found into the audit row. What it does NOT do is
 * require a reassignment first: cutting off the login of somebody who has left is a security
 * act, and holding it behind re-homing fourteen tasks would delay the security consequence for
 * a data-tidying one. Reassignment already has an endpoint (`PUT /admin/tasks/{task}/assignees`)
 * and a second one here would be a second statement of the assignment rules.
 *
 * ## There is no salary anywhere in this file
 *
 * `employee_salaries` is Phase 9's and it leaves the server through `SalaryController` behind
 * `can:payroll.approve`. A personnel screen that happened to carry a pay figure would be the
 * worst leak in the application, so this service never reads that table and `EmployeeResource`
 * never carries it.
 */
class EmployeeAdministrationService
{
    /**
     * The permission keys a project-level grant may carry.
     *
     * Exactly the keys a policy READS out of `user_project_permissions` today, which is one:
     * `ProjectPolicy::viewFinance()` (the example Part C §1 gives — *"a MANAGER may be granted
     * `projects.view_finance` for one project"*). The list is short on purpose. A grant of a key
     * no policy reads is a row that grants nothing while the screen says otherwise, which is a
     * worse failure than not offering it — so a key is added here in the same change that
     * teaches a policy to read it.
     *
     * @var list<Permission>
     */
    public const GRANTABLE_PERMISSIONS = [Permission::ProjectsViewFinance];

    /**
     * The roles this screen may assign.
     *
     * Every role but MANAGER, which Part C §1 puts in the roles table and assigns to nobody:
     * *"MANAGER exists in the roles table from Phase 0 but is **assigned to nobody** and gets no
     * dedicated UI in MVP."* Offering it would create the one thing that line forbids.
     *
     * @var list<RoleName>
     */
    public const ASSIGNABLE_ROLES = [
        RoleName::ADMIN,
        RoleName::EMPLOYEE,
        RoleName::REMOTE_EMPLOYEE,
        RoleName::ACCOUNTANT,
    ];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ActivityLogger $activity,
        private readonly ScheduleService $schedules,
    ) {}

    /**
     * Every employee this viewer administers, active and inactive, newest name order.
     *
     * **Inactive people are on the list** and are not filtered out anywhere: Part B §3 rule 11
     * keeps the record for good, and a departed colleague who vanished from the only screen that
     * says what happened to them would be a deletion in everything but name. The resource marks
     * them; the query does not hide them.
     *
     * `$filters` are a VIEW choice and never a privacy rule: `status` narrows what is shown and
     * `search` narrows it further, and neither can widen the scope `administered()` set.
     *
     * @param  array{status?: string, search?: ?string}  $filters
     * @return Collection<int, Employee>
     */
    public function listFor(?User $viewer, array $filters = []): Collection
    {
        $status = $filters['status'] ?? 'all';
        $search = $filters['search'] ?? null;

        return $this->administered($viewer)
            ->when(
                UserStatus::tryFrom((string) $status) !== null,
                fn (Builder $query) => $query->where('employees.status', $status),
            )
            ->when(
                is_string($search) && $search !== '',
                fn (Builder $query) => $query->where(function (Builder $match) use ($search): void {
                    $match->where('users.name', 'ilike', '%'.$search.'%')
                        ->orWhere('users.email', 'ilike', '%'.$search.'%')
                        ->orWhere('employees.employee_number', 'ilike', '%'.$search.'%');
                }),
            )
            ->with(['user', 'role', 'schedule', 'manager.user'])
            // `select()` before the counts, because it REPLACES the column list: the join below
            // puts a second `id` and a second `status` in scope, and the aggregates have to be
            // added after the narrowing rather than wiped by it.
            ->join('users', 'users.id', '=', 'employees.user_id')
            ->select('employees.*')
            ->withCount('projects')
            ->addSelect($this->countSelects($viewer))
            ->orderBy('users.name')
            ->get();
    }

    /**
     * Who a new hire may be given as a manager: the active employees in this viewer's own scope.
     *
     * A separate, cheap query rather than a second `listFor()` call — the select's options need a
     * name and an id, not two correlated counts per row. `manager_id` is what the two 🟡 *own
     * team* scopes on `Employee` read, and until this screen existed nothing wrote it.
     *
     * @return Collection<int, Employee>
     */
    public function assignableManagers(?User $viewer): Collection
    {
        return $this->administered($viewer)
            ->with('user')
            ->where('employees.status', UserStatus::Active->value)
            ->join('users', 'users.id', '=', 'employees.user_id')
            ->select('employees.*')
            ->orderBy('users.name')
            ->get();
    }

    /**
     * One employee, or null — which the controller turns into a **404 and never a 403**.
     *
     * The same scope the list is narrowed by, asked of one row (Part C §1: a record the
     * requester may not see is absent, and absence by id is 404). It is a query and not a policy
     * call for exactly that reason: a refusal would confirm the record exists.
     */
    public function findFor(?User $viewer, Employee|int $employee): ?Employee
    {
        return $this->administered($viewer)
            ->with(['user', 'role', 'schedule', 'manager.user'])
            ->select('employees.*')
            ->withCount('projects')
            ->addSelect($this->countSelects($viewer))
            ->whereKey($employee instanceof Employee ? $employee->getKey() : $employee)
            ->first();
    }

    /**
     * Hire somebody: the user, the employee record and the working week, in one transaction.
     *
     * ## First sign-in
     *
     * Part H §1 puts email out of scope for the MVP and there is no password-reset route in
     * `routes/auth.php` — login and the two-factor challenge are the whole of the guest surface
     * — so there is nothing to mail an invitation to and nothing for an invitation link to point
     * at. The account is therefore created with a **generated** password, which is returned from
     * here ONCE so the Admin can read it out, and never stored in plaintext, never audited and
     * never put in a payload by any resource. The new colleague signs in with it and changes it
     * at Profile → Password, which has existed since Phase 0 and applies
     * `Password::defaults()` (twelve characters, breach-checked).
     *
     * An ADMIN or ACCOUNTANT hire is then walked through TOTP enrolment by the existing
     * `two-factor` middleware before they can reach anything, so a finance account cannot be
     * used without it (Part C §3).
     *
     * @param  array{name: string, email: string, employee_number?: ?string, role: RoleName, tracking_mode: string, phone?: ?string, employment_type: string, joining_date?: ?string, manager_id?: ?int, schedule?: array{working_days: list<string>, working_hours_per_day: float, start_time: ?string, office_or_remote: string}}  $attributes
     * @return array{employee: Employee, password: string}
     *
     * @throws AuthorizationException
     */
    public function create(User $actor, array $attributes): array
    {
        $this->guardActor($actor);

        // Letters and digits only, and long enough that sixteen of them is not a number anybody
        // guesses: it is read out loud or pasted into a message once, so a symbol in it is a
        // transcription error waiting to happen rather than entropy that matters at this length.
        $password = Str::password(16, true, true, false);

        $employee = DB::transaction(function () use ($actor, $attributes, $password): Employee {
            $user = User::create([
                'name' => $attributes['name'],
                'email' => $attributes['email'],
                'password' => $password,
            ]);

            $employee = Employee::create([
                'user_id' => $user->getKey(),
                'employee_number' => ($attributes['employee_number'] ?? null) ?: $this->nextEmployeeNumber(),
                'role_id' => Role::where('name', $attributes['role']->value)->firstOrFail()->getKey(),
                'manager_id' => $attributes['manager_id'] ?? null,
                'phone' => $attributes['phone'] ?? null,
                'employment_type' => $attributes['employment_type'],
                'tracking_mode' => $attributes['tracking_mode'],
                'joining_date' => $attributes['joining_date'] ?? null,
                'status' => UserStatus::Active,
            ]);

            // Through ScheduleService, which is the only writer of `schedules` and audits the
            // week as `schedule.changed`. Writing the row here would have been a second place
            // that decides what a working week is allowed to look like.
            if (($attributes['schedule'] ?? null) !== null) {
                $this->schedules->save($employee, $attributes['schedule'], $actor);
            }

            // No credential in the audit row, and none in the activity line either. What is
            // recorded is who was hired, as what, and how their time is measured.
            $this->audit->record(
                AuditEvent::EmployeeCreated,
                $employee,
                null,
                [
                    'name' => $user->name,
                    'email' => $user->email,
                    'employee_number' => $employee->employee_number,
                    'role' => $attributes['role']->value,
                    'tracking_mode' => $employee->tracking_mode?->value,
                    'employment_type' => $employee->employment_type,
                    'joining_date' => $employee->joining_date?->toDateString(),
                    'manager_id' => $employee->manager_id,
                    'status' => UserStatus::Active->value,
                ],
                $actor,
            );

            $this->activity->record(
                $employee,
                sprintf('%s added as %s', $user->name, $attributes['role']->value),
                $actor,
            );

            return $employee;
        });

        return ['employee' => $employee, 'password' => $password];
    }

    /**
     * Re-issue somebody's sign-in password, and hand it back once.
     *
     * ## Why this exists at all
     *
     * Without it an account can become permanently unusable by nobody's mistake. There is no
     * email in the MVP (Part H §1) and `routes/auth.php` has no password-reset route, so the
     * password `create()` mints is said once and never again; Profile → Password needs the
     * CURRENT one to set a new one. So a new hire who loses the sentence before they sign in,
     * or anybody who simply forgets, had an account that no Admin in the agency could fix and
     * that could never be signed into again. The only remedy was to deactivate them and create
     * a second person, which splits one human being's history across two employee records —
     * exactly what Part B §3 rule 11 keeps a single record forever to prevent.
     *
     * ## What it does, and what it deliberately also does
     *
     * It mints the same way `create()` does, and then **ends their sessions and rotates their
     * remember token**, which `create()` has no reason to do and `deactivate()` does for a
     * different reason. A password reset that left the old sessions signed in would be the
     * wrong shape of tool: the case where it matters most is the one where somebody else may
     * have had the old credential, and in that case the browser already holding a session is
     * the thing you are trying to remove.
     *
     * ## What is NOT recorded
     *
     * The password, in the audit row or anywhere else. `old_value` is null — a hash is not a
     * before-value anybody should be shown — and the new value records only that it happened
     * and to whom. What the audit row is FOR is the question you ask afterwards: who could
     * have signed in as this person, and when.
     *
     * Guarded like every other act here, which includes the self-guard: an Admin resets their
     * own password at Profile → Password, where they have to prove they know the current one.
     *
     * @return string the new password, to be shown once and never stored
     *
     * @throws SelfModificationException
     * @throws AuthorizationException
     */
    public function resetPassword(User $actor, Employee $employee): string
    {
        $this->guard($actor, $employee, 'reset the password of');

        // The same alphabet and length as `create()`, for the same reason: it is read out loud
        // or pasted once, so a symbol in it is a transcription error rather than entropy.
        $password = Str::password(16, true, true, false);

        DB::transaction(function () use ($actor, $employee, $password): void {
            $user = $employee->user;

            $user->forceFill(['password' => $password])->save();

            // See the docblock: the old sessions are the thing a reset is most often for.
            DB::table('sessions')->where('user_id', $user->getKey())->delete();

            $user->setRememberToken(Str::random(60));
            $user->save();

            $this->audit->record(
                AuditEvent::EmployeePasswordReset,
                $employee,
                null,
                ['email' => $user->email, 'sessions_ended' => true],
                $actor,
            );

            $this->activity->record($employee, 'Sign-in password re-issued', $actor);
        });

        return $password;
    }

    /**
     * Change what somebody may reach across the whole agency.
     *
     * Tracking mode is deliberately left as it is; `changeTrackingMode()` below is how that
     * moves. Part D §1 is why they are two calls and not one: *"`employees.tracking_mode ∈
     * {remote_timer, office_attendance, none}` is a per-employee field, **not a role rule**"*.
     * Deriving one from the other would make Tapu's timer a consequence of his role name, and
     * an office-based REMOTE_EMPLOYEE — or a remote EMPLOYEE — impossible to record.
     *
     * MANAGER is refused before this is reached: `self::ASSIGNABLE_ROLES` is what the two Form
     * Requests validate against, and Part C §1 assigns that role to nobody in the MVP.
     *
     * The new role takes effect on the target's **next request** — `User::hasPermission()` memoizes
     * per instance and caches nothing across requests — and their sessions are deliberately left
     * alone (see `EmployeeController::changeRole()` for why).
     *
     * @throws SelfModificationException
     * @throws AuthorizationException
     */
    public function changeRole(User $actor, Employee $employee, RoleName $role): void
    {
        $this->guard($actor, $employee, 'change the role of');

        $old = $employee->role?->name;

        if ($old === $role) {
            return;
        }

        DB::transaction(function () use ($actor, $employee, $role, $old): void {
            $employee->role()->associate(Role::where('name', $role->value)->firstOrFail());
            $employee->save();

            $this->audit->record(
                AuditEvent::RoleChanged,
                $employee,
                ['role' => $old?->value],
                ['role' => $role->value],
                $actor,
            );

            $this->activity->record(
                $employee,
                sprintf('Role changed from %s to %s', $old->value ?? 'none', $role->value),
                $actor,
            );
        });
    }

    /**
     * Change how somebody's working time is measured. The other half of Part D §2's
     * *"role/schedule/tracking_mode"*, and until now the field had **no writer anywhere in the
     * application** — `ScheduleService`'s docblock defers it here by name.
     *
     * ## Switching somebody does not migrate their history, and cannot
     *
     * The two modes keep their days in different tables, and that is decision 4-11 rather than
     * an implementation detail: an `office_attendance` employee has rows in `attendance_records`
     * and no timer, and a `remote_timer` employee has rows in `time_entries` and **no attendance
     * row at all** — `AttendanceService::markAbsent()` and `AttendancePolicy` both require
     * `office_attendance` before one can be written, so no hand can create one. Decision 5-9
     * held the same line for an approved leave day.
     *
     * So the days before the switch stay in the table they were recorded in, and the days after
     * it go to the other one. Nothing here rewrites them, and nothing should: a month of clock-in
     * times is not convertible into tracked minutes, and inventing either would put a number into
     * a record Phase 9 pays from. The consequence is stated on the screen instead, in words, in
     * `EmployeeRoleCard.vue`'s confirmation — which is the only honest place for it.
     *
     * `none` is a real answer and not a gap: the Accountant is `none`, has no schedule row, is
     * never marked absent and runs no timer (`LeaveService`, `PayrollService`).
     *
     * @throws SelfModificationException
     * @throws AuthorizationException
     */
    public function changeTrackingMode(User $actor, Employee $employee, TrackingMode $mode): void
    {
        // The same single statement of the self rule the other four writes use — never a second
        // check in a controller. Part C §1: *"Manage roles/permissions — ADMIN ✅ (not own
        // account)"*, and how your own days are measured is that same account.
        $this->guard($actor, $employee, 'change the tracking mode of');

        $old = $employee->tracking_mode;

        if ($old === $mode) {
            return;
        }

        DB::transaction(function () use ($actor, $employee, $mode, $old): void {
            $employee->update(['tracking_mode' => $mode]);

            $this->audit->record(
                AuditEvent::EmployeeTrackingModeChanged,
                $employee,
                ['tracking_mode' => $old?->value],
                ['tracking_mode' => $mode->value],
                $actor,
            );

            $this->activity->record(
                $employee,
                sprintf(
                    'Tracking mode changed from %s to %s',
                    $old?->value ?? 'none',
                    $mode->value,
                ),
                $actor,
            );
        });
    }

    /**
     * Deactivates the employee and their login, and ends their sessions. The user row is kept.
     *
     * The open tasks it found go into the audit row — see the class docblock for why they are
     * flagged and not reassigned. Nothing about this call depends on them: an Admin switching
     * off the access of somebody who has left is never blocked by work that is still open.
     *
     * @throws SelfModificationException
     * @throws AuthorizationException
     */
    public function deactivate(User $actor, Employee $employee): void
    {
        $this->guard($actor, $employee, 'deactivate');

        $openTaskIds = $this->openTaskIds($employee);

        DB::transaction(function () use ($actor, $employee, $openTaskIds): void {
            $user = $employee->user;
            $old = ['status' => $employee->status?->value, 'user_status' => $user->status?->value];

            $employee->update(['status' => UserStatus::Inactive]);
            $user->update(['status' => UserStatus::Inactive]);

            // Ends the stored sessions, then kills the remember-me cookies that would
            // otherwise sign them straight back in.
            DB::table('sessions')->where('user_id', $user->id)->delete();

            $user->setRememberToken(Str::random(60));
            $user->save();

            $this->audit->record(
                AuditEvent::EmployeeDeactivated,
                $employee,
                $old,
                [
                    'status' => UserStatus::Inactive->value,
                    'user_status' => UserStatus::Inactive->value,
                    // Spec §47's "open tasks flagged for reassignment", as evidence rather than
                    // as a promise: the ids that were still owed on the day the login stopped.
                    'open_task_count' => count($openTaskIds),
                    'open_task_ids' => $openTaskIds,
                ],
                $actor,
            );

            $this->activity->record($employee, 'Employee deactivated', $actor);
        });
    }

    /**
     * Switches a login back on. The inverse of `deactivate()`, and deliberately not its mirror.
     *
     * It does **not** restore the sessions the deactivation ended and it does not put back the
     * old remember token: those were destroyed, the person signs in again, and a reactivation
     * that resurrected a browser somebody else may now be sitting at would be the one way this
     * pair could be worse than no pair at all.
     *
     * @throws SelfModificationException
     * @throws AuthorizationException
     */
    public function reactivate(User $actor, Employee $employee): void
    {
        $this->guard($actor, $employee, 'reactivate');

        DB::transaction(function () use ($actor, $employee): void {
            $user = $employee->user;
            $old = ['status' => $employee->status?->value, 'user_status' => $user->status?->value];

            $employee->update(['status' => UserStatus::Active]);
            $user->update(['status' => UserStatus::Active]);

            $this->audit->record(
                AuditEvent::EmployeeReactivated,
                $employee,
                $old,
                ['status' => UserStatus::Active->value, 'user_status' => UserStatus::Active->value],
                $actor,
            );

            $this->activity->record($employee, 'Employee reactivated', $actor);
        });
    }

    /**
     * Grant one permission on one project, on top of whatever the role already gives.
     *
     * Part C §1: *"Project-level overrides (`user_project_permissions`) layer on top"*. The row
     * is keyed on the USER and not the employee, because that is what `ProjectPolicy` reads; the
     * screen addresses the person, and the employee record is how it finds them.
     *
     * Idempotent: granting what somebody already holds returns the existing row and writes no
     * second audit entry, because nothing changed.
     *
     * @throws SelfModificationException
     * @throws AuthorizationException
     */
    public function grantProjectPermission(
        User $actor,
        Employee $employee,
        Project $project,
        Permission $permission,
    ): UserProjectPermission {
        $this->guard($actor, $employee, 'grant project permissions to');
        $this->guardGrantable($permission);

        $permissionId = PermissionModel::where('key', $permission->value)->firstOrFail()->getKey();

        return DB::transaction(function () use ($actor, $employee, $project, $permission, $permissionId): UserProjectPermission {
            $existing = UserProjectPermission::query()
                ->where('user_id', $employee->user_id)
                ->where('project_id', $project->getKey())
                ->where('permission_id', $permissionId)
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $grant = UserProjectPermission::create([
                'user_id' => $employee->user_id,
                'project_id' => $project->getKey(),
                'permission_id' => $permissionId,
            ]);

            $this->audit->record(
                AuditEvent::PermissionChanged,
                $employee,
                $this->grantAuditValues($employee, $project, $permission, granted: false),
                $this->grantAuditValues($employee, $project, $permission, granted: true),
                $actor,
            );

            $this->activity->record(
                $employee,
                sprintf('Granted %s on %s', $permission->value, $project->name),
                $actor,
            );

            return $grant;
        });
    }

    /**
     * Take a grant away again. The row goes; the audit row is what is left of it.
     *
     * This is not a deletion in the sense Part B §3 rule 11 forbids — that rule is about people
     * and records of work. A grant is a live privilege, and "revoked" has to mean the policy
     * stops answering yes; a soft-deleted grant would be a privilege that looks removed and
     * still reads as present unless every policy remembered to ask.
     *
     * @throws SelfModificationException
     * @throws AuthorizationException
     */
    public function revokeProjectPermission(User $actor, Employee $employee, UserProjectPermission $grant): void
    {
        $this->guard($actor, $employee, 'change the project permissions of');

        DB::transaction(function () use ($actor, $employee, $grant): void {
            $project = $grant->project;
            $permission = $grant->permission?->key;

            $grant->delete();

            $this->audit->record(
                AuditEvent::PermissionChanged,
                $employee,
                $this->grantAuditValues($employee, $project, $permission, granted: true),
                $this->grantAuditValues($employee, $project, $permission, granted: false),
                $actor,
            );

            $this->activity->record(
                $employee,
                sprintf(
                    'Revoked %s on %s',
                    $permission?->value ?? 'a permission',
                    $project?->name ?? 'a project',
                ),
                $actor,
            );
        });
    }

    /**
     * This employee's project-level grants, newest first, with the project and the key.
     *
     * @return Collection<int, UserProjectPermission>
     */
    public function grantsFor(Employee $employee): Collection
    {
        return UserProjectPermission::query()
            ->with(['project', 'permission'])
            ->where('user_id', $employee->user_id)
            ->orderByDesc('id')
            ->get();
    }

    /**
     * The projects a grant may be made on: the ones this actor can see, not archived.
     *
     * Archived projects are read-only everywhere else (Part D §4), so a grant on one would be a
     * privilege over a record nobody may change.
     *
     * @return Collection<int, Project>
     */
    public function grantableProjects(?User $viewer): Collection
    {
        if ($viewer === null) {
            /** @var Collection<int, Project> */
            return Project::query()->whereRaw('1 = 0')->get();
        }

        return Project::query()
            ->visibleTo($viewer)
            ->whereNull('archived_at')
            ->orderBy('name')
            ->get();
    }

    /**
     * When this employee's login was switched off, read off the audit trail.
     *
     * There is no `employees.deactivated_at` column and this phase adds no migration — but the
     * fact is already recorded, once per deactivation, in the append-only log. Reading it there
     * means the date on the screen is the date in the audit trail by construction, which a
     * second column could only promise.
     */
    public function deactivatedAt(Employee $employee): ?Carbon
    {
        if ($employee->status !== UserStatus::Inactive) {
            return null;
        }

        $at = AuditLog::query()
            ->where('event', AuditEvent::EmployeeDeactivated->value)
            ->where('target_type', $employee->getMorphClass())
            ->where('target_id', $employee->getKey())
            ->orderByDesc('id')
            ->value('created_at');

        return $at === null ? null : Carbon::parse($at);
    }

    /**
     * The ids of the tasks this employee still owes work on.
     *
     * `TaskBucket::Open` and not a hand-written status list: it is the definition the Tasks
     * screen's own Open bucket uses, so "12 open tasks" here means the twelve rows the link
     * lands on.
     *
     * @return list<int>
     */
    public function openTaskIds(Employee $employee): array
    {
        return TaskBucket::Open
            ->apply(Task::query()->notArchived(), Carbon::today())
            ->forEmployee($employee)
            ->orderBy('tasks.id')
            ->pluck('tasks.id')
            ->map(fn (int|string $id): int => (int) $id)
            ->all();
    }

    /**
     * The employees this viewer may administer.
     *
     * The same three cases `Employee::scopeAttendanceVisibleTo()` draws, keyed on
     * `roles.manage` instead of the attendance keys — nobody without it, everybody for an
     * ADMIN, and the people who report to them plus themselves for a holder who is not an
     * Admin. Part C §1's *Manage roles/permissions* row gives the key to ADMIN alone today, so
     * the middle case is the whole of production; the third exists because a role that is given
     * the key later must not silently receive the whole company with it.
     *
     * It is a query and not a policy call because that is what makes somebody out of scope
     * ABSENT rather than refused: the list simply does not contain them, and `findFor()` comes
     * back empty, so **404 is what the controller has rather than what it decides** (Part C).
     *
     * @return Builder<Employee>
     */
    private function administered(?User $viewer): Builder
    {
        $query = Employee::query();

        if ($viewer === null || ! $viewer->isActive() || ! $viewer->hasPermission(Permission::RolesManage)) {
            return $query->whereRaw('1 = 0');
        }

        if ($viewer->hasRole(RoleName::ADMIN)) {
            return $query;
        }

        $own = $viewer->employee?->getKey();

        return $query->where(function (Builder $scoped) use ($own): void {
            $scoped->where('employees.manager_id', $own)->orWhere('employees.id', $own);
        });
    }

    /**
     * The two counts every row carries, as correlated subqueries rather than a query per row.
     *
     * `open_task_count` is the deactivation consequence, present BEFORE the act so the
     * confirmation can state it; `project_permissions_count` is how many grants the person
     * holds. Both are read by `EmployeeResource` off the model, the way `TaskResource` reads
     * `attachment_count`.
     *
     * @return array<string, Builder<Task>|Builder<UserProjectPermission>>
     */
    private function countSelects(?User $viewer): array
    {
        $tasks = TaskBucket::Open
            ->apply(Task::query()->notArchived(), Carbon::today())
            ->join('task_assignees', 'task_assignees.task_id', '=', 'tasks.id')
            ->whereColumn('task_assignees.employee_id', 'employees.id')
            ->selectRaw('count(*)');

        if ($viewer !== null) {
            // Scoped to what the viewer may read, so a count can never be evidence of a task
            // they may not see. For an Admin — every holder of `roles.manage` today — this
            // narrows nothing, which is exactly why it is written rather than reasoned about.
            $tasks->visibleTo($viewer);
        }

        return [
            'open_task_count' => $tasks,
            'project_permissions_count' => UserProjectPermission::query()
                ->whereColumn('user_project_permissions.user_id', 'employees.user_id')
                ->selectRaw('count(*)'),
        ];
    }

    /**
     * `GT-006` after `GT-005`: the next number in the seeded series.
     *
     * Only used when the form leaves the field blank. `SELECT … FOR UPDATE` on the rows of the
     * series, inside the caller's transaction, so two Admins hiring at once cannot both read
     * the same maximum — and `employees.employee_number` is unique anyway, so the worst case is
     * a refused insert rather than a duplicate.
     */
    private function nextEmployeeNumber(): string
    {
        // The maximum is taken in PHP rather than in SQL because PostgreSQL refuses `FOR UPDATE`
        // beside an aggregate — and the lock is the point of the query, so the aggregate is what
        // gives way.
        $numbers = Employee::query()
            ->whereRaw("employee_number ~ '^GT-[0-9]+$'")
            ->lockForUpdate()
            ->pluck('employee_number')
            ->map(fn (string $number): int => (int) substr($number, 3))
            ->all();

        return sprintf('GT-%03d', ($numbers === [] ? 0 : max($numbers)) + 1);
    }

    /**
     * @return array<string, mixed>
     */
    private function grantAuditValues(
        Employee $employee,
        ?Project $project,
        ?Permission $permission,
        bool $granted,
    ): array {
        return [
            'user_id' => $employee->user_id,
            'project_id' => $project?->getKey(),
            'project' => $project?->name,
            'permission' => $permission?->value,
            'granted' => $granted,
        ];
    }

    /**
     * @throws AuthorizationException
     */
    private function guardGrantable(Permission $permission): void
    {
        if (! in_array($permission, self::GRANTABLE_PERMISSIONS, true)) {
            throw new AuthorizationException(sprintf(
                '%s cannot be granted per project.',
                $permission->value,
            ));
        }
    }

    private function guard(User $actor, Employee $employee, string $action): void
    {
        if ($employee->user_id === $actor->id) {
            throw SelfModificationException::forAction($action);
        }

        $this->guardActor($actor);
    }

    /**
     * The actor's own half of `guard()`, split out for `create()` — which has no subject to
     * compare the actor against, and is the only reason this is a second method.
     *
     * @throws AuthorizationException
     */
    private function guardActor(User $actor): void
    {
        if (! $actor->isActive() || ! $actor->hasPermission(Permission::RolesManage)) {
            throw new AuthorizationException('You are not allowed to manage employees.');
        }
    }
}
