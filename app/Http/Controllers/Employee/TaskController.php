<?php

namespace App\Http\Controllers\Employee;

use App\Exceptions\TaskStateException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Task\ChangeTaskStatusRequest;
use App\Http\Requests\Task\HandOffTaskRequest;
use App\Http\Requests\Task\ReorderTaskRequest;
use App\Http\Requests\Task\StoreChecklistItemRequest;
use App\Http\Requests\Task\StoreTaskLinkRequest;
use App\Http\Requests\Task\UpdateChecklistItemRequest;
use App\Http\Requests\Task\UpdateTaskRequest;
use App\Http\Requests\Task\ViewTaskCalendarRequest;
use App\Http\Resources\TaskResource;
use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\Project;
use App\Models\Tag;
use App\Models\Task;
use App\Models\TaskChecklistItem;
use App\Models\TaskLink;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\TaskService;
use App\Support\TaskPriority;
use App\Support\TaskStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The employee's own tasks: the List, Board and Calendar views, the detail page, and the
 * writes doing the work involves.
 *
 * A task they are not ASSIGNED to does not exist as far as this surface is concerned — not
 * even one on a project they are a member of. It is absent from the list and answers 404 by id,
 * never 403 (master prompt Part B §3 rule 1).
 *
 * The Manager lives on this surface too — `surface:employee`, not `surface:admin` — so the
 * routes a Manager needs are here, refused to an Employee by TaskPolicy rather than by being
 * absent: delete and archive (ADMIN/MANAGER in the plan), the review verdicts through the same
 * status endpoint everyone else uses, and the hand-off. Unarchive is not here, because it is
 * Admin-only and an Admin reaches the Admin surface.
 *
 * What an employee may write is the WORK — the description, the work summary, the estimate, the
 * checklist, the links, and moving their own card along the board as far as In review. What
 * they may not write is the PLAN: the dates, the priority, the title, the project. That split
 * lives in UpdateTaskRequest, which refuses the plan fields to anyone who is not a manager, and
 * it is the same rule as "date changes by drag are Admin/Manager only" seen from the form.
 *
 * Group-by-project is offered and group-by-assignee is not: every task here is already theirs,
 * so grouping by assignee would produce exactly one group — and for the same reason the
 * assignee picker's option list is not sent to this surface at all.
 */
class TaskController extends Controller
{
    /** The variants that mean anything on a list that is one person's work. */
    private const GROUP_BY = ['status', 'project', 'priority'];

    /** How far back a task's timeline is shown on the detail page. */
    private const ACTIVITY_LIMIT = 30;

    /** What the detail page needs on top of the list's relations. */
    private const DETAIL_RELATIONS = [
        'checklistItems.completer',
        'links',
        'dependencies',
        'dependents',
        'creator',
        'completer',
        'firstCompleter',
        'workSummaryAuthor',
    ];

    public function __construct(
        private readonly TaskService $tasks,
        private readonly ActivityLogger $activity,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Task::class);

        $filters = $this->tasks->filters($request->query());
        $groupBy = (string) $request->query('group_by', 'status');
        $groupBy = in_array($groupBy, self::GROUP_BY, true) ? $groupBy : 'status';

        $grouped = $this->tasks->grouped($request->user(), $filters, $groupBy);

        return Inertia::render('Employee/Tasks/Index', [
            'tasks' => $this->present($grouped, $request),
            'filters' => $this->presentFilters($filters),
            'groupByOptions' => self::GROUP_BY,
            'statuses' => $this->options(TaskStatus::boardOrder()),
            'priorities' => $this->options(TaskPriority::cases()),
            'projects' => $this->projects($request),
            'tags' => $this->filterTags($request),
        ]);
    }

    /**
     * The Board, scoped the way everything on this surface is scoped: the columns hold the
     * tasks this employee is assigned to and nothing else, because the query starts at
     * Task::visibleTo(). A Manager on the same route sees every card.
     *
     * A route of its own rather than `?view=board`, for the same reasons as on the Admin
     * surface: deep-linkable, its own row in the permission matrix, and the page name follows
     * the route. Filters travel as query parameters so switching views keeps them.
     */
    public function board(Request $request): Response
    {
        Gate::authorize('viewAny', Task::class);

        $filters = $this->tasks->filters($request->query());

        return Inertia::render('Employee/Tasks/Board', [
            'board' => $this->presentBoard($this->tasks->board($request->user(), $filters), $request),
            'filters' => $this->presentFilters($filters),
            // Which columns this ROLE may drag between — TO DO ↔ IN PROGRESS ↔ WAITING and
            // into IN REVIEW for an employee, with Completed absent because a review verdict
            // is a manager's. The per-task half is still checked on every drop.
            'transitions' => $this->tasks->transitionsFor($request->user()),
            'statuses' => $this->options(TaskStatus::boardOrder()),
            'priorities' => $this->options(TaskPriority::cases()),
            'projects' => $this->projects($request),
            'tags' => $this->filterTags($request),
        ]);
    }

    /**
     * The Calendar, over the same scoped query.
     */
    public function calendar(ViewTaskCalendarRequest $request): Response
    {
        Gate::authorize('viewAny', Task::class);

        $filters = $this->tasks->filters($request->query());

        return Inertia::render('Employee/Tasks/Calendar', [
            'calendar' => $this->presentCalendar($this->tasks->calendar($request->user(), $filters), $request),
            'filters' => $this->presentFilters($filters),
            // False for an employee, true for the Manager who shares this surface. It is the
            // same answer UpdateTaskRequest gives when it makes the dates prohibited, so the
            // disabled handles and the refused write cannot disagree.
            'can_plan' => TaskService::mayPlan($request->user()),
            'statuses' => $this->options(TaskStatus::boardOrder()),
            'priorities' => $this->options(TaskPriority::cases()),
            'projects' => $this->projects($request),
            'tags' => $this->filterTags($request),
        ]);
    }

    public function show(Request $request, Task $task): Response
    {
        $task = $this->visible($request, $task);

        $request->attributes->set('task_detail', true);

        return Inertia::render('Employee/Tasks/Show', [
            'task' => (new TaskResource($task))->resolve($request),
            'activity' => $this->activityFor($task),
            'reviewers' => $this->reviewers($task),
            // The tag picker's options. Tags are the one picker this surface does get: the plan
            // says employees assign existing tags, and `PUT /employee/tasks/{task}` takes
            // `tag_ids` from them — so withholding the list would leave a write they are
            // allowed to make with nothing to make it from. It is this project's tags plus the
            // global ones, which is both what `tag_ids` accepts and all this employee has any
            // business seeing the names of.
            //
            // No `projects` list, unlike the Admin page: `project_id` is `prohibited` here for
            // everybody but a manager, and a picker offering a move the request refuses is a
            // control that exists to be told no.
            'tags' => $this->tagsFor($task->project),
        ]);
    }

    public function update(UpdateTaskRequest $request, Task $task): RedirectResponse
    {
        return $this->run($request, $task, fn (Task $task) => $this->tasks->update(
            $request->user(),
            $task,
            $request->validated(),
        ), 'Task updated.');
    }

    /**
     * The same endpoint the Admin surface has, taking the same request, calling the same
     * service method. An employee reaches In review through it — which is why the work summary
     * is required there — and never Completed: TaskStatus::mayRoleTransition() keeps the review
     * verdicts to the managers and TaskPolicy::review() keeps them to this project's reviewer.
     */
    public function status(ChangeTaskStatusRequest $request, Task $task): RedirectResponse
    {
        return $this->run($request, $task, fn (Task $task) => $this->tasks->transition(
            $request->user(),
            $task,
            $request->status(),
            $request->workSummary(),
            $request->reason(),
            $this->sibling($request->afterId()),
        ), 'Task moved to '.$request->status()->label().'.');
    }

    public function reorder(ReorderTaskRequest $request, Task $task): RedirectResponse
    {
        return $this->run($request, $task, fn (Task $task) => $this->tasks->reorder(
            $request->user(),
            $task,
            $this->sibling($request->afterId()),
        ), 'Task reordered.');
    }

    /**
     * Handing your own work over, which is what makes somebody else's work summary able to
     * complete the task. The current primary may do it; so may a manager.
     */
    public function handOff(HandOffTaskRequest $request, Task $task): RedirectResponse
    {
        $employee = Employee::query()->findOrFail($request->employeeId());

        return $this->run($request, $task, fn (Task $task) => $this->tasks->handOff(
            $request->user(),
            $task,
            $employee,
            $request->reason(),
        ), 'Task handed over.');
    }

    public function storeChecklistItem(StoreChecklistItemRequest $request, Task $task): RedirectResponse
    {
        return $this->run($request, $task, fn (Task $task) => $this->tasks->addChecklistItem(
            $request->user(),
            $task,
            $request->title(),
        ), 'Checklist item added.');
    }

    public function updateChecklistItem(
        UpdateChecklistItemRequest $request,
        Task $task,
        TaskChecklistItem $item,
    ): RedirectResponse {
        $this->belongsTo($item->task_id, $task);

        return $this->run($request, $task, fn () => $this->tasks->updateChecklistItem(
            $request->user(),
            $item,
            $request->changes(),
        ), 'Checklist updated.');
    }

    public function destroyChecklistItem(Request $request, Task $task, TaskChecklistItem $item): RedirectResponse
    {
        $this->belongsTo($item->task_id, $task);

        return $this->run($request, $task, fn () => $this->tasks->removeChecklistItem(
            $request->user(),
            $item,
        ), 'Checklist item removed.');
    }

    public function storeLink(StoreTaskLinkRequest $request, Task $task): RedirectResponse
    {
        return $this->run($request, $task, fn (Task $task) => $this->tasks->addLink(
            $request->user(),
            $task,
            $request->url(),
            $request->label(),
        ), 'Link added.');
    }

    public function destroyLink(Request $request, Task $task, TaskLink $link): RedirectResponse
    {
        $this->belongsTo($link->task_id, $task);

        return $this->run($request, $task, fn () => $this->tasks->removeLink(
            $request->user(),
            $link,
        ), 'Link removed.');
    }

    /**
     * Archiving is a Manager's move on this surface; TaskPolicy refuses an employee.
     */
    public function archive(Request $request, Task $task): RedirectResponse
    {
        return $this->run($request, $task, fn (Task $task) => $this->tasks->archive(
            $request->user(),
            $task,
        ), 'Task archived.');
    }

    /**
     * Delete is ADMIN/MANAGER in the plan, and a Manager only ever sees this surface — so the
     * route is here, and TaskPolicy::delete() is what makes it a 403 for everybody else.
     */
    public function destroy(Request $request, Task $task): RedirectResponse
    {
        $task = $this->visible($request, $task);

        $this->tasks->delete($request->user(), $task);

        return redirect()
            ->route('employee.tasks.index')
            ->with('success', 'Task deleted.');
    }

    /*
    |--------------------------------------------------------------------------
    | Plumbing
    |--------------------------------------------------------------------------
    */

    /**
     * @param  callable(Task): mixed  $write
     */
    private function run(Request $request, Task $task, callable $write, string $success): RedirectResponse
    {
        $task = $this->visible($request, $task);

        try {
            $write($task);
        } catch (TaskStateException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', $success);
    }

    /**
     * A task this employee is not assigned to is absent, not refused: 404, never 403.
     */
    private function visible(Request $request, Task $task): Task
    {
        return Task::query()
            ->visibleTo($request->user())
            ->with([...TaskService::RELATIONS, ...self::DETAIL_RELATIONS])
            ->withCount([
                'checklistItems',
                'checklistItems as checklist_items_done_count' => fn ($query) => $query->where('is_done', true),
            ])
            ->whereKey($task->getKey())
            ->firstOrFail();
    }

    private function belongsTo(?int $taskId, Task $task): void
    {
        abort_unless((int) $taskId === (int) $task->getKey(), 404);
    }

    private function sibling(?int $id): ?Task
    {
        return $id === null ? null : Task::query()->find($id);
    }

    /**
     * @param  array<string, mixed>  $grouped
     * @return array<string, mixed>
     */
    private function present(array $grouped, Request $request): array
    {
        return [
            ...$grouped,
            'groups' => array_map(fn (array $group): array => [
                ...$group,
                'tasks' => TaskResource::collection($group['tasks'])->toArray($request),
            ], $grouped['groups']),
        ];
    }

    /**
     * The Board's payload: the status groups, named columns, each card through TaskResource —
     * which composes ProjectResource and so decides per requester what a card may carry. On
     * this surface that is what leaves a project as a domain rather than a client and a price.
     *
     * @param  array<string, mixed>  $board
     * @return array<string, mixed>
     */
    private function presentBoard(array $board, Request $request): array
    {
        return [
            ...$board,
            'columns' => array_map(fn (array $column): array => [
                ...$column,
                'tasks' => TaskResource::collection($column['tasks'])->toArray($request),
            ], $board['columns']),
        ];
    }

    /**
     * The Calendar's payload. `span` sits beside the task, not inside it: it is this window's
     * geometry rather than a fact about the task.
     *
     * @param  array<string, mixed>  $calendar
     * @return array<string, mixed>
     */
    private function presentCalendar(array $calendar, Request $request): array
    {
        return [
            'window' => $calendar['window'],
            'total' => $calendar['total'],
            'unscheduled_count' => $calendar['unscheduled_count'],
            'tasks' => array_map(
                fn (array $entry): array => (new TaskResource($entry['task']))->resolve($request)
                    + ['span' => $entry['span']],
                $calendar['entries'],
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function presentFilters(array $filters): array
    {
        unset($filters['as_of']);

        return array_map(
            fn (mixed $value): mixed => $value instanceof Carbon ? $value->toDateString() : $value,
            $filters,
        );
    }

    /**
     * Only the projects this employee can see, so the filter select cannot name a project
     * the list would never show a row from.
     *
     * @return list<array{id: int, name: string}>
     */
    private function projects(Request $request): array
    {
        return Project::query()
            ->visibleTo($request->user())
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Project $project): array => ['id' => $project->id, 'name' => $project->name])
            ->values()
            ->all();
    }

    /**
     * @return list<array{id: int, name: string|null}>
     */
    private function reviewers(Task $task): array
    {
        return $this->tasks->reviewersFor($task)
            ->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name])
            ->values()
            ->all();
    }

    /**
     * Tags for the List's filter chip: the global ones, plus the ones scoped to a project this
     * employee can see — and nothing else.
     *
     * This list used to be every tag in the system, which on this surface was a leak with a
     * name on it: a tag scoped to another client's project carries that project's vocabulary,
     * and an employee who is not on it has no business reading the label. The set is the union
     * over `Project::visibleTo()`, which is the same answer the project filter beside it gives,
     * so the two chips cannot disagree about which work exists.
     *
     * @return list<array{id: int, name: string, colour: string, is_global: bool}>
     */
    private function filterTags(Request $request): array
    {
        return $this->presentTags(Tag::query()->visibleTo($request->user())->orderBy('name')->get());
    }

    /**
     * One task's picker: the tags that task's project can use, its own plus the global ones,
     * which is exactly the set `tag_ids` accepts.
     *
     * @return list<array{id: int, name: string, colour: string, is_global: bool}>
     */
    private function tagsFor(?Project $project): array
    {
        return $project === null
            ? []
            : $this->presentTags(Tag::query()->usableOn($project)->orderBy('name')->get());
    }

    /**
     * @param  Collection<int, Tag>  $tags
     * @return list<array{id: int, name: string, colour: string, is_global: bool}>
     */
    private function presentTags(Collection $tags): array
    {
        return $tags
            ->map(fn (Tag $tag): array => [
                'id' => $tag->id,
                'name' => $tag->name,
                'colour' => $tag->colour,
                'is_global' => $tag->isGlobal(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{description: string, actor: string|null, at: string|null}>
     */
    private function activityFor(Task $task): array
    {
        return $this->activity->for($task)
            ->take(self::ACTIVITY_LIMIT)
            ->load('actor')
            ->map(fn (ActivityLog $log): array => [
                'description' => $log->description,
                'actor' => $log->actor?->name,
                'at' => $log->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  list<TaskStatus|TaskPriority>  $cases
     * @return list<array{value: string, label: string}>
     */
    private function options(array $cases): array
    {
        return array_map(
            fn (TaskStatus|TaskPriority $case): array => [
                'value' => $case->value,
                'label' => $case->label(),
            ],
            $cases,
        );
    }
}
