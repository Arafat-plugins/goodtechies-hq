<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Services\WorkloadService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin → Workforce → Workload (master prompt Part D §7, Phase 4).
 *
 * *Task count per employee, overdue per employee, estimated vs tracked, projects with most
 * pending work — **counts only**.* An Admin opens it when deciding who can take the next job.
 *
 * Thin, like every controller here: one gate, one service call, one render. Every rule — what
 * "open" and "overdue" mean, whose tasks get counted, what may and may not be put beside a
 * person's name — is `WorkloadService`'s, and the counts themselves are `TaskService`'s, so
 * this screen and `/admin/tasks` under the same filter answer with the same number.
 *
 * ## No score, and the ordering is part of that
 *
 * Part H forbids productivity scoring. People are listed **by name**; estimated and tracked are
 * two numbers side by side with no ratio between them; nothing is tinted by how a total
 * compares with an estimate. `tests/Feature/Workforce/TimesheetNoScoreTest.php` greps this
 * file, the service and the rendered payload for "score", "productivity", "efficiency",
 * "rating" and "ranking".
 *
 * ## 403 and 404
 *
 * Behind `surface:admin` and `can:tasks.view`, so every other role is refused before a query
 * runs — 403, about who is asking. There is no record parameter, so there is no 404 to have:
 * an employee outside the viewer's scope is simply not in the list, which is the absence rule
 * stated as a scope (`Employee::attendanceVisibleTo()`).
 */
class WorkloadController extends Controller
{
    public function __construct(private readonly WorkloadService $workload) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Task::class);

        $user = $request->user();
        abort_if($user === null, 403);

        return Inertia::render('Admin/Workload/Index', $this->workload->forViewer($user, Carbon::today()));
    }
}
