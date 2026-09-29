<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Services\TaskService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The old address of an Admin's own plate.
 *
 * My Tasks, Due Today and Overdue are now the scope dropdown on the Tasks toolbar
 * (`/admin/tasks?scope=…`), so this route only translates: it keeps its name, so every link
 * builder that points here still resolves, and it 302s to the same set of tasks on the Tasks
 * List. The translation is TaskService::myTasksRedirectQuery(); nothing here builds a query.
 */
class MyTaskController extends Controller
{
    public function __construct(
        private readonly TaskService $tasks,
    ) {}

    public function index(Request $request): RedirectResponse
    {
        // The same gate /admin/tasks runs, so a refusal stays a 403 here rather than a hop.
        Gate::authorize('viewAny', Task::class);

        return redirect()->route('admin.tasks.index', $this->tasks->myTasksRedirectQuery($request->query()));
    }
}
