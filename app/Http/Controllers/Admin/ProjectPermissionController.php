<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Employees\GrantProjectPermissionRequest;
use App\Models\Employee;
use App\Models\Project;
use App\Models\UserProjectPermission;
use App\Services\EmployeeAdministrationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Project-level permission grants (master prompt Part C §1: *"Project-level overrides
 * (`user_project_permissions`) layer on top — e.g. a MANAGER may be granted
 * `projects.view_finance` for one project"*).
 *
 * The table has existed since Phase 0 and `ProjectPolicy::viewFinance()` has READ it since Phase
 * 1 — **nothing wrote it**, so the override in Part C §1 was a rule the application enforced and
 * nobody could exercise. These two endpoints are the writer, and they are the minimum: grant one
 * key on one project, take it away again, both audited as `permission.changed` with old and new
 * values (Part C §4 names the event).
 *
 * ## Three separate refusals, in this order
 *
 * 1. The **routes** are 403 for every other shell (`surface:admin`) and for any role without
 *    `roles.manage` (`can:roles.manage` on the group).
 * 2. The **employee** and the **project** are re-resolved through their own scopes before any
 *    policy is asked, so one the requester may not see is **404** and never a refusal that would
 *    confirm the record exists (Part C).
 * 3. `EmployeePolicy::managePermissions` then refuses a **self-grant** — the privilege escalation
 *    Part C §1's *"(not own account)"* exists to stop — and
 *    `EmployeeAdministrationService::guard()` refuses the call as well.
 *
 * A grant hangs off the employee in the URL rather than off the project, because the screen it
 * belongs to is that person's record and the row it writes already knows which project it is on.
 * Revoking names the grant's own id, so two Admins clearing the same list cannot take each
 * other's row: the second gets a 404 for a grant that is no longer there.
 */
class ProjectPermissionController extends Controller
{
    public function __construct(private readonly EmployeeAdministrationService $employees) {}

    public function store(GrantProjectPermissionRequest $request, Employee $employee): RedirectResponse
    {
        $subject = $this->visible($request, $employee);

        Gate::authorize('managePermissions', $subject);

        $project = $this->visibleProject($request, $request->projectId());
        $permission = $request->permission();

        $this->employees->grantProjectPermission($request->user(), $subject, $project, $permission);

        return back()->with('success', sprintf(
            '%s may now use %s on %s.',
            $subject->user?->name ?? 'That employee',
            $permission->value,
            $project->name,
        ));
    }

    public function destroy(Request $request, Employee $employee, UserProjectPermission $grant): RedirectResponse
    {
        $subject = $this->visible($request, $employee);

        Gate::authorize('managePermissions', $subject);

        // A grant that belongs to somebody else is not this employee's to revoke, and saying so
        // would confirm it exists — so it is the same 404 as a grant that was never there.
        if ($grant->user_id !== $subject->user_id) {
            throw new NotFoundHttpException;
        }

        $name = $grant->permission?->key?->value ?? 'that permission';
        $project = $grant->project?->name ?? 'that project';

        $this->employees->revokeProjectPermission($request->user(), $subject, $grant);

        return back()->with('success', sprintf(
            '%s no longer has %s on %s.',
            $subject->user?->name ?? 'That employee',
            $name,
            $project,
        ));
    }

    /**
     * The employee, or 404 — the same scope the list and the detail are narrowed by.
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
     * The project, or 404 — `Project::visibleTo()`, and not archived, which is the same set
     * `grantableProjects()` offered. A grant on a project the requester cannot see would be a
     * privilege handed out over a record they are not allowed to know about.
     */
    private function visibleProject(Request $request, int $projectId): Project
    {
        $user = $request->user();

        $project = $user === null ? null : Project::query()
            ->visibleTo($user)
            ->whereNull('archived_at')
            ->whereKey($projectId)
            ->first();

        if ($project === null) {
            throw new NotFoundHttpException;
        }

        return $project;
    }
}
