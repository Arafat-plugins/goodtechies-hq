<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\BuildsMyTasksPayload;
use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Services\TaskService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * An Admin's own plate.
 *
 * The plan asks for My Tasks "for every role incl. Admins", and this is why it is a screen of
 * its own rather than `/admin/tasks?assignee_id=me`: an Admin runs the agency from the Tasks
 * List and does their own work from here, and the two questions want different furniture. The
 * List has a chip bar and a group-by toggle because you are looking for something; this has
 * seven counts because you want to know what is waiting on you before you open anything.
 *
 * Thin by design. Every count and every row is TaskService's answer, scoped by
 * Task::visibleTo() and narrowed by the `mine` filter; nothing here builds a query.
 */
class MyTaskController extends Controller
{
    use BuildsMyTasksPayload;

    /** Where this page lives — the buckets link back to it with their own `?bucket=`. */
    private const BASE = '/admin/my-tasks';

    public function __construct(
        private readonly TaskService $tasks,
    ) {}

    public function index(Request $request): Response
    {
        // The same gate /admin/tasks runs. A person who may not view tasks has no plate.
        Gate::authorize('viewAny', Task::class);

        return Inertia::render('Admin/MyTasks', [
            ...$this->myTasksPayload($request, self::BASE),
        ]);
    }
}
