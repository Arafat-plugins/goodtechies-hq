<?php

namespace App\Http\Controllers\Employee;

use App\Http\Controllers\Controller;
use App\Services\ReportService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Employee → My Reports (master prompt Part D §15, report contract §5).
 *
 * *My Tasks, Completed, Pending, Overdue as linked counts, a Time section present only for
 * timer roles, and a weekly or monthly work summary picked with `?period=week|month`.*
 *
 * ## It is self-scoped, and there is nothing to pass
 *
 * This is not the admin report with an employee filter pre-applied. There is **no employee
 * parameter on this route and none in the payload's queries**: every count is
 * `TaskService` with `mine => true`, which is "assigned to the signed-in person". So the answer
 * to "can this screen show somebody else's numbers" is not "the scope prevents it" but "there
 * is no way to ask" — which is the stronger of the two, and the one the suite asserts.
 *
 * A Manager reaches this surface and sees every task on `/employee/tasks`; here they see their
 * own plate, for the same reason an Admin does on `/admin/my-tasks`. The narrowing is the
 * `mine` filter, not the surface.
 *
 * ## `?period=` is not validated, it is decided
 *
 * Anything that is not `month` is `week`. That is a total function rather than a rule, so
 * there is nothing for a Form Request to refuse: a stale link asking for a period that never
 * existed gets this week, which is the useful answer, and the frozen `ReportRequest` keeps the
 * five keys the contract gave it.
 */
class ReportController extends Controller
{
    public function __construct(private readonly ReportService $reports) {}

    public function __invoke(Request $request): Response
    {
        $viewer = $request->user();
        abort_if($viewer === null, 403);

        return Inertia::render(
            'Employee/Reports',
            $this->reports->forEmployee($viewer, (string) $request->query('period', ReportService::PERIOD_WEEK)),
        );
    }
}
