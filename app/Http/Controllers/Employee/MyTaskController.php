<?php

namespace App\Http\Controllers\Employee;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Services\TaskService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The old address of the employee's own plate.
 *
 * My Tasks, Due Today and Overdue are now the scope dropdown on the Tasks toolbar
 * (`/employee/tasks?scope=…`). Dashboard cards and reports still deep-link here, so the route
 * keeps its name and 302s to the same set of tasks on the Tasks List — Task::visibleTo() on
 * that page is unchanged, so a redirect cannot widen what anybody sees.
 */
class MyTaskController extends Controller
{
    public function __construct(
        private readonly TaskService $tasks,
    ) {}

    public function index(Request $request): RedirectResponse
    {
        // The same gate /employee/tasks runs, so a refusal stays a 403 here rather than a hop.
        Gate::authorize('viewAny', Task::class);

        return redirect()->route('employee.tasks.index', $this->tasks->myTasksRedirectQuery($request->query()));
    }
}
