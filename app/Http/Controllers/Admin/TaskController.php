<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\TaskStateException;
use App\Http\Controllers\Concerns\BuildsDiscussionPayload;
use App\Http\Controllers\Controller;
use App\Http\Requests\Task\ChangeTaskStatusRequest;
use App\Http\Requests\Task\HandOffTaskRequest;
use App\Http\Requests\Task\ReorderTaskRequest;
use App\Http\Requests\Task\StoreChecklistItemRequest;
use App\Http\Requests\Task\StoreTaskDependencyRequest;
use App\Http\Requests\Task\StoreTaskLinkRequest;
use App\Http\Requests\Task\StoreTaskRequest;
use App\Http\Requests\Task\UpdateChecklistItemRequest;
use App\Http\Requests\Task\UpdateTaskAssigneesRequest;
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
use App\Services\ConversationService;
use App\Services\TaskService;
use App\Support\TaskBucket;
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
 * Tasks on the Admin surface: the List, Board and Calendar views, the detail page, and every
 * write a task takes.
 *
 * Even here the list is scoped through Task::visibleTo(): the surface a request arrives on is
 * never what decides which rows it sees, and a task an Admin could not see would answer 404
 * rather than 403.
 *
 * One rule shapes the whole file. A status changes through `status()` and nothing else — not
 * through `update()`, which does not accept the field, and not through a second endpoint for
 * the board drag, which does not exist. The drag posts to `status()` with the card it landed
 * under; the form posts to `status()` without one. Underneath, TaskService::transition() is the
 * only caller of the model's one status door. Phase 1 lost a project's status to a general
 * update endpoint once; a task does not get to repeat it.
 */
class TaskController extends Controller
{
    // The detail page inlines the task's discussion into its props, in the same shape the
    // `…/discussion` endpoint sends, so the panel paints with its thread already in hand.
    use BuildsDiscussionPayload;

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
        // The attachment panel. Current versions only — the relation says so — each one's
        // uploader eager-loaded so FileResource does not query per row.
        'files.uploader',
    ];

    public function __construct(
        private readonly TaskService $tasks,
        private readonly ActivityLogger $activity,
        private readonly ConversationService $conversations,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Task::class);

        $filters = $this->tasks->filters($request->query());
        $groupBy = (string) $request->query('group_by', 'status');
        $grouped = $this->tasks->grouped($request->user(), $filters, $groupBy);

        return Inertia::render('Admin/Tasks/Index', [
            'tasks' => $this->present($grouped, $request),
            'filters' => $this->presentFilters($filters),
            'groupByOptions' => TaskService::GROUP_BY,
            'statuses' => $this->options(TaskStatus::boardOrder()),
            'priorities' => $this->options(TaskPriority::cases()),
            // The bucket chip's options. A bucket is a question rather than a column value
            // — "what is late", "what is due today" — and it is the vocabulary the
            // dashboards' cards link in, so a card's count and the list it opens are the
            // same predicate. Unset, the chip is invisible: FilterBar only draws a chip for
            // a filter that has a value.
            'buckets' => $this->options(TaskBucket::cases()),
            'projects' => $this->projects($request),
            'tags' => $this->filterTags($request),
            'canManageTags' => $this->mayManageTags(),
            // The assignee picker's options. TaskService has taken an `assignee_id` filter
            // since slice 1 and no controller sent the list to build it with, so the chip bar
            // could not offer it — the 2-8 follow-up.
            'employees' => $this->employees(),
        ]);
    }

    /**
     * The Board: the same query the List view runs, grouped by status, drawn as columns.
     *
     * A route of its own rather than `?view=board` on the index — it is deep-linkable, it gets
     * its own row in the permission matrix instead of hiding behind another route's, and the
     * Inertia page name follows it. The filters travel as query parameters, so switching views
     * keeps them.
     */
    public function board(Request $request): Response
    {
        Gate::authorize('viewAny', Task::class);

        $filters = $this->tasks->filters($request->query());

        return Inertia::render('Admin/Tasks/Board', [
            'board' => $this->presentBoard($this->tasks->board($request->user(), $filters), $request),
            'filters' => $this->presentFilters($filters),
            // The role half of the drag rule, so a column nobody in this role could drop into
            // is not offered. The drop itself is still checked per task on the server.
            'transitions' => $this->tasks->transitionsFor($request->user()),
            'statuses' => $this->options(TaskStatus::boardOrder()),
            'priorities' => $this->options(TaskPriority::cases()),
            // The bucket chip's options. A bucket is a question rather than a column value
            // — "what is late", "what is due today" — and it is the vocabulary the
            // dashboards' cards link in, so a card's count and the list it opens are the
            // same predicate. Unset, the chip is invisible: FilterBar only draws a chip for
            // a filter that has a value.
            'buckets' => $this->options(TaskBucket::cases()),
            'projects' => $this->projects($request),
            'tags' => $this->filterTags($request),
            'canManageTags' => $this->mayManageTags(),
            'employees' => $this->employees(),
        ]);
    }

    /**
     * The Calendar: one window, the tasks that overlap it, and each one's span.
     *
     * The window comes back in the payload. A grid that had to infer which dates it was
     * drawing from the tasks it happened to receive would be wrong on the first empty month.
     */
    public function calendar(ViewTaskCalendarRequest $request): Response
    {
        Gate::authorize('viewAny', Task::class);

        $filters = $this->tasks->filters($request->query());

        return Inertia::render('Admin/Tasks/Calendar', [
            'calendar' => $this->presentCalendar($this->tasks->calendar($request->user(), $filters), $request),
            'filters' => $this->presentFilters($filters),
            // Date drags are Admin/Manager only. This is the same answer UpdateTaskRequest
            // gives when it makes `start_date` and `due_date` prohibited, from one definition,
            // so a handle is never enabled for somebody the write would refuse.
            'can_plan' => TaskService::mayPlan($request->user()),
            'statuses' => $this->options(TaskStatus::boardOrder()),
            'priorities' => $this->options(TaskPriority::cases()),
            // The bucket chip's options. A bucket is a question rather than a column value
            // — "what is late", "what is due today" — and it is the vocabulary the
            // dashboards' cards link in, so a card's count and the list it opens are the
            // same predicate. Unset, the chip is invisible: FilterBar only draws a chip for
            // a filter that has a value.
            'buckets' => $this->options(TaskBucket::cases()),
            'projects' => $this->projects($request),
            'tags' => $this->filterTags($request),
            'canManageTags' => $this->mayManageTags(),
            'employees' => $this->employees(),
        ]);
    }

    public function show(Request $request, Task $task): Response
    {
        $task = $this->visible($request, $task);

        // Tells TaskResource this is the detail page, so it resolves the transitions this
        // requester may actually make. A list never asks for that: it is a gate call per
        // candidate status per row.
        $request->attributes->set('task_detail', true);

        return Inertia::render('Admin/Tasks/Show', [
            'task' => (new TaskResource($task))->resolve($request),
            'activity' => $this->activityFor($task),
            'employees' => $this->employees(),
            'reviewers' => $this->reviewers($task),
            'priorities' => $this->options(TaskPriority::cases()),
            // `UpdateTaskRequest` takes `project_id`, so moving a task between projects is a
            // supported write; without this list it was a write with nothing to reach it.
            'projects' => $this->projects($request),
            // The tag picker's options: this project's tags plus every global one, which is
            // exactly the set `tag_ids` will accept. Not every tag in the system — a picker
            // offering a label that the request would then refuse is worse than no picker.
            'tags' => $this->tagsFor($task->project),
            'siblings' => $this->siblings($task),
            // The task's discussion — the plan's "comments", which are the messages of this
            // task's own conversation. Inlined so the panel paints with its thread; the
            // `…/discussion` endpoint sends the identical shape for refreshes after a post.
            'discussion' => $this->discussionPayload($request, $task),
        ]);
    }

    public function store(StoreTaskRequest $request): RedirectResponse
    {
        $task = $this->tasks->create(
            $request->user(),
            $request->validated(),
            $request->assigneeIds(),
            $request->primaryAssigneeId(),
        );

        return redirect()
            ->route('admin.tasks.show', $task)
            ->with('success', 'Task created.');
    }

    public function update(UpdateTaskRequest $request, Task $task): RedirectResponse
    {
        return $this->run($request, $task, fn (Task $task) => $this->tasks->update(
            $request->user(),
            $task,
            $request->validated(),
        ), 'Task updated.');
    }

    public function destroy(Request $request, Task $task): RedirectResponse
    {
        $task = $this->visible($request, $task);

        try {
            $this->tasks->delete($request->user(), $task);
        } catch (TaskStateException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('admin.tasks.index')
            ->with('success', 'Task deleted.');
    }

    /**
     * The only endpoint on this surface that moves a task.
     *
     * The board drag sends `status` and `after_id`; the calendar drag sends `status` alone; the
     * form on the detail page sends `status` with a work summary or a reason. All three land
     * here, in one service call, behind one set of checks.
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

    public function archive(Request $request, Task $task): RedirectResponse
    {
        return $this->run($request, $task, fn (Task $task) => $this->tasks->archive(
            $request->user(),
            $task,
        ), 'Task archived.');
    }

    public function unarchive(Request $request, Task $task): RedirectResponse
    {
        return $this->run($request, $task, fn (Task $task) => $this->tasks->unarchive(
            $request->user(),
            $task,
        ), 'Task unarchived.');
    }

    public function assignees(UpdateTaskAssigneesRequest $request, Task $task): RedirectResponse
    {
        return $this->run($request, $task, fn (Task $task) => $this->tasks->syncAssignees(
            $request->user(),
            $task,
            $request->assigneeIds(),
            $request->primaryAssigneeId(),
        ), 'Assignees updated.');
    }

    /**
     * The explicit hand-off: the other assignee becomes primary, and their work summary is
     * then the one completion accepts.
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

    public function storeDependency(StoreTaskDependencyRequest $request, Task $task): RedirectResponse
    {
        $dependsOn = Task::query()->findOrFail($request->dependsOnTaskId());

        return $this->run($request, $task, fn (Task $task) => $this->tasks->addDependency(
            $request->user(),
            $task,
            $dependsOn,
        ), 'Dependency added.');
    }

    public function destroyDependency(Request $request, Task $task, Task $dependency): RedirectResponse
    {
        return $this->run($request, $task, fn (Task $task) => $this->tasks->removeDependency(
            $request->user(),
            $task,
            $dependency,
        ), 'Dependency removed.');
    }

    /*
    |--------------------------------------------------------------------------
    | Plumbing
    |--------------------------------------------------------------------------
    */

    /**
     * Resolve the task, run the write, and turn a refusal by the task's own state into a flash
     * error rather than a status code.
     *
     * An AuthorizationException is deliberately NOT caught: "you may not" is a 403, and the
     * service throws it whether the caller is a controller, a job or a console command.
     *
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
     * The task, if this requester may see it at all. A record they may not see is absent, so
     * this is a 404 and never a 403.
     */
    private function visible(Request $request, Task $task): Task
    {
        return Task::query()
            ->visibleTo($request->user())
            ->with([...TaskService::RELATIONS, ...self::DETAIL_RELATIONS])
            ->withCount([
                'checklistItems',
                'checklistItems as checklist_items_done_count' => fn ($query) => $query->where('is_done', true),
                'files as attachment_count',
            ])
            ->whereKey($task->getKey())
            ->firstOrFail();
    }

    /**
     * A child row that belongs to another task is not this task's business, and saying so with
     * a 403 would confirm it exists.
     */
    private function belongsTo(?int $taskId, Task $task): void
    {
        abort_unless((int) $taskId === (int) $task->getKey(), 404);
    }

    /**
     * The card a drag was dropped under. Not resolved through visibleTo(): it is a position
     * marker inside a column the requester is already holding, and the service refuses one
     * from another column.
     */
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
     * The Board's payload: the status groups, named columns, with each card through
     * TaskResource — which is what keeps a finance field off a board card, because
     * TaskResource composes ProjectResource rather than reading a project's columns.
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
     * The Calendar's payload. `span` rides beside the task rather than inside it: it is the
     * geometry of this window, not a fact about the task, and TaskResource stays the one thing
     * that decides which of a task's own fields leave the server.
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
     * Filters as the screen's chip bar wants them: the as-of Carbon is an internal detail of
     * the overdue calculation and does not belong in a query string the user can see, and the
     * window's two ends go back as the plain dates they arrived as.
     *
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
     * Projects for the filter select. Not a ProjectResource: this is a picker, and a name
     * with an id is all it may carry.
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
     * The employees a task may be filtered by or assigned to. A picker again: an id and a name,
     * never an EmployeeResource.
     *
     * Who belongs in it is TaskService::assignableEmployees()' answer, and it is a permission
     * question: an Accountant holds no tasks.* permission, so a task assigned to them is a task
     * nobody can even open. The filter is by what a role MAY DO, not by its name, so a later
     * role that can hold tasks turns up here without anybody editing this list.
     *
     * @return list<array{id: int, name: string|null}>
     */
    private function employees(): array
    {
        return $this->tasks->assignableEmployees()
            ->map(fn (Employee $employee): array => ['id' => $employee->id, 'name' => $employee->user?->name])
            ->sortBy('name')
            ->values()
            ->all();
    }

    /**
     * Who may pass a review verdict on this task, so the detail page can name them. Resolved by
     * TaskReviewers — the same code slice 5's "notify the reviewer" will call.
     *
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
     * The other tasks of this project, for the dependency picker. Titles and ids only.
     *
     * @return list<array{id: int, title: string}>
     */
    private function siblings(Task $task): array
    {
        return Task::query()
            ->where('project_id', $task->project_id)
            ->whereKeyNot($task->getKey())
            ->notArchived()
            ->orderBy('title')
            ->get(['id', 'title'])
            ->map(fn (Task $other): array => ['id' => $other->id, 'title' => $other->title])
            ->values()
            ->all();
    }

    /**
     * Tags for the List's filter chip: the global ones plus the ones scoped to a project this
     * requester can see. Not every tag in the system — a tag's NAME is a name, and one scoped
     * to somebody else's project names that project's work.
     *
     * An Admin sees every project, so this is every tag for them; that it is a scoped query
     * rather than an unscoped one is the point — the Manager who cannot is on the same surface
     * and the same controller, and the rule does not want a second implementation.
     *
     * @return list<array{id: int, name: string, colour: string, is_global: bool}>
     */
    private function filterTags(Request $request): array
    {
        return $this->presentTags(Tag::query()->visibleTo($request->user())->orderBy('name')->get());
    }

    /**
     * Whether to offer the tag manager beside the filter chips at all.
     *
     * Sent from the server for the same reason `can_plan` is: the alternative is a Vue file
     * deciding from `auth.user.role`, which is a second copy of `TagPolicy::manages()` living
     * where nobody will remember to change it. `Gate::allows('create', Tag::class)` passes no
     * project, so this is the global-tag answer — the weakest thing the manager can do, and
     * therefore the right gate for whether the door is worth showing. Every endpoint behind
     * the door asks again.
     */
    private function mayManageTags(): bool
    {
        return Gate::allows('create', Tag::class);
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
                'colour' => $tag->colour?->value,
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
     * @param  list<TaskStatus|TaskPriority|TaskBucket>  $cases
     * @return list<array{value: string, label: string}>
     */
    private function options(array $cases): array
    {
        return array_map(
            fn (TaskStatus|TaskPriority|TaskBucket $case): array => [
                'value' => $case->value,
                'label' => $case->label(),
            ],
            $cases,
        );
    }
}
