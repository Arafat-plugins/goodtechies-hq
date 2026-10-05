<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Employees\StoreEmployeeRequest;
use App\Http\Requests\Employees\UpdateEmployeeRoleRequest;
use App\Http\Requests\Employees\UpdateEmployeeTrackingModeRequest;
use App\Http\Resources\EmployeeResource;
use App\Models\Device;
use App\Models\Employee;
use App\Models\Project;
use App\Models\User;
use App\Services\EmployeeAdministrationService;
use App\Support\EmploymentType;
use App\Support\Permission;
use App\Support\RoleName;
use App\Support\TrackingMode;
use App\Support\UserStatus;
use App\Support\Weekday;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Admin → Workforce → Employees, which is also Admin → Users & Roles (master prompt Part D §2:
 * *"Users & Roles is the same screen family (Employees list → employee detail →
 * role/schedule/tracking_mode)"* — one family, so one controller).
 *
 * ## Inactive people are on the list
 *
 * Part B §3 rule 11: *"Employee departure = `status = inactive`, never delete."* The list carries
 * them, marked, and there is **no destroy action here and none for a user** — not a soft delete,
 * not an archive. `?status=` narrows the list as a view choice; nothing hides anybody by default.
 *
 * ## 404 by id, 403 by route
 *
 * An employee outside the requester's scope is re-resolved through
 * `EmployeeAdministrationService::findFor()` before any policy is asked, so they are **absent**
 * and answer 404 — a refusal would confirm the record exists (Part C). The routes themselves are
 * 403 for every other shell (`surface:admin`) and for any role without `roles.manage`
 * (`can:roles.manage` on the group), and `EmployeePolicy` is asked again behind both.
 *
 * ## Nobody acts on their own account
 *
 * Part C §1's *Manage roles/permissions* row is *"ADMIN ✅ (not own account)"*. The policy answers
 * 403 for a self-action and `EmployeeAdministrationService::guard()` refuses the call as well, so
 * the rule holds for a caller that is not an HTTP request. Each row's own `permissions` block and
 * `is_you` are what stop the screen drawing the control in the first place.
 *
 * ## Two fields are writable from the detail, and each is its own endpoint
 *
 * Part D §2 names the family *"Employees list → employee detail → role/schedule/tracking_mode"*.
 * The schedule belongs to `ScheduleController` (`ScheduleService` is the only writer of
 * `schedules`); the other two are `PUT …/{employee}/role` and `PUT …/{employee}/tracking-mode`
 * here. They are two endpoints and not one `update`, because they are two rules: a role decides
 * what somebody may reach across the agency and is an event Part C §4 requires in the audit log,
 * and a tracking mode decides which table their working day is recorded in. Each is one field,
 * each is confirmed on screen before it is sent, and neither is a key in a general record form
 * that could change it by accident — the same split `POST /admin/projects/{project}/status`
 * keeps, where `PUT /admin/projects/{project}` ignores `status` by design.
 *
 * ## The first-sign-in password has its own channel
 *
 * `store()` flashes it under `self::FIRST_SIGN_IN` and `show()` reads it once. It is not in
 * `success`, because `FlashMessage.vue` renders that as a persistent Alert in the same strip as
 * every routine confirmation, and because the sentence that channel used to carry — *"It is not
 * shown again"* — was not true: Inertia keeps page props in `history.state`, so Back re-rendered
 * it. `show()` now pairs the credential with `Inertia::encryptHistory()` and
 * `Inertia::clearHistory()`, and the panel rolls the key once more after mounting, so Back has
 * nothing to restore and must re-ask the server — which has already spent the flash.
 *
 * ## No salary and no score
 *
 * `EmployeeResource` cannot carry a pay figure (its docblock says why) and nothing here ranks
 * anybody: Part H §1 forbids productivity scoring, so the only number on a row is how much work
 * is still open, which exists to be handed over rather than to be judged.
 */
class EmployeeController extends Controller
{
    /**
     * The one-shot session key the generated first-sign-in password travels on.
     *
     * Flashed by `store()`, read once by `show()`, and never anywhere else. Flash data lives for
     * exactly one request, so an Admin who never opens the redirect target leaves no credential
     * behind in the session store.
     */
    private const FIRST_SIGN_IN = 'employee.first_sign_in';

    public function __construct(private readonly EmployeeAdministrationService $employees) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Employee::class);

        $filters = $this->filters($request);
        $user = $request->user();

        return Inertia::render('Admin/Employees/Index', [
            'employees' => EmployeeResource::collection($this->employees->listFor($user, $filters))
                ->resolve($request),
            'filters' => $filters,
            // Absent means the create form is not offered at all — see EmployeeFormOptions in
            // Components/Employees/employees.ts. The options are the server's, so a select can
            // never offer a value `StoreEmployeeRequest` would then refuse.
            'options' => $user !== null && Gate::forUser($user)->allows('create', Employee::class)
                ? $this->formOptions($user)
                : null,
        ]);
    }

    public function store(StoreEmployeeRequest $request): RedirectResponse
    {
        Gate::authorize('create', Employee::class);

        ['employee' => $employee, 'password' => $password] = $this->employees->create(
            $request->user(),
            $request->employeeAttributes(),
        );

        // The generated first-sign-in password travels on its OWN one-shot channel, and not in
        // `success`. There is no email in the MVP (Part H §1) and no password-reset route to send
        // anybody to, so handing the Admin one password IS the delivery mechanism — see
        // StoreEmployeeRequest for that decision, which stands. What changed is the channel:
        //
        //   * `success` is rendered by `FlashMessage.vue` as a persistent, undismissable Alert,
        //     in the same strip and the same words as *"Project archived"*. A credential does not
        //     belong in the channel every routine confirmation uses.
        //   * `FIRST_SIGN_IN` is keyed to the employee it belongs to, read exactly once by
        //     `show()`, and drawn by a component that looks like what it is (a credential handed
        //     over once, with a copy control).
        //
        // `success` still carries the hire itself, without the credential, so the screen confirms
        // what happened even if the panel is never read. The password is not audited, is never
        // stored in plaintext and appears in no resource.
        return redirect()
            ->route('admin.employees.show', $employee)
            ->with(self::FIRST_SIGN_IN, [
                // Keyed, so a credential flashed for one hire cannot be drawn on another
                // employee's page — two Admins hiring in one session would otherwise be one
                // mis-click from reading each other's.
                'employee_id' => $employee->getKey(),
                'name' => $employee->user?->name,
                'email' => $employee->user?->email,
                'password' => $password,
            ])
            ->with('success', sprintf(
                '%s added.',
                $employee->user?->name ?? 'The employee',
            ));
    }

    public function show(Request $request, Employee $employee): Response
    {
        $subject = $this->visible($request, $employee);

        Gate::authorize('view', $subject);

        // The detail-only keys, the way TaskResource gates `available_transitions`: memberships,
        // grants and the deactivation date are three relation reads that a list of thirty rows
        // must not make.
        $request->attributes->set('employee_detail', true);

        $subject->load('projects');
        // Set rather than declared: `user_project_permissions` hangs off the USER (that is what
        // ProjectPolicy reads) and there is no Employee relation for it. `setRelation` gives the
        // resource the same `relationLoaded` contract a declared relation would, without a model
        // change in a phase that adds none.
        $subject->setRelation('projectPermissionGrants', $this->employees->grantsFor($subject));

        $user = $request->user();
        $mayGrant = $user !== null && Gate::forUser($user)->allows('managePermissions', $subject);
        $mayChangeRole = $user !== null && Gate::forUser($user)->allows('changeRole', $subject);
        $mayChangeTracking = $user !== null && Gate::forUser($user)->allows('changeTrackingMode', $subject);

        $firstSignIn = $this->pullFirstSignIn($request, $subject);

        // **Make the promise true.** The panel says the password is not shown again, and without
        // the next two calls that was false: Inertia keeps every page's props in `history.state`,
        // so navigating on and pressing Back restored this response — and the credential with it
        // — without asking the server anything.
        //
        //   * `encryptHistory()` makes this entry's stored props ciphertext under a key held in
        //     `sessionStorage` rather than a readable object in the history entry.
        //   * `clearHistory()` throws that key away, so nothing written before this response can
        //     be decrypted either.
        //
        // The third step is client-side and belongs to the panel: `EmployeeFirstSignInPanel`
        // rolls the key again once it has mounted — which is strictly after Inertia has written
        // this entry — so THIS entry is unreadable too. Back then lands on a history item Inertia
        // cannot restore, which makes it re-ask the server, and the server has already spent the
        // one-shot flash. See the component for the whole sequence.
        //
        // `encryptHistory` is passed on EVERY render rather than switched on for this one,
        // because it is a flag on the Inertia response *factory*, which is a singleton: turned
        // on and left on, a long-lived worker would encrypt every later response too. Handing it
        // the app-wide default makes each response say what it means.
        Inertia::encryptHistory($firstSignIn !== null || (bool) config('inertia.history.encrypt', false));

        if ($firstSignIn !== null) {
            Inertia::clearHistory();
        }

        return Inertia::render('Admin/Employees/Show', [
            'employee' => (new EmployeeResource($subject))->resolve($request),
            // The vocabulary the working week is spelled in, so the record card's summary and the
            // Work Schedule editor's checkboxes read one enum (App\Support\Weekday).
            'weekdays' => $this->weekdays(),
            // Absent means no grant form is drawn. Both lists are the server's: a project or a
            // key that is not in them is one the endpoint would refuse.
            'grantableProjects' => $mayGrant ? $this->grantableProjects($user) : null,
            'grantablePermissions' => $mayGrant ? $this->grantablePermissions() : null,
            // Part D §2's *"role/schedule/tracking_mode"*, two of them writable from here. Null
            // means the select is not drawn at all — the same contract the grant lists keep —
            // and the options are the server's, so a control can never offer a value the Form
            // Request would then refuse. MANAGER is not in `roleOptions()` (Part C §1).
            'roleOptions' => $mayChangeRole ? $this->roleOptions() : null,
            'trackingModeOptions' => $mayChangeTracking ? $this->trackingModeOptions() : null,
            // Phase 11: the person's connected timer extensions, with a revoke per row. Null — no
            // card — unless they are on the remote timer and the requester may revoke, which is
            // the same check the revoke route makes (`EmployeePolicy::deactivate`).
            'extensionDevices' => $subject->tracking_mode === TrackingMode::RemoteTimer
                && $user !== null && Gate::forUser($user)->allows('deactivate', $subject)
                ? $subject->user->devices()->active()->get()
                    ->map(fn (Device $d): array => [
                        'id' => $d->id,
                        'name' => $d->name,
                        'paired_at' => $d->paired_at?->toIso8601String(),
                        'last_seen_at' => $d->last_seen_at?->toIso8601String(),
                    ])
                    ->values()
                : null,
            // Present on exactly one response in the life of an account: the redirect after
            // `store()`. Absent on every ordinary visit, absent on a reload, and absent for a
            // second Admin looking at the same record at the same moment — it is flash data
            // keyed to this employee, read once, and it is the only place this string exists
            // outside the new colleague's own password hash.
            ...($firstSignIn === null ? [] : ['firstSignIn' => $firstSignIn]),
        ]);
    }

    /**
     * Change what somebody may reach across the whole agency.
     *
     * `EmployeeAdministrationService::changeRole()` does the work — it is the one statement of
     * this rule, it audits the change as `role.changed` with old and new values (Part C §4), and
     * its `guard()` refuses a self-change. Nothing is re-tested here: `Gate::authorize` answers
     * the **403** for a request, `findFor()` answers the **404** for a record outside the
     * requester's scope, and the service answers for a caller that is not a request at all.
     *
     * **The target's sessions are deliberately left alone.** A role change is not a departure:
     * `deactivate()` ends sessions because the person is gone, and doing it here would sign
     * somebody out mid-sentence for a promotion. The new permissions apply on their next request
     * — `User::hasPermission()` memoizes per instance and caches nothing between requests — and
     * if the new role belongs to another shell, `EnsureSurface` sends them to it. The
     * confirmation on screen says so in those words.
     */
    public function changeRole(UpdateEmployeeRoleRequest $request, Employee $employee): RedirectResponse
    {
        $subject = $this->visible($request, $employee);

        Gate::authorize('changeRole', $subject);

        $role = $request->role();

        $this->employees->changeRole($request->user(), $subject, $role);

        return back()->with('success', sprintf(
            '%s is now %s. What they can reach changes on their next request; they stay signed in.',
            $subject->user?->name ?? 'That employee',
            $this->roleLabel($role),
        ));
    }

    /**
     * Change how somebody's working time is measured — the field that, until this endpoint, had
     * **no writer anywhere in the application**.
     *
     * The consequence worth stating is that the switch is not a migration: an office-attendance
     * employee's days are rows in `attendance_records` and a remote-timer employee's are rows in
     * `time_entries`, and a remote-timer employee has no attendance row at all (decision 4-11).
     * Nothing here rewrites what is already recorded, so the sentence below says which side of
     * the line the old days stay on, and the confirmation dialog says it at greater length
     * before the Admin presses anything.
     */
    public function changeTrackingMode(
        UpdateEmployeeTrackingModeRequest $request,
        Employee $employee,
    ): RedirectResponse {
        $subject = $this->visible($request, $employee);

        Gate::authorize('changeTrackingMode', $subject);

        $mode = $request->trackingMode();

        $this->employees->changeTrackingMode($request->user(), $subject, $mode);

        return back()->with('success', sprintf(
            '%s is now measured by %s. Days already recorded stay where they were recorded — this changes how the days from here on are counted.',
            $subject->user?->name ?? 'That employee',
            lcfirst($this->trackingModeLabel($mode)),
        ));
    }

    /**
     * Switch a login off. Never a delete (Part B §3 rule 11).
     *
     * The open tasks are stated in the sentence rather than reassigned: spec §47 says *"open tasks
     * flagged for reassignment"*, Part D §21 says *"no auto-reassign"*, and cutting off the access
     * of somebody who has left must not wait on re-homing their work. The count came with the row
     * the confirmation was opened from, so the Admin had it before they pressed the button.
     */
    public function deactivate(Request $request, Employee $employee): RedirectResponse
    {
        $subject = $this->visible($request, $employee);

        Gate::authorize('deactivate', $subject);

        $open = count($this->employees->openTaskIds($subject));

        $this->employees->deactivate($request->user(), $subject);

        return back()->with('success', sprintf(
            '%s can no longer sign in, and their sessions have ended. Their record is kept.%s',
            $subject->user?->name ?? 'That employee',
            $open === 0 ? '' : sprintf(
                ' %d open %s still assigned to them — hand %s to somebody else.',
                $open,
                $open === 1 ? 'task is' : 'tasks are',
                $open === 1 ? 'it' : 'them',
            ),
        ));
    }

    /**
     * The inverse. It does not restore the sessions the deactivation ended — see
     * `EmployeeAdministrationService::reactivate()`.
     */
    public function reactivate(Request $request, Employee $employee): RedirectResponse
    {
        $subject = $this->visible($request, $employee);

        Gate::authorize('reactivate', $subject);

        $this->employees->reactivate($request->user(), $subject);

        return back()->with('success', sprintf(
            '%s can sign in again, with the role and the working week they had. They will need to sign in from scratch.',
            $subject->user?->name ?? 'That employee',
        ));
    }

    /**
     * Re-issue somebody's sign-in password.
     *
     * The same one-shot channel `store()` uses, for the same reason and with the same
     * protections — this is the second and last place in the application that hands a working
     * credential to a human being, and both of them do it identically.
     *
     * It exists because without it an account could become permanently unusable: there is no
     * email (Part H §1) and no reset route, Profile → Password needs the current password, and
     * so a forgotten one left a person whose record must be kept forever (Part B §3 rule 11)
     * and whose login nobody in the agency could ever repair. `EmployeeAdministrationService::
     * resetPassword()` says the rest, including why it ends their sessions.
     */
    public function resetPassword(Request $request, Employee $employee): RedirectResponse
    {
        $subject = $this->visible($request, $employee);

        Gate::authorize('resetPassword', $subject);

        $password = $this->employees->resetPassword($request->user(), $subject);

        return back()
            ->with(self::FIRST_SIGN_IN, [
                'employee_id' => $subject->getKey(),
                'name' => $subject->user?->name,
                'email' => $subject->user?->email,
                'password' => $password,
                // The panel says a different sentence for a reset than for a hire: the
                // consequence an Admin needs to know here is that the person's existing
                // sessions have just ended, which is not true of a new hire who has none.
                'reissued' => true,
            ])
            ->with('success', sprintf(
                '%s has a new sign-in password, and their existing sessions have ended.',
                $subject->user?->name ?? 'That employee',
            ));
    }

    /**
     * The employee, or 404 — the scope is a query, so absence is what the controller HAS rather
     * than what it decides (Part C).
     */
    private function visible(Request $request, Employee $employee): Employee
    {
        $visible = $this->employees->findFor($request->user(), $employee);

        if ($visible === null) {
            throw new NotFoundHttpException;
        }

        return $visible;
    }

    /**
     * `?status=` and `?search=`, echoed back so the chip bar survives a reload.
     *
     * Bounded rather than free, like `HolidayController::year()`: an unrecognised status becomes
     * `all` instead of an error, because a stale link asks a question that no longer exists and
     * the honest answer is the unnarrowed list.
     *
     * @return array{status: string, search: ?string}
     */
    private function filters(Request $request): array
    {
        $status = (string) $request->query('status', 'all');
        $search = trim((string) $request->query('search', ''));

        return [
            'status' => UserStatus::tryFrom($status)?->value ?? 'all',
            'search' => $search === '' ? null : $search,
        ];
    }

    /**
     * The generated first-sign-in password, read **once**, or null.
     *
     * Flash data, so it is gone from the session the moment this request ends whether it was read
     * or not; `pull()` takes it out before that anyway, so two tabs cannot both render it. The id
     * check is what stops a credential flashed for one hire being drawn on another employee's
     * page: a mismatch is dropped and nothing is shown, because a password beside the wrong name
     * is worse than no password at all.
     *
     * @return array{name: ?string, email: ?string, password: string}|null
     */
    private function pullFirstSignIn(Request $request, Employee $employee): ?array
    {
        if (! $request->hasSession()) {
            return null;
        }

        $flashed = $request->session()->pull(self::FIRST_SIGN_IN);

        if (! is_array($flashed) || ! is_string($flashed['password'] ?? null)) {
            return null;
        }

        if (($flashed['employee_id'] ?? null) !== $employee->getKey()) {
            return null;
        }

        return [
            'name' => is_string($flashed['name'] ?? null) ? $flashed['name'] : null,
            'email' => is_string($flashed['email'] ?? null) ? $flashed['email'] : null,
            'password' => $flashed['password'],
            // A hire's first password and a re-issued one are handed over identically and need
            // the same care; they differ in one consequence, and the panel says whichever is
            // true. A reset has just signed that person out everywhere — somebody who is not
            // told that hears "it stopped working" an hour later and does not connect the two.
            'reissued' => ($flashed['reissued'] ?? false) === true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(User $user): array
    {
        return [
            'roles' => $this->roleOptions(),

            'tracking_modes' => $this->trackingModeOptions(),

            'employment_types' => array_map(fn (EmploymentType $type): array => [
                'value' => $type->value,
                'label' => $type->label(),
            ], EmploymentType::cases()),

            // Who a new hire may report to. Every active employee in the requester's own scope,
            // named by their user record — `manager_id` is what the two 🟡 *own team* scopes in
            // Employee read, and until this screen existed nothing in the application wrote it.
            'managers' => $this->employees->assignableManagers($user)
                ->map(fn (Employee $employee): array => [
                    'id' => $employee->getKey(),
                    'name' => $employee->user?->name,
                ])
                ->values()
                ->all(),

            'weekdays' => $this->weekdays(),
        ];
    }

    /**
     * The roles this screen may assign, with their words — the create form's select and the
     * detail's role control read the same list, so the two can never offer different sets.
     *
     * MANAGER is absent, from `EmployeeAdministrationService::ASSIGNABLE_ROLES` (Part C §1:
     * assigned to nobody in the MVP), which is also what both Form Requests validate against.
     *
     * @return list<array{value: string, label: string}>
     */
    private function roleOptions(): array
    {
        return array_map(fn (RoleName $role): array => [
            'value' => $role->value,
            'label' => $this->roleLabel($role),
        ], EmployeeAdministrationService::ASSIGNABLE_ROLES);
    }

    /**
     * `REMOTE_EMPLOYEE` → `Remote employee`: one word per meaning, sentence case, which is what
     * every other select on this surface reads like.
     */
    private function roleLabel(RoleName $role): string
    {
        return ucfirst(strtolower(str_replace('_', ' ', $role->value)));
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function trackingModeOptions(): array
    {
        return array_map(fn (TrackingMode $mode): array => [
            'value' => $mode->value,
            'label' => $this->trackingModeLabel($mode),
        ], TrackingMode::cases());
    }

    /**
     * *Not tracked* rather than *None*, because "None" reads as *no answer* where the answer is
     * *nobody measures this person's time* — which is the Accountant's real state, not a gap in
     * their record.
     */
    private function trackingModeLabel(TrackingMode $mode): string
    {
        return match ($mode) {
            TrackingMode::RemoteTimer => 'Remote timer',
            TrackingMode::OfficeAttendance => 'Office attendance',
            TrackingMode::None => 'Not tracked',
        };
    }

    /**
     * @return list<array{value: string, label: string, short: string}>
     */
    private function weekdays(): array
    {
        return array_map(fn (Weekday $day): array => [
            'value' => $day->value,
            'label' => $day->label(),
            'short' => $day->shortLabel(),
        ], Weekday::cases());
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function grantableProjects(User $user): array
    {
        return $this->employees->grantableProjects($user)
            ->map(fn (Project $project): array => ['id' => $project->getKey(), 'name' => $project->name])
            ->values()
            ->all();
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function grantablePermissions(): array
    {
        return array_map(fn (Permission $permission): array => [
            'value' => $permission->value,
            'label' => ucfirst(strtolower(str_replace(['.', '_'], ' ', $permission->value))),
        ], EmployeeAdministrationService::GRANTABLE_PERMISSIONS);
    }
}
