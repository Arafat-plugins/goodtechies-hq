<?php

namespace App\Http\Controllers\Employee;

use App\Http\Controllers\Concerns\BuildsMyTasksPayload;
use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Services\TaskService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The employee's own plate — the screen they open first thing to see what is due today.
 *
 * On this surface `Task::visibleTo()` has already narrowed to the tasks they are assigned to,
 * so the `mine` filter the payload applies is a no-op for an Employee and a real narrowing for
 * the Manager who shares this surface: a Manager sees every task on /employee/tasks and their
 * own seven buckets here. That is the same code answering both, which is why the two cannot
 * drift apart.
 *
 * Thin by design; every query is TaskService's.
 */
class MyTaskController extends Controller
{
    use BuildsMyTasksPayload;

    /** Where this page lives — the buckets link back to it with their own `?bucket=`. */
    private const BASE = '/employee/my-tasks';

    public function __construct(
        private readonly TaskService $tasks,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Task::class);

        return Inertia::render('Employee/MyTasks', [
            ...$this->myTasksPayload($request, self::BASE),
        ]);
    }
}
