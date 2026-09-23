<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Recurring\PreviewRecurrenceRequest;
use App\Http\Requests\Recurring\StoreRecurringTaskRequest;
use App\Http\Requests\Recurring\UpdateRecurringTaskRequest;
use App\Http\Resources\RecurringGenerationLogResource;
use App\Http\Resources\RecurringTaskResource;
use App\Models\Project;
use App\Models\RecurringTask;
use App\Services\RecurringTaskEngine;
use App\Services\RecurringTaskService;
use App\Support\RecurrenceRule;
use App\Support\RecurrenceSummary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The Recurring tab on admin project detail (master prompt Phase 3, "Screens (Admin)").
 *
 * ## The shape, which is Phase 2's shape
 *
 * Reads a panel fetches are **JSON** (`index`, `log`, `preview`); writes are **Inertia `back()`
 * with a flash** (`store`, `update`, `generate`). The tab is a panel inside
 * `Pages/Admin/Projects/Show.vue`, which is served by `ProjectController@show` — it has no page
 * of its own and therefore no Inertia render here.
 *
 * ## 403 and 404
 *
 * Both, and they mean different things:
 *
 *   - **403** — the wrong surface or the wrong role. Every route here is behind
 *     `surface:admin`, so a Manager, an employee and the Accountant are refused there; and
 *     `RecurringTaskPolicy` refuses the same people again, because a retainer template is an
 *     Admin object whichever shell the request arrived in.
 *   - **404** — a record the requester may not see. Every lookup of a template goes through
 *     `RecurringTask::visibleTo()`, so a template on a project outside their scope is ABSENT
 *     rather than forbidden (Part C). On this surface only Admins get past the middleware and
 *     an Admin sees every project, so in practice the 404 is reached by an unknown id — but the
 *     rule is coded rather than argued, because the day somebody widens the surface is not the
 *     day to discover it was only true by accident.
 *
 * ## It does not generate anything
 *
 * "Generate now" calls `RecurringTaskEngine::generate($template, $asOf, force: true)`, which is
 * the entry point the engine left for it. `force` skips `due()` and nothing else: the project's
 * state, the existing-instance check and the unique index all still apply, and the task is still
 * created through `TaskService::create()`. There is no second creation path in this file, and
 * there is no second copy of the period key, the next-run date or the stop reason either — all
 * three are asked of `RecurrenceRule` and the engine.
 */
class RecurringTaskController extends Controller
{
    /** How many attempts the log panel shows. A year of a monthly retainer, and then some. */
    private const LOG_LIMIT = 50;

    public function __construct(
        private readonly RecurringTaskService $templates,
        private readonly RecurringTaskEngine $engine,
    ) {}

    /**
     * A project's templates. JSON — the tab fetches it on open and re-reads it after a write.
     */
    public function index(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('viewAny', [RecurringTask::class, $project]);

        return response()->json([
            'templates' => RecurringTaskResource::collection(
                $this->templates->forProject($project),
            )->toArray($request),
        ]);
    }

    public function store(StoreRecurringTaskRequest $request, Project $project): RedirectResponse
    {
        Gate::authorize('create', [RecurringTask::class, $project]);

        $this->templates->create(
            $request->user(),
            $project,
            $request->templateAttributes(),
            $request->recurrenceRule(),
        );

        return back()->with('success', 'Recurring task set up.');
    }

    public function update(UpdateRecurringTaskRequest $request, RecurringTask $recurringTask): RedirectResponse
    {
        $template = $this->visible($request, $recurringTask);

        Gate::authorize('update', $template);

        $this->templates->update(
            $request->user(),
            $template,
            $request->templateAttributes(),
            $request->recurrenceRule(),
        );

        return back()->with('success', 'Recurring task updated.');
    }

    /**
     * "Generate now".
     *
     * The flash is the LOG ROW's own sentence, not a sentence written here — pressing the button
     * twice says *"An instance for October 2026 already exists (task #41)."* because that is
     * what the engine wrote down, and the row the reader then finds in the log below says the
     * same words. A skip is flashed as an `error` so that it reads as the refusal it is; it is
     * not a failed request, and the attempt is recorded either way.
     */
    public function generate(Request $request, RecurringTask $recurringTask): RedirectResponse
    {
        $template = $this->visible($request, $recurringTask);

        Gate::authorize('generate', $template);

        $log = $this->engine->generate($template, Carbon::today(), force: true);

        $sentence = (new RecurringGenerationLogResource($log))->resolve($request)['sentence'];

        return back()->with($log->isWarning() ? 'error' : 'success', $sentence);
    }

    /**
     * One template's generation log. JSON, like the index — it is what the log panel fetches
     * when somebody opens it, and it is not part of the template payload because a screen
     * printing three rows must not drag a year of history along to do it.
     */
    public function log(Request $request, RecurringTask $recurringTask): JsonResponse
    {
        $template = $this->visible($request, $recurringTask);

        Gate::authorize('view', $template);

        $entries = $template->log()
            ->with(['task', 'previousOpenTask'])
            ->limit(self::LOG_LIMIT)
            ->get();

        return response()->json([
            'entries' => RecurringGenerationLogResource::collection($entries)->toArray($request),
        ]);
    }

    /**
     * The next-run preview for a rule that has not been saved.
     *
     * Every value in the answer comes from `RecurrenceRule`: the date it next fires, the period
     * that run belongs to, the key that period is stored under, and when the instance it makes
     * would be due. The editor prints them. It computes none of them, which is the entire reason
     * this endpoint exists rather than a date library in Vue.
     */
    public function preview(PreviewRecurrenceRequest $request, Project $project): JsonResponse
    {
        Gate::authorize('create', [RecurringTask::class, $project]);

        $rule = $request->recurrenceRule();
        $asOf = Carbon::today();

        $at = $rule->nextRunAt($asOf);
        $periodStart = $rule->periodStart($at);
        $period = $rule->periodKey($at);

        return response()->json([
            'preview' => [
                'at' => $at->toDateString(),
                'period' => $period,
                'period_label' => RecurrenceRule::labelForPeriod($period),
                'period_start' => $periodStart->toDateString(),
                'period_end' => $rule->periodEnd($periodStart)->toDateString(),
                'due_date' => $rule->dueDate($periodStart)->toDateString(),
                'summary' => RecurrenceSummary::for($rule),
            ],
        ]);
    }

    /**
     * The template, or 404.
     *
     * Route-model binding has already found the row; this re-asks the question Part C cares
     * about — may this requester SEE it — and answers absence with absence. It is the same
     * `Project::visibleTo()` the projects list is scoped by, reached through the template's
     * project, so the tab and the project it hangs off cannot disagree about what exists.
     */
    private function visible(Request $request, RecurringTask $template): RecurringTask
    {
        $visible = RecurringTask::query()
            ->visibleTo($request->user())
            ->with(['project', 'defaultAssignee.user'])
            ->whereKey($template->getKey())
            ->first();

        if ($visible === null) {
            throw new NotFoundHttpException;
        }

        return $visible;
    }
}
