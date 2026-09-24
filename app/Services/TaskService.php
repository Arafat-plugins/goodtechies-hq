<?php

namespace App\Services;

use App\Events\TaskAssigned;
use App\Events\TaskCompleted;
use App\Events\TaskDeleted;
use App\Events\TaskReassigned;
use App\Events\TaskStatusChanged;
use App\Events\TaskSubmittedForReview;
use App\Exceptions\TaskStateException;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\Tag;
use App\Models\Task;
use App\Models\TaskChecklistItem;
use App\Models\TaskLink;
use App\Models\User;
use App\Support\AuditEvent;
use App\Support\Permission;
use App\Support\RoleName;
use App\Support\TaskBucket;
use App\Support\TaskPriority;
use App\Support\TaskStatus;
use App\Support\UserStatus;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Tasks: the read queries the three views share, and every write a task can take.
 *
 * There is one query. The List view groups it, the Board names those groups columns, and the
 * Calendar windows it — grouped(), board() and calendar() are three shapes over query(), not
 * three queries, so a filter behaves identically whichever view the user switched from.
 *
 * Every query starts at Task::visibleTo(), so a row the requester may not see is never in the
 * result to be filtered out later. The surface a request arrives on never decides this.
 *
 * On the write side this class is the ONLY place a task changes, and `transition()` is the only
 * place its status does. That is not a convention: Task::applyTransition() is the single door
 * through a model-level guard, so a future drag handler, job or controller that writes
 * `tasks.status` by any other route throws instead of working. The form and the drag therefore
 * cannot end up with different rules, because there is no second path to put different rules on.
 *
 * Validation is never here — it is in the Form Requests. Authorization is never here either —
 * it is in TaskPolicy; this class asks the gate and turns a refusal into an exception so that a
 * service caller outside HTTP (a job, a command) is refused the same way a controller is.
 */
class TaskService
{
    /** The relations a task payload needs, so a list is not a query per row. */
    public const RELATIONS = [
        'project.client',
        'project.pm.user',
        'project.members.user',
        'project.finance',
        'assignees.user',
        'tags',
    ];

    /** The group-by variants the List view offers. */
    public const GROUP_BY = ['status', 'assignee', 'project', 'priority'];

    /**
     * The two orderings the views ask for. See order().
     *
     * They are named rather than passed as raw SQL because there are exactly two questions a
     * task list is ever asked here, and a third caller inventing its own sort is how the List
     * and the Board end up disagreeing about where a card is.
     */
    public const ORDER_LIST = 'list';

    public const ORDER_BOARD = 'board';

    /**
     * The attributes a caller may write through create() and update().
     *
     * `status` is deliberately absent, and so are the completion columns, `archived_at` and
     * `position`. Phase 1 shipped the security fix this list is the memory of: a general update
     * endpoint that accepted `status` let an assigned manager cancel a project through the edit
     * form. A `status` handed to update() is dropped here, not obeyed.
     *
     * @var list<string>
     */
    private const FIELDS = [
        'project_id',
        'title',
        'description',
        'priority',
        'start_date',
        'due_date',
        'estimated_minutes',
    ];

    /**
     * The attributes only CREATE may set: where the task came from.
     *
     * Phase 3. A generated task is created through create() like any other — the repo has
     * refused a second creation path twice (decisions 2-9 and 2-36) — but its provenance is not
     * something anybody edits afterwards, so it is listed here instead of in FIELDS. update()
     * never looks at this list, so a request that smuggles `recurring_task_id` into the edit
     * form cannot reassign a task to another template or restamp its period.
     *
     * The two columns being written in the SAME INSERT as the rest of the task is the whole
     * point: `tasks_recurring_task_period_unique` is checked on that row, so there is no instant
     * in which a generated task exists without the period that makes it unique. That is what
     * turns the duplicate rule from an `if` into a constraint.
     *
     * @var list<string>
     */
    private const BIRTH_FIELDS = [
        'recurring_task_id',
        'recurring_period',
    ];

    /**
     * The statuses a task may be BORN in.
     *
     * A task has to start somewhere, so creation is the one write that sets a status without a
     * transition — but only to a status that owes work. Nobody creates a task that is already
     * completed, and nobody reaches COMPLETED except through the machine.
     *
     * @var list<string>
     */
    public const BIRTH_STATUSES = ['backlog', 'todo'];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ActivityLogger $activity,
        private readonly TaskReviewers $reviewers,
        private readonly ConversationService $conversations,
    ) {}

    /**
     * The base query: visible to this user, filtered, ordered.
     *
     * The ordering is a parameter rather than a fact about this method, and it defaults to the
     * List's. A view asks for the order that answers its own question — see order() — and a
     * caller that says nothing gets the one /admin/tasks has always shown.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<Task>
     */
    public function query(User $user, array $filters = [], string $order = self::ORDER_LIST): Builder
    {
        return $this->order($this->filtered($user, $filters)
            ->with(self::RELATIONS)
            // The List view's "Subtasks" column, promised by slice 1's TaskResource and
            // countable now that there is a checklist to count. Two aggregates, not a query
            // per row.
            ->withCount([
                'checklistItems',
                'checklistItems as checklist_items_done_count' => fn (Builder $query) => $query->where('is_done', true),
                // The paperclip on a card. The `files` relation is already current-versions
                // only, so this counts files and not revisions.
                'files as attachment_count',
            ])
            // **"Assignee on leave" (Phase 5, Part D §5 and §9).** A correlated subquery, not a
            // second pass: this method is the single funnel for the List, the Board, the
            // Calendar, My Tasks and the dashboard cards, so hanging the flag here gives every
            // one of them the same answer with no controller change and no extra round trip.
            // A board of two hundred cards costs the query it already cost.
            //
            // It is information, never an action. Nothing anywhere reassigns a task because its
            // assignee is away — Part D §5 says flag, Part H forbids inventing the rest, and an
            // Admin is the one who decides what to do about Thursday.
            //
            // Who may see whose flag is resolved on the server by `taskFlagScopeFor()`: an
            // approver sees every assignee's, everybody else sees only their own. See
            // `LeaveRequest::taskFlagQuery()`.
            ->addSelect([
                'assignees_on_leave' => LeaveRequest::taskFlagQuery(LeaveRequest::taskFlagScopeFor($user)),
            ]), $order);
    }

    /**
     * How many tasks this question has — scoped and filtered exactly as the list would be.
     *
     * A card's number is a COUNT in the database, never `count($rows)` after a fetch and never
     * a length read off a page of a paginated list. That is the whole difference between "4
     * overdue" and "4 overdue on this page".
     *
     * It runs against filtered() rather than query(): the eager loads and the two ordering
     * columns are for a list somebody reads, and Postgres refuses `select count(*) … order by
     * due_date` outright.
     *
     * @param  array<string, mixed>  $filters
     */
    public function count(User $user, array $filters = []): int
    {
        return $this->filtered($user, $filters)->count();
    }

    /**
     * One count per bucket, each its own query against the same scoped, filtered base.
     *
     * The buckets are `TaskBucket` cases rather than strings so that a screen cannot ask for
     * a count of something that has no definition — and so that the card's `?bucket=` link
     * and this count are the same enum case, which is what stops them drifting apart.
     *
     * @param  list<TaskBucket>  $buckets
     * @param  array<string, mixed>  $filters
     * @return array<string, int>
     */
    public function bucketCounts(User $user, array $buckets, array $filters = []): array
    {
        $counts = [];

        foreach ($buckets as $bucket) {
            $counts[$bucket->value] = $this->count($user, [...$filters, 'bucket' => $bucket->value]);
        }

        return $counts;
    }

    /**
     * The buckets as a screen draws them: key, label and count, in the order asked for.
     *
     * The card and the link it carries are the same enum case, so a strip cannot end up with
     * a number from one predicate and a destination built from another.
     *
     * @param  list<TaskBucket>  $buckets
     * @param  array<string, mixed>  $filters
     * @return list<array{key: string, label: string, count: int}>
     */
    public function bucketCards(User $user, array $buckets, array $filters = []): array
    {
        $counts = $this->bucketCounts($user, $buckets, $filters);

        return array_map(fn (TaskBucket $bucket): array => [
            'key' => $bucket->value,
            'label' => $bucket->cardLabel(),
            'count' => $counts[$bucket->value],
        ], $buckets);
    }

    /**
     * Everything query() and count() share: the rows this user may see at all, narrowed by
     * the filters. No ordering and no eager loading, because one of the two callers wants a
     * number and the other wants a screen.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<Task>
     */
    private function filtered(User $user, array $filters): Builder
    {
        $filters = $this->filters($filters);

        return Task::query()
            ->visibleTo($user)
            // "Assigned to me", which for an Admin is a real narrowing: Task::visibleTo() has
            // already handed them the whole agency. A user with no employee row is assigned
            // nothing, and must not fall through to everybody's work — see onlyMine().
            ->when($filters['mine'], fn (Builder $q) => $this->onlyMine($q, $user))
            // The bucket, whose definition lives in TaskBucket::apply() and nowhere else.
            ->when($filters['bucket'], fn (Builder $q, string $bucket) => TaskBucket::from($bucket)
                ->apply($q, $filters['as_of']))
            ->when($filters['search'], fn (Builder $q, string $search) => $q->where('title', 'ilike', '%'.$search.'%'))
            ->when($filters['project_id'], fn (Builder $q, int $id) => $q->where('project_id', $id))
            ->when($filters['status'], fn (Builder $q, string $status) => $q->where('status', $status))
            ->when($filters['priority'], fn (Builder $q, string $priority) => $q->where('priority', $priority))
            ->when($filters['assignee_id'], fn (Builder $q, int $id) => $q->whereHas(
                'assignees',
                fn (Builder $a) => $a->where('employees.id', $id),
            ))
            ->when($filters['tag_id'], fn (Builder $q, int $id) => $q->whereHas(
                'tags',
                fn (Builder $t) => $t->where('tags.id', $id),
            ))
            // The date window, as an OVERLAP rather than a containment — see windowPredicate().
            ->when($filters['date_from'], fn (Builder $q, Carbon $from) => $q->whereRaw(
                'coalesce(tasks.due_date, tasks.start_date) >= ?',
                [$from->toDateString()],
            ))
            ->when($filters['date_to'], fn (Builder $q, Carbon $to) => $q->whereRaw(
                'coalesce(tasks.start_date, tasks.due_date) <= ?',
                [$to->toDateString()],
            ))
            ->when($filters['overdue'], fn (Builder $q) => $q->overdue($filters['as_of']))
            // Archived tasks are hidden from active views unless explicitly asked for.
            ->unless($filters['archived'], fn (Builder $q) => $q->notArchived());
    }

    /**
     * The `mine` narrowing: tasks this user is assigned to, primary or not.
     *
     * The null branch is the one that matters. `Task::visibleTo()` gives an Admin every row,
     * so if "mine" quietly did nothing for a user without an employee record, an Admin's My
     * Tasks page would show the agency's entire backlog as their own plate. Nobody is assigned
     * a task without an employee row, so the honest answer is no rows.
     *
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    private function onlyMine(Builder $query, User $user): Builder
    {
        $employee = $user->employee;

        return $employee === null ? $query->whereRaw('1 = 0') : $query->forEmployee($employee);
    }

    /**
     * The ordering a view asked for. Two views, two questions, two orderings — and the whole
     * difference is which column leads.
     *
     * **The List** (ORDER_LIST) is read top to bottom and answers "what is next", so the due
     * date leads, undated work falls to the bottom, and `position` is only a tiebreak. This is
     * what /admin/tasks has always shown and it is why the ordering is a parameter: changing
     * this default would move a screen nobody asked to move.
     *
     * **A Board lane** (ORDER_BOARD) is dragged, so `position` leads — that is the whole point
     * of a manual order. A lane sorted by date first is a lane where a card dropped at the top
     * springs back, because `position` third behind a date almost never gets to break anything.
     *
     * The Board's tiebreaks, in order:
     *
     *   1. `due_date asc nulls last`. A card nobody has dragged has no manual order to honour —
     *      `position` defaults to 0 for any row written outside this service, and the positions
     *      that already exist were handed out per project, so two cards in one lane can share a
     *      number. Both cases are answered the same way and answered well: cards that agree on
     *      `position` fall back to the date, which is exactly the order the Board shows today.
     *      An untouched lane therefore reads like the List, and a collision is harmless rather
     *      than arbitrary.
     *   2. `id`. Makes the sort total, so two cards that agree on both never swap places between
     *      two identical requests — and place() can only compute a drop against a lane whose
     *      order is stable.
     *
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    private function order(Builder $query, string $order): Builder
    {
        return $order === self::ORDER_BOARD
            ? $query->orderBy('position')->orderByRaw('due_date asc nulls last')->orderBy('id')
            : $query->orderByRaw('due_date asc nulls last')->orderBy('position')->orderBy('id');
    }

    /**
     * The List view's payload: tasks bucketed into collapsible groups, each with its count.
     *
     * The shape is the same whichever variant is asked for — an ordered list of
     * `{key, label, tone, count, tasks}` — so the screen renders one loop and the toggle
     * changes data, not markup.
     *
     * A task with two assignees appears in BOTH of their groups under `group_by=assignee`.
     * That is deliberate: the group is "this person's work", and the task is both people's.
     * `total` therefore counts tasks, and may be less than the sum of the group counts.
     *
     * @param  array<string, mixed>  $filters
     * @return array{
     *     group_by: string,
     *     groups: list<array{key: string, label: string, tone: string|null, count: int, tasks: Collection<int, Task>}>,
     *     total: int,
     *     overdue_count: int,
     * }
     */
    public function grouped(
        User $user,
        array $filters = [],
        string $groupBy = 'status',
        string $order = self::ORDER_LIST,
    ): array {
        $groupBy = in_array($groupBy, self::GROUP_BY, true) ? $groupBy : 'status';
        $filters = $this->filters($filters);

        /** @var Collection<int, Task> $tasks */
        $tasks = $this->query($user, $filters, $order)->get();

        $groups = match ($groupBy) {
            'assignee' => $this->byAssignee($tasks),
            'project' => $this->byProject($tasks),
            'priority' => $this->byPriority($tasks),
            default => $this->byStatus($tasks),
        };

        return [
            'group_by' => $groupBy,
            'groups' => $groups,
            'total' => $tasks->count(),
            'overdue_count' => $tasks->filter(
                fn (Task $task): bool => $task->isOverdue($filters['as_of']),
            )->count(),
        ];
    }

    /**
     * The overdue bucket: `due_date < as-of date` and the status is one somebody still owes
     * work on. Computed here, never stored — a stored flag would be wrong every midnight.
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, Task>
     */
    public function overdue(User $user, array $filters = [], ?Carbon $asOf = null): Collection
    {
        return $this->query($user, $filters)->overdue($asOf ?? Carbon::today())->get();
    }

    /**
     * The Board's payload: the same grouped-by-status query the List view already builds,
     * named for what it is. A board column IS a status group — there is no second query and
     * no second ordering, so a card cannot sit in one place on the List and another on the
     * Board.
     *
     * Every status is present, including the empty ones: byStatus() walks
     * TaskStatus::boardOrder() rather than the rows it happens to have, because a column that
     * vanishes when it is empty is a column you cannot drag anything INTO.
     *
     * @param  array<string, mixed>  $filters
     * @return array{
     *     columns: list<array{key: string, label: string, tone: string|null, count: int, tasks: Collection<int, Task>}>,
     *     total: int,
     *     overdue_count: int,
     * }
     */
    public function board(User $user, array $filters = []): array
    {
        $grouped = $this->grouped($user, $filters, 'status', self::ORDER_BOARD);

        return [
            'columns' => $grouped['groups'],
            'total' => $grouped['total'],
            'overdue_count' => $grouped['overdue_count'],
        ];
    }

    /**
     * The Calendar's payload: a window, the tasks that OVERLAP it, and each one's geometry.
     *
     * The window it answers with is the window it actually used — defaulted to the as-of
     * month when the caller named none, and never silently different from what was asked
     * for. A grid that has to infer which dates it is drawing is a grid that will one day
     * draw the wrong ones.
     *
     * Each entry carries a `span` so the screen lays a bar out without doing date arithmetic
     * of its own: where the span really starts and ends, where it starts and ends INSIDE this
     * window, and whether it runs off either edge. A task starting 28 Aug and due 3 Sep is in
     * both months' payloads, clipped differently in each.
     *
     * @param  array<string, mixed>  $filters
     * @return array{
     *     window: array{from: string, to: string, days: int, month: string|null},
     *     entries: list<array{task: Task, span: array<string, mixed>}>,
     *     total: int,
     *     unscheduled_count: int,
     * }
     */
    public function calendar(User $user, array $filters = []): array
    {
        $filters = $this->filters($filters);
        [$from, $to] = $this->window($filters);

        $filters['date_from'] = $from;
        $filters['date_to'] = $to;

        $entries = [];

        foreach ($this->query($user, $filters)->get() as $task) {
            $span = $this->span($task, $from, $to);

            if ($span !== null) {
                $entries[] = ['task' => $task, 'span' => $span];
            }
        }

        // Lane-packing order: earliest visible day first, then the longest bar, then id so
        // two identical spans never swap places between two requests.
        usort($entries, fn (array $a, array $b): int => [
            $a['span']['visible_start'], -$a['span']['total_days'], (int) $a['task']->getKey(),
        ] <=> [
            $b['span']['visible_start'], -$b['span']['total_days'], (int) $b['task']->getKey(),
        ]);

        return [
            'window' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'days' => (int) $from->diffInDays($to) + 1,
                // Set only when the window is exactly one calendar month, which is what a
                // month grid asks for and what its heading names.
                'month' => $from->isSameDay($from->copy()->startOfMonth())
                    && $to->isSameDay($from->copy()->endOfMonth()->startOfDay())
                        ? $from->format('Y-m')
                        : null,
            ],
            'entries' => $entries,
            'total' => count($entries),
            // The tasks this window can never show, because they have no date at all. Sent as
            // a count so the grid can say so instead of quietly losing them.
            'unscheduled_count' => $this->query($user, [...$filters, 'date_from' => null, 'date_to' => null])
                ->whereNull('start_date')
                ->whereNull('due_date')
                ->count(),
        ];
    }

    /**
     * The role half of the drag rule: per column, the columns this user's ROLE may move a card
     * to. Derived from TaskStatus, not restated — the board, the calendar and the detail form
     * read one map.
     *
     * It is deliberately only half the answer. The task half — "an employee moves a task they
     * are assigned to" and "only this project's reviewer passes a review" — is a fact about a
     * particular card and stays in TaskPolicy, which the endpoint checks on every drop. So a
     * target this map offers can still be refused; what it buys is a board that does not offer
     * a column nobody in this role could ever drop into. TaskResource's `available_transitions`
     * is the per-task answer, and it costs a gate call per candidate status per row, which is
     * right on a detail page and wrong on a board.
     *
     * @return array<string, list<string>>
     */
    public function transitionsFor(?User $user): array
    {
        $role = $user?->employee?->role?->name;
        $map = [];

        foreach (TaskStatus::boardOrder() as $from) {
            $map[$from->value] = $role === null ? [] : array_values(array_map(
                fn (TaskStatus $to): string => $to->value,
                array_filter(
                    $from->allowedTransitions(),
                    fn (TaskStatus $to): bool => $from->mayRoleTransition($role, $to),
                ),
            ));
        }

        return $map;
    }

    /**
     * May this user change the PLAN — the dates, the priority, the title, which project a task
     * is in — as opposed to the work?
     *
     * One definition with two readers. UpdateTaskRequest makes the plan fields `prohibited` for
     * anybody this says no to, and the Calendar sends the same answer as `can_plan` so the date
     * handles are disabled for exactly the people the request would refuse. Two copies of this
     * rule is one copy that can drift, and the drift would look like a date drag that appears
     * to work and then is not saved.
     */
    public static function mayPlan(?User $user): bool
    {
        return $user?->hasRole(RoleName::ADMIN, RoleName::MANAGER) === true;
    }

    /*
    |--------------------------------------------------------------------------
    | Writes
    |--------------------------------------------------------------------------
    */

    /**
     * Create a task, assign it, and put it at the bottom of its column.
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<int>  $assigneeIds  the FIRST is the primary unless $primaryId says otherwise
     *
     * @throws AuthorizationException
     * @throws TaskStateException
     */
    public function create(User $actor, array $attributes, array $assigneeIds = [], ?int $primaryId = null): Task
    {
        if (! Gate::forUser($actor)->allows('create', Task::class)) {
            throw new AuthorizationException('You are not allowed to create tasks.');
        }

        $status = TaskStatus::tryFrom((string) ($attributes['status'] ?? ''));

        if ($status === null || ! in_array($status->value, self::BIRTH_STATUSES, true)) {
            $status = TaskStatus::Backlog;
        }

        return DB::transaction(function () use ($actor, $attributes, $assigneeIds, $primaryId, $status): Task {
            $task = new Task;
            $task->fill(array_intersect_key($attributes, array_flip(self::FIELDS)));
            $task->forceFill([
                'status' => $status,
                'created_by' => $actor->getKey(),
                'position' => $this->nextPosition($status),
                // Phase 3: the recurring origin, in this INSERT and only this one. See
                // BIRTH_FIELDS — the unique index is checked on this row, not afterwards.
                ...array_intersect_key($attributes, array_flip(self::BIRTH_FIELDS)),
            ])->save();

            if (isset($attributes['work_summary'])) {
                $this->writeWorkSummary($task, $actor, (string) $attributes['work_summary']);
                $task->save();
            }

            $this->activity->record($task, 'Task created', $actor);

            // Every task is born with its discussion (Phase 2 slice 4, recorded decision): the
            // spec's `task_comments` table is not created, and a task's "comments" are the
            // messages of its own `task` conversation. Doing it here, inside the create
            // transaction, means a task and its discussion arrive together or not at all.
            //
            // It is not the only guarantee, and deliberately so — the conversations migration
            // backfills every task that predates the table, and forTask() is a firstOrCreate
            // behind a unique index, so a task made by the factory or the seeder still ends up
            // with exactly one.
            $this->conversations->forTask($task);

            if ($assigneeIds !== []) {
                $this->applyAssignees($actor, $task, $assigneeIds, $primaryId, AuditEvent::TaskAssigned);
            }

            return $task->refresh();
        });
    }

    /**
     * Edit a task. Not its status, not its assignees, not its position — each of those is its
     * own method with its own ability and its own audit row.
     *
     * Tags ride along here rather than on an endpoint of their own, because putting a label on
     * a task is an edit and needs exactly the ability an edit needs: `tasks.update`. That is
     * also what keeps the two halves of "a tag belongs to one project" in one transaction — a
     * request that moves the task AND sets its tags is checked against the project it is moving
     * TO, and a move that says nothing about tags drops the ones that would not survive it.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws AuthorizationException
     * @throws TaskStateException
     */
    public function update(User $actor, Task $task, array $attributes): Task
    {
        $this->guardWritable($actor, $task, 'update', 'edited');

        return DB::transaction(function () use ($actor, $task, $attributes): Task {
            $task->fill(array_intersect_key($attributes, array_flip(self::FIELDS)));

            if (array_key_exists('work_summary', $attributes)) {
                $this->writeWorkSummary($task, $actor, (string) $attributes['work_summary']);
            }

            $changed = array_values(array_diff(array_keys($task->getDirty()), ['work_summary_by', 'work_summary_at']));
            $moved = in_array('project_id', $changed, true);

            if ($changed !== []) {
                $task->save();

                $this->activity->record($task, 'Task updated: '.implode(', ', $changed), $actor);
            }

            // After the save, so both of these see the project the task is in NOW.
            if (array_key_exists('tag_ids', $attributes)) {
                $this->applyTags($actor, $task, (array) $attributes['tag_ids']);
            } elseif ($moved) {
                $this->dropForeignTags($actor, $task);
            }

            return $task->refresh();
        });
    }

    /**
     * Move a task along the status machine. The ONLY way `tasks.status` changes.
     *
     * The board drag, the calendar drag and the form on the detail page are three callers of
     * this one method through one endpoint per surface, which is what makes them impossible to
     * give different rules to. The optional `$after` is the card the drop landed under, so a
     * drag that changes column also lands in the right place in it, in the same transaction.
     *
     * @throws AuthorizationException
     * @throws TaskStateException
     */
    public function transition(
        User $actor,
        Task $task,
        TaskStatus $to,
        ?string $workSummary = null,
        ?string $reason = null,
        ?Task $after = null,
    ): Task {
        if ($task->isArchived()) {
            throw TaskStateException::archived('moved to another status');
        }

        $from = $task->status;

        if ($from === null || ! $from->canTransitionTo($to)) {
            throw TaskStateException::transition($from, $to);
        }

        if (! Gate::forUser($actor)->allows('transition', [$task, $to])) {
            throw new AuthorizationException(sprintf(
                'You are not allowed to move this task to %s.',
                $to->label(),
            ));
        }

        $reason = $this->clean($reason);
        $workSummary = $this->clean($workSummary);

        // Two moves may not happen silently: cancelling work, and undoing a completion.
        $isReopen = $from === TaskStatus::Completed && $to === TaskStatus::InProgress;

        if ($reason === null && ($to === TaskStatus::Cancelled || $isReopen)) {
            throw TaskStateException::reasonRequired($to);
        }

        return DB::transaction(function () use ($actor, $task, $from, $to, $workSummary, $reason, $after, $isReopen): Task {
            $before = $this->statusSnapshot($task);

            if ($workSummary !== null) {
                $this->writeWorkSummary($task, $actor, $workSummary);
            }

            // A task goes to review with somebody's summary on it, and is completed only with
            // the PRIMARY assignee's — see assertCompletable().
            if ($to === TaskStatus::InReview && $this->clean($task->work_summary) === null) {
                throw TaskStateException::workSummaryRequired($to);
            }

            if ($to === TaskStatus::Completed) {
                $this->assertCompletable($task);

                $task->forceFill([
                    'completed_by' => $actor->getKey(),
                    'completed_at' => now(),
                ]);

                // The first completion, kept for good. Written only while still null, so the
                // second and third completions never overwrite the first.
                if ($task->first_completed_at === null) {
                    $task->forceFill([
                        'first_work_summary' => $task->work_summary,
                        'first_completed_by' => $actor->getKey(),
                        'first_completed_at' => now(),
                    ]);
                }
            }

            if ($isReopen) {
                // The task genuinely is not complete any more, so the live completion columns
                // are cleared — a row that says completed_at while its status is In progress
                // contradicts itself. The original survives in first_completed_*, which was
                // written when it was completed and is never touched again.
                $task->forceFill(['completed_by' => null, 'completed_at' => null]);
            }

            $task->applyTransition($to)->save();

            // A card that changed column lands at the bottom of the new one unless the drag
            // said which card it was dropped under — the same sparse-position arithmetic
            // reorder() uses, so a drag that changes column and one that does not agree.
            if ($after !== null) {
                $this->place($task, $after);
            } else {
                $task->forceFill([
                    'position' => $this->nextPosition($to, (int) $task->getKey()),
                ])->save();
            }

            $line = sprintf('Status changed from %s to %s', $from->label(), $to->label());

            if ($isReopen) {
                $line = sprintf(
                    'Reopened from %s to %s (completed %s by %s — that completion is kept)',
                    $from->label(),
                    $to->label(),
                    $task->first_completed_at?->toDayDateTimeString() ?? 'previously',
                    $task->firstCompleter()->first()?->name ?? 'somebody',
                );
            }

            $this->activity->record($task, $reason === null ? $line : $line.' — '.$reason, $actor);

            $this->audit->record(
                AuditEvent::TaskStatusChanged,
                $task,
                $before,
                $this->statusSnapshot($task->refresh()) + ['reason' => $reason],
                $actor,
            );

            $this->announceTransition($actor, $task, $from, $to, $reason);

            return $task;
        });
    }

    /**
     * Slice 5: tell the people who need to know that this task moved.
     *
     * ONE event per move. Two of the eight destinations have a notification of their own in the
     * spec's automation table — "Task marked In Review → notify reviewer" and "Task completed →
     * notify original assigner/reviewer" — so those two fire their own event INSTEAD of the
     * general one, never as well as it. §11's rule is "one event = one notification", and a
     * reviewer told both "waiting for your review" and "status changed to In review" has been
     * told the same thing twice.
     *
     * Who hears about each is NotificationDispatcher's half, not this class's.
     */
    private function announceTransition(
        User $actor,
        Task $task,
        TaskStatus $from,
        TaskStatus $to,
        ?string $reason,
    ): void {
        event(match ($to) {
            TaskStatus::InReview => new TaskSubmittedForReview($task, $actor),
            TaskStatus::Completed => new TaskCompleted($task, $actor),
            default => new TaskStatusChanged($task, $actor, $from, $to, $reason),
        });
    }

    /**
     * Set who the task is assigned to, and which of them is primary.
     *
     * @param  list<int>  $assigneeIds
     *
     * @throws AuthorizationException
     * @throws TaskStateException
     */
    public function syncAssignees(User $actor, Task $task, array $assigneeIds, ?int $primaryId = null): Task
    {
        $this->guardWritable($actor, $task, 'assign', 'reassigned');

        return DB::transaction(function () use ($actor, $task, $assigneeIds, $primaryId): Task {
            $had = $task->assignees()->exists();

            $this->applyAssignees(
                $actor,
                $task,
                $assigneeIds,
                $primaryId,
                $had ? AuditEvent::TaskReassigned : AuditEvent::TaskAssigned,
            );

            return $task->refresh();
        });
    }

    /**
     * Hand the task over: make another of its assignees the primary one.
     *
     * This IS the "explicit hand-off" the completion rule allows for. Completion needs the
     * primary assignee's work summary, and this is the only thing that moves who that is — so
     * a second assignee finishing somebody else's task is always a recorded act with an actor
     * and a reason behind it, never a quiet exception to the rule.
     *
     * @throws AuthorizationException
     * @throws TaskStateException
     */
    public function handOff(User $actor, Task $task, Employee $to, string $reason): Task
    {
        $this->guardWritable($actor, $task, 'handOff', 'handed over');

        $reason = $this->clean($reason);

        if ($reason === null) {
            throw new TaskStateException('A hand-off has to say why.');
        }

        $assignees = $task->assignees()->get();

        if (! $assignees->contains('id', $to->id)) {
            throw TaskStateException::notAnAssignee();
        }

        $from = $assignees->first(fn (Employee $employee): bool => (bool) $employee->pivot?->is_primary);

        if ($from !== null && $from->id === $to->id) {
            throw TaskStateException::alreadyPrimary($this->employeeName($to));
        }

        return DB::transaction(function () use ($actor, $task, $assignees, $from, $to, $reason): Task {
            // One UPDATE per row rather than a sync: the partial unique index allows exactly
            // one primary per task, so the old one is demoted before the new one is promoted.
            foreach ($assignees as $employee) {
                $task->assignees()->updateExistingPivot($employee->id, ['is_primary' => false]);
            }

            $task->assignees()->updateExistingPivot($to->id, ['is_primary' => true]);

            $this->activity->record($task, sprintf(
                'Handed over: %s is now the primary assignee (was %s) — %s',
                $this->employeeName($to),
                $from === null ? 'nobody' : $this->employeeName($from),
                $reason,
            ), $actor);

            $this->audit->record(
                AuditEvent::TaskReassigned,
                $task,
                ['primary_employee_id' => $from?->id],
                ['primary_employee_id' => $to->id, 'reason' => $reason, 'hand_off' => true],
                $actor,
            );

            // Slice 5. A hand-off does not go through applyAssignees() — the assignee SET does
            // not change, only which of them owns completion — so the event is fired here, with
            // identical before and after lists and two different primaries. That is exactly the
            // shape NotificationDispatcher reads to work out that both of them need telling.
            $ids = $assignees->pluck('id')->map('intval')->values()->all();

            event(new TaskReassigned($task, $actor, $ids, $ids, $from?->id, (int) $to->id));

            return $task->refresh();
        });
    }

    /**
     * Soft-delete the task. Admin and Manager only, audit-logged, and soft precisely because
     * the audit row has to keep pointing at something.
     *
     * @throws AuthorizationException
     */
    public function delete(User $actor, Task $task): void
    {
        if (! Gate::forUser($actor)->allows('delete', $task)) {
            throw new AuthorizationException('You are not allowed to delete this task.');
        }

        DB::transaction(function () use ($actor, $task): void {
            // Audit first: the row is written while the task is still there to be described,
            // and audit_logs is append-only so it cannot be tidied up afterwards.
            $this->audit->record(AuditEvent::TaskDeleted, $task, $this->snapshot($task), null, $actor);
            $this->activity->record($task, 'Task deleted', $actor);

            $task->delete();

            // After the delete, so the event describes something that has happened. The delete
            // is SOFT, so the row, its assignees and its policy answers are all still there for
            // the dispatcher to ask about.
            event(new TaskDeleted($task, $actor));
        });
    }

    /**
     * @throws AuthorizationException
     * @throws TaskStateException
     */
    public function archive(User $actor, Task $task): Task
    {
        // Permission before state, unlike ProjectService: "it is already archived" is a fact
        // about the task, and somebody who may not archive it has no business learning it.
        if (! Gate::forUser($actor)->allows('archive', $task)) {
            throw new AuthorizationException('You are not allowed to archive this task.');
        }

        if ($task->isArchived()) {
            throw TaskStateException::alreadyArchived();
        }

        return DB::transaction(function () use ($actor, $task): Task {
            // Archiving is orthogonal to status, exactly as it is for a project: the task keeps
            // the status it had, and comes back in it.
            $task->forceFill(['archived_at' => now()])->save();

            $this->activity->record($task, sprintf(
                'Task archived (was %s)',
                $task->status?->label() ?? 'unknown',
            ), $actor);

            return $task->refresh();
        });
    }

    /**
     * @throws AuthorizationException
     * @throws TaskStateException
     */
    public function unarchive(User $actor, Task $task): Task
    {
        if (! Gate::forUser($actor)->allows('unarchive', $task)) {
            throw new AuthorizationException('You are not allowed to unarchive this task.');
        }

        if (! $task->isArchived()) {
            throw TaskStateException::notArchived();
        }

        return DB::transaction(function () use ($actor, $task): Task {
            $task->forceFill(['archived_at' => null])->save();

            $this->activity->record($task, 'Task unarchived', $actor);

            return $task->refresh();
        });
    }

    /**
     * Move a card inside its column: put $task straight after $after, or at the top when
     * $after is null.
     *
     * Positions are sparse multiples of 1000 so the ordinary drop is one UPDATE of one row —
     * the midpoint between its two new neighbours. When the midpoints run out (two neighbours
     * one apart, after ten drops into the same crack) the column, and only the column, is
     * renumbered back onto the 1000s and the drop is applied to that.
     *
     * @throws AuthorizationException
     * @throws TaskStateException
     */
    public function reorder(User $actor, Task $task, ?Task $after = null): Task
    {
        $this->guardWritable($actor, $task, 'update', 'reordered');

        if ($after !== null && ! $this->sameColumn($task, $after)) {
            throw new TaskStateException('A task can only be reordered against a card in the same column.');
        }

        return DB::transaction(function () use ($task, $after): Task {
            $this->place($task, $after);

            return $task->refresh();
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Checklist, links, dependencies
    |--------------------------------------------------------------------------
    */

    /**
     * @throws AuthorizationException
     * @throws TaskStateException
     */
    public function addChecklistItem(User $actor, Task $task, string $title): TaskChecklistItem
    {
        $this->guardWritable($actor, $task, 'update', 'edited');

        return DB::transaction(function () use ($actor, $task, $title): TaskChecklistItem {
            $last = $task->checklistItems()->max('position');

            $item = $task->checklistItems()->create([
                'title' => $title,
                'position' => (int) $last + Task::POSITION_STEP,
            ]);

            $this->activity->record($task, 'Checklist item added: '.$title, $actor);

            return $item;
        });
    }

    /**
     * Rename or tick a checklist item. Ticking records who and when; unticking clears both,
     * because a half-remembered "completed by" on an unticked line is worse than none.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws AuthorizationException
     * @throws TaskStateException
     */
    public function updateChecklistItem(User $actor, TaskChecklistItem $item, array $attributes): TaskChecklistItem
    {
        $task = $item->task;
        $this->guardWritable($actor, $task, 'update', 'edited');

        return DB::transaction(function () use ($actor, $task, $item, $attributes): TaskChecklistItem {
            if (array_key_exists('title', $attributes)) {
                $item->title = (string) $attributes['title'];
            }

            if (array_key_exists('is_done', $attributes)) {
                $done = (bool) $attributes['is_done'];

                $item->forceFill([
                    'is_done' => $done,
                    'completed_by' => $done ? $actor->getKey() : null,
                    'completed_at' => $done ? now() : null,
                ]);
            }

            if ($item->isDirty()) {
                $ticked = $item->isDirty('is_done');
                $item->save();

                $this->activity->record($task, $ticked
                    ? sprintf('Checklist item %s: %s', $item->is_done ? 'ticked' : 'unticked', $item->title)
                    : 'Checklist item renamed: '.$item->title, $actor);
            }

            return $item;
        });
    }

    /**
     * @throws AuthorizationException
     * @throws TaskStateException
     */
    public function removeChecklistItem(User $actor, TaskChecklistItem $item): void
    {
        $task = $item->task;
        $this->guardWritable($actor, $task, 'update', 'edited');

        DB::transaction(function () use ($actor, $task, $item): void {
            $title = $item->title;
            $item->delete();

            $this->activity->record($task, 'Checklist item removed: '.$title, $actor);
        });
    }

    /**
     * @throws AuthorizationException
     * @throws TaskStateException
     */
    public function addLink(User $actor, Task $task, string $url, ?string $label = null): TaskLink
    {
        $this->guardWritable($actor, $task, 'update', 'edited');

        return DB::transaction(function () use ($actor, $task, $url, $label): TaskLink {
            $link = $task->links()->create([
                'url' => $url,
                'label' => $this->clean($label),
                'created_by' => $actor->getKey(),
            ]);

            $this->activity->record($task, 'Link added: '.$link->displayLabel(), $actor);

            return $link;
        });
    }

    /**
     * @throws AuthorizationException
     * @throws TaskStateException
     */
    public function removeLink(User $actor, TaskLink $link): void
    {
        $task = $link->task;
        $this->guardWritable($actor, $task, 'update', 'edited');

        DB::transaction(function () use ($actor, $task, $link): void {
            $label = $link->displayLabel();
            $link->delete();

            $this->activity->record($task, 'Link removed: '.$label, $actor);
        });
    }

    /**
     * Record that $task waits for $dependsOn.
     *
     * Three things are refused: a task depending on itself (the database refuses it too), a
     * dependency on a task in another project, and anything that would close a cycle. The cycle
     * check is a walk rather than a constraint because no CHECK can express reachability.
     *
     * @throws AuthorizationException
     * @throws TaskStateException
     */
    public function addDependency(User $actor, Task $task, Task $dependsOn): Task
    {
        $this->guardWritable($actor, $task, 'update', 'edited');

        if ($task->getKey() === $dependsOn->getKey()) {
            throw TaskStateException::dependsOnItself();
        }

        if ($task->project_id !== $dependsOn->project_id) {
            throw TaskStateException::dependencyAcrossProjects();
        }

        if ($this->reaches($dependsOn, (int) $task->getKey())) {
            throw TaskStateException::dependencyCycle();
        }

        return DB::transaction(function () use ($actor, $task, $dependsOn): Task {
            $task->dependencies()->syncWithoutDetaching([
                $dependsOn->getKey() => ['created_by' => $actor->getKey(), 'created_at' => now()],
            ]);

            $this->activity->record($task, 'Now waiting for: '.$dependsOn->title, $actor);

            return $task->refresh();
        });
    }

    /**
     * @throws AuthorizationException
     * @throws TaskStateException
     */
    public function removeDependency(User $actor, Task $task, Task $dependsOn): Task
    {
        $this->guardWritable($actor, $task, 'update', 'edited');

        return DB::transaction(function () use ($actor, $task, $dependsOn): Task {
            $task->dependencies()->detach($dependsOn->getKey());

            $this->activity->record($task, 'No longer waiting for: '.$dependsOn->title, $actor);

            return $task->refresh();
        });
    }

    /**
     * Everyone who may pass a review verdict on this task — the project's PM, or every Admin
     * when it has none. Slice 5's notifications call this, not a second copy of the rule.
     *
     * @return SupportCollection<int, User>
     */
    public function reviewersFor(Task $task): SupportCollection
    {
        return $this->reviewers->for($task);
    }

    /**
     * The employees a task may be assigned to, and filtered by.
     *
     * Active, and holding the one permission that makes a task workable at all:
     * `tasks.view` is the first line of TaskPolicy::view(), and every other ability on a task
     * goes through view() — so a role without it can be given a task and then do nothing with
     * it, not even see it. The Accountant falls out here without being named, and so will any
     * later role that holds no tasks.* permission; a later role that does hold one appears by
     * itself. This is the rule, not a list of roles to keep in step with the seeder.
     *
     * @return Collection<int, Employee>
     */
    public function assignableEmployees(): Collection
    {
        return Employee::query()
            ->with('user')
            ->where('status', UserStatus::Active->value)
            ->whereHas('role.permissions', fn (Builder $query) => $query->where(
                'permissions.key',
                Permission::TasksView->value,
            ))
            ->get();
    }

    /*
    |--------------------------------------------------------------------------
    | The rules behind the writes
    |--------------------------------------------------------------------------
    */

    /**
     * Completion needs the PRIMARY assignee's work summary.
     *
     * Both assignees see and edit the task, and either may submit it for review with their own
     * summary — but the summary the task is COMPLETED on has to be the primary's, because the
     * primary is who the work was given to. The stored author is what makes that checkable at
     * all; an anonymous text column would pass this test whoever typed into it.
     *
     * The exception is the hand-off: handOff() moves "primary" to the other assignee, with an
     * actor, a reason and an audit row, and then their summary is the one that counts. That is
     * what "or an explicit hand-off" means here — a recorded change of owner, not a silent
     * waiver of the rule.
     *
     * A task nobody is assigned to has no primary to require, so whoever completes it answers
     * for the summary themselves.
     *
     * @throws TaskStateException
     */
    private function assertCompletable(Task $task): void
    {
        if ($this->clean($task->work_summary) === null) {
            throw TaskStateException::workSummaryRequired(TaskStatus::Completed);
        }

        $primary = $task->primary();

        if ($primary === null) {
            return;
        }

        if ($task->work_summary_by !== null && (int) $task->work_summary_by === (int) $primary->user_id) {
            return;
        }

        throw TaskStateException::primaryWorkSummaryRequired($this->employeeName($primary));
    }

    /**
     * Store a work summary together with who wrote it and when. Never written any other way —
     * the authorship is half the rule.
     */
    private function writeWorkSummary(Task $task, User $actor, string $summary): void
    {
        $summary = $this->clean($summary);

        if ($summary === null) {
            $task->forceFill(['work_summary' => null, 'work_summary_by' => null, 'work_summary_at' => null]);

            return;
        }

        if ($this->clean($task->work_summary) === $summary && (int) $task->work_summary_by === (int) $actor->getKey()) {
            return;
        }

        $task->forceFill([
            'work_summary' => $summary,
            'work_summary_by' => $actor->getKey(),
            'work_summary_at' => now(),
        ]);
    }

    /**
     * Sync the assignee set, keep exactly one primary, and write the audit row.
     *
     * @param  list<int>  $assigneeIds
     *
     * @throws TaskStateException
     */
    private function applyAssignees(
        User $actor,
        Task $task,
        array $assigneeIds,
        ?int $primaryId,
        AuditEvent $event,
    ): void {
        $assigneeIds = array_values(array_unique(array_map('intval', $assigneeIds)));

        if (count($assigneeIds) > Task::MAX_ASSIGNEES) {
            throw TaskStateException::tooManyAssignees(Task::MAX_ASSIGNEES);
        }

        // The first listed is the primary unless the caller named one; a named one that is not
        // on the list is a mistake, not a third assignee.
        if ($primaryId !== null && ! in_array((int) $primaryId, $assigneeIds, true)) {
            throw TaskStateException::primaryNotAssigned();
        }

        $primaryId ??= $assigneeIds[0] ?? null;

        $before = $this->assigneeSnapshot($task);

        $payload = [];

        foreach ($assigneeIds as $id) {
            $payload[$id] = ['is_primary' => $id === (int) $primaryId];
        }

        // Detach first: the partial unique index forbids two primaries even for the instant a
        // sync would need to hold both.
        $task->assignees()->detach();
        $task->assignees()->sync($payload);
        $task->load('assignees.user');

        $after = $this->assigneeSnapshot($task);

        if ($before === $after) {
            return;
        }

        $names = $task->assignees
            ->map(fn (Employee $employee): string => $this->employeeName($employee)
                .((int) $employee->id === (int) $primaryId ? ' (primary)' : ''))
            ->all();

        $this->activity->record($task, $names === []
            ? 'Assignees cleared'
            : 'Assigned to '.implode(', ', $names), $actor);

        $this->audit->record($event, $task, $before, $after, $actor);

        // Slice 5. Fired here rather than in the two public callers because this is the one
        // place that knows whether the set really changed — the early return above means an
        // idempotent re-save of the same assignees notifies nobody, which is what stops a form
        // submitted twice from sending two "you were assigned" rows.
        //
        // In the same transaction as the audit row, deliberately: the listener writes
        // `notifications` synchronously, so a write that rolls back takes its notification with
        // it. See App\Events\TaskAssigned for why that is the rule.
        event($event === AuditEvent::TaskAssigned
            ? new TaskAssigned($task, $actor, $after['assignee_ids'])
            : new TaskReassigned(
                $task,
                $actor,
                $before['assignee_ids'],
                $after['assignee_ids'],
                $before['primary_employee_id'] === null ? null : (int) $before['primary_employee_id'],
                $after['primary_employee_id'] === null ? null : (int) $after['primary_employee_id'],
            ));
    }

    /**
     * Set the task's tags to exactly this list.
     *
     * Assigning is not creating. Anyone who may update the task may put an EXISTING tag on it —
     * the plan's rule is "Admin/Manager create them, global or scoped to one project; employees
     * only assign existing tags" — and creating one is not reachable from here at all.
     *
     * A tag scoped to another project is refused, not quietly dropped. UpdateTaskRequest says
     * so first, with a message against the field; this is the same rule at the only place that
     * writes `task_tags`, so a job or a console command cannot get round it either.
     *
     * @param  list<int>|array<array-key, mixed>  $tagIds
     *
     * @throws TaskStateException
     */
    private function applyTags(User $actor, Task $task, array $tagIds): void
    {
        $ids = array_values(array_unique(array_map('intval', $tagIds)));

        // Re-read the project rather than trusting a loaded relation: this runs after a move,
        // and the relation on the instance may still be the project the task came from.
        $project = $task->project()->first();

        /** @var Collection<int, Tag> $usable */
        $usable = $project === null || $ids === []
            ? new Collection
            : Tag::query()->usableOn($project)->whereKey($ids)->get();

        if ($usable->count() !== count($ids)) {
            throw TaskStateException::tagNotUsable();
        }

        $before = $task->tags()->pluck('tags.id')->map('intval')->sort()->values()->all();
        $after = $usable->pluck('id')->map('intval')->sort()->values()->all();

        if ($before === $after) {
            return;
        }

        $task->tags()->sync($ids);
        $task->load('tags');

        $names = $usable->sortBy('name')->pluck('name')->all();

        $this->activity->record(
            $task,
            $names === [] ? 'Tags cleared' : 'Tagged: '.implode(', ', $names),
            $actor,
        );
    }

    /**
     * A task that changed project keeps its global tags and leaves the old project's behind.
     *
     * Without this a move is the one way a task ends up carrying a label that belongs to a
     * project it is not in any more — which the tag picker would then not even offer to remove,
     * because it only lists what this project can use.
     */
    private function dropForeignTags(User $actor, Task $task): void
    {
        /** @var Collection<int, Tag> $foreign */
        $foreign = $task->tags()
            ->whereNotNull('tags.project_id')
            ->where('tags.project_id', '!=', $task->project_id)
            ->get();

        if ($foreign->isEmpty()) {
            return;
        }

        $task->tags()->detach($foreign->pluck('id')->all());
        $task->load('tags');

        $this->activity->record($task, sprintf(
            'Tags dropped with the move to another project: %s',
            implode(', ', $foreign->sortBy('name')->pluck('name')->all()),
        ), $actor);
    }

    /**
     * Put $task straight after $after in its column, or at the top when $after is null.
     *
     * Used by reorder() and by transition(), so a card that changes column and a card that
     * only moves within one get their position the same way.
     */
    private function place(Task $task, ?Task $after): void
    {
        $column = $this->column($task);
        $siblings = $column->reject(fn (Task $other): bool => $other->getKey() === $task->getKey())->values();

        $index = $after === null
            ? 0
            : $siblings->search(fn (Task $other): bool => $other->getKey() === $after->getKey());

        // A card dropped under something that is not in this column goes to the bottom, which
        // is also where a card that just changed column lands.
        if ($index === false) {
            $index = $siblings->count();
        } elseif ($after !== null) {
            $index++;
        }

        $low = $index > 0 ? (int) $siblings[$index - 1]->position : null;
        $high = $index < $siblings->count() ? (int) $siblings[$index]->position : null;

        $position = $this->between($low, $high);

        if ($position !== null) {
            $task->forceFill(['position' => $position])->save();

            return;
        }

        // The midpoints ran out. Renumber THIS column back onto the 1000s — never the board,
        // never another project — and the drop lands in the gap that makes.
        $ordered = $siblings->all();
        array_splice($ordered, $index, 0, [$task]);

        foreach ($ordered as $offset => $card) {
            $card->forceFill(['position' => ($offset + 1) * Task::POSITION_STEP])->save();
        }
    }

    /**
     * The position between two neighbours, or null when there is no whole number left between
     * them and the column has to be renumbered.
     */
    private function between(?int $low, ?int $high): ?int
    {
        if ($low === null && $high === null) {
            return Task::POSITION_STEP;
        }

        if ($low === null) {
            return $high > 1 ? intdiv($high, 2) : null;
        }

        if ($high === null) {
            return $low + Task::POSITION_STEP;
        }

        $midpoint = intdiv($low + $high, 2);

        return $midpoint > $low && $midpoint < $high ? $midpoint : null;
    }

    /**
     * One Kanban column: the tasks of one project in one status, in drag order.
     *
     * @return Collection<int, Task>
     */
    /**
     * A lane, in drag order.
     *
     * A lane is a STATUS, not a (project, status) pair. The Board's columns are statuses
     * across every project — a card carries its project name precisely because the lane
     * mixes them — so scoping a column to one project made a manual order that could only
     * ever reorder a card against its own project's cards, and forced the screen to walk a
     * drop anchor backwards looking for one. Positions are handed out per status for the
     * same reason.
     *
     * It orders by ORDER_BOARD — the same ordering the Board draws with — and that is not a
     * detail. place() turns the card you dropped under into an INDEX in this list, so if this
     * list is in a different order from the one on screen, the card lands somewhere other than
     * where it was dropped. It ordered by (position, id) once, while the Board drew by
     * (position, due_date, id); every lane whose positions tied put the card in the wrong
     * place, and every lane's positions tied.
     */
    private function column(Task $task): Collection
    {
        return $this->order(
            Task::query()->where('status', $task->status?->value),
            self::ORDER_BOARD,
        )->get();
    }

    private function sameColumn(Task $task, Task $other): bool
    {
        return $task->status === $other->status;
    }

    /**
     * The bottom of a column. `$exceptId` is the card being moved INTO the column: it is
     * already carrying the new status by the time this runs, and a card must not be placed
     * relative to where it already is.
     */
    private function nextPosition(TaskStatus $status, ?int $exceptId = null): int
    {
        $last = Task::query()
            ->where('status', $status->value)
            ->when($exceptId !== null, fn (Builder $query) => $query->whereKeyNot($exceptId))
            ->max('position');

        return (int) $last + Task::POSITION_STEP;
    }

    /**
     * Can $from reach $target by following what it waits for? The cycle check.
     */
    private function reaches(Task $from, int $target): bool
    {
        $seen = [];
        $queue = [(int) $from->getKey()];

        while ($queue !== []) {
            $id = array_shift($queue);

            if ($id === $target) {
                return true;
            }

            if (isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;

            foreach (DB::table('task_dependencies')->where('task_id', $id)->pluck('depends_on_task_id') as $next) {
                $queue[] = (int) $next;
            }
        }

        return false;
    }

    /**
     * @throws AuthorizationException
     * @throws TaskStateException
     */
    private function guardWritable(User $actor, Task $task, string $ability, string $action): void
    {
        // Archived is a state, not a permission: it answers with a flash error, not a 403.
        if ($task->isArchived()) {
            throw TaskStateException::archived($action);
        }

        if (! Gate::forUser($actor)->allows($ability, $task)) {
            throw new AuthorizationException('You are not allowed to change this task.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function statusSnapshot(Task $task): array
    {
        return [
            'status' => $task->status?->value,
            'completed_by' => $task->completed_by,
            'completed_at' => $task->completed_at?->toIso8601String(),
            'work_summary_by' => $task->work_summary_by,
            // The original completion, so an audit reader can see a reopening did not lose it.
            'first_completed_at' => $task->first_completed_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function assigneeSnapshot(Task $task): array
    {
        $assignees = $task->relationLoaded('assignees') ? $task->assignees : $task->assignees()->get();

        return [
            'assignee_ids' => $assignees->pluck('id')->map('intval')->sort()->values()->all(),
            'primary_employee_id' => $assignees
                ->first(fn (Employee $employee): bool => (bool) $employee->pivot?->is_primary)?->id,
        ];
    }

    /**
     * The task as the audit trail should remember it once it is gone.
     *
     * @return array<string, mixed>
     */
    private function snapshot(Task $task): array
    {
        return [
            'title' => $task->title,
            'project_id' => $task->project_id,
            'status' => $task->status?->value,
            'priority' => $task->priority?->value,
            'start_date' => $task->start_date?->toDateString(),
            'due_date' => $task->due_date?->toDateString(),
            'estimated_minutes' => $task->estimated_minutes,
            'tracked_seconds' => (int) $task->tracked_seconds,
            'created_by' => $task->created_by,
        ] + $this->assigneeSnapshot($task);
    }

    private function clean(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * The window a calendar request actually gets: what it asked for, or the as-of month.
     *
     * Half a window is still a window — `date_from` alone is "this month onwards from there" —
     * so each end falls back to the other's month rather than to nothing. A window that runs
     * backwards collapses to a single day; over HTTP ViewTaskCalendarRequest refuses it first,
     * and this is what keeps a job or a console command from producing an empty grid instead
     * of an error.
     *
     * @param  array{date_from: Carbon|null, date_to: Carbon|null, as_of: Carbon}  $filters
     * @return array{0: Carbon, 1: Carbon}
     */
    private function window(array $filters): array
    {
        $from = $filters['date_from'];
        $to = $filters['date_to'];

        if ($from === null && $to === null) {
            $month = $filters['as_of'];

            return [$month->copy()->startOfMonth(), $month->copy()->endOfMonth()->startOfDay()];
        }

        $from ??= $to->copy()->startOfMonth();
        $to ??= $from->copy()->endOfMonth()->startOfDay();

        return $to->lt($from) ? [$from->copy(), $from->copy()] : [$from->copy(), $to->copy()];
    }

    /**
     * One task's geometry inside one window.
     *
     * The span is `[start_date, due_date]`, and a task carrying only one of the two is a
     * single day on that date — the plan's "tasks by due date, and start→due span when a start
     * date exists", said once, here, rather than recomputed in the screen. A task with neither
     * date has no place on a grid and returns null; the query has already excluded it.
     *
     * `visible_*` is the span clipped to the window and `continues_*` says which edge it runs
     * off, which together are everything a bar needs: a task starting 28 Aug and due 3 Sep
     * draws in September from the 1st with an arrow on its left, and in August to the 31st
     * with an arrow on its right, from two payloads that each did the clipping here.
     *
     * @return array{start: string, end: string, visible_start: string, visible_end: string, days: int, total_days: int, continues_before: bool, continues_after: bool, is_single_day: bool}|null
     */
    private function span(Task $task, Carbon $from, Carbon $to): ?array
    {
        $start = $task->start_date ?? $task->due_date;
        $end = $task->due_date ?? $task->start_date;

        if ($start === null || $end === null) {
            return null;
        }

        $start = $start->copy()->startOfDay();
        $end = $end->copy()->startOfDay();

        // UpdateTaskRequest refuses a due date before its start date, so this only catches a
        // row that got its dates some other way. Swapping beats drawing a negative-width bar.
        if ($end->lt($start)) {
            [$start, $end] = [$end, $start];
        }

        $visibleStart = $start->lt($from) ? $from->copy() : $start->copy();
        $visibleEnd = $end->gt($to) ? $to->copy() : $end->copy();

        return [
            'start' => $start->toDateString(),
            'end' => $end->toDateString(),
            'visible_start' => $visibleStart->toDateString(),
            'visible_end' => $visibleEnd->toDateString(),
            // Inclusive of both ends: a one-day task is 1, not 0.
            'days' => (int) $visibleStart->diffInDays($visibleEnd) + 1,
            'total_days' => (int) $start->diffInDays($end) + 1,
            'continues_before' => $start->lt($from),
            'continues_after' => $end->gt($to),
            'is_single_day' => $start->isSameDay($end),
        ];
    }

    /**
     * One group per status, in board order, including the empty ones.
     *
     * The empty groups matter: a status column that vanishes when it has no tasks makes the
     * board look like the status does not exist, and the group counts are what the collapsed
     * header shows.
     *
     * @param  Collection<int, Task>  $tasks
     * @return list<array{key: string, label: string, tone: string|null, count: int, tasks: Collection<int, Task>}>
     */
    private function byStatus(Collection $tasks): array
    {
        return array_map(function (TaskStatus $status) use ($tasks): array {
            $inGroup = $tasks->filter(fn (Task $task): bool => $task->status === $status)->values();

            return [
                'key' => $status->value,
                'label' => $status->label(),
                'tone' => $status->tone(),
                'count' => $inGroup->count(),
                'tasks' => $inGroup,
            ];
        }, TaskStatus::boardOrder());
    }

    /**
     * One group per priority, urgent first — never alphabetically, or Urgent files under U.
     *
     * @param  Collection<int, Task>  $tasks
     * @return list<array{key: string, label: string, tone: string|null, count: int, tasks: Collection<int, Task>}>
     */
    private function byPriority(Collection $tasks): array
    {
        $cases = TaskPriority::cases();
        usort($cases, fn (TaskPriority $a, TaskPriority $b): int => $a->rank() <=> $b->rank());

        return array_map(function (TaskPriority $priority) use ($tasks): array {
            $inGroup = $tasks->filter(fn (Task $task): bool => $task->priority === $priority)->values();

            return [
                'key' => $priority->value,
                'label' => $priority->label(),
                'tone' => null,
                'count' => $inGroup->count(),
                'tasks' => $inGroup,
            ];
        }, $cases);
    }

    /**
     * One group per assignee, plus an "Unassigned" group when anything lands in it. Only the
     * people who actually have a task here get a group — unlike statuses, the set of
     * employees is open-ended and an empty group per employee would be noise.
     *
     * @param  Collection<int, Task>  $tasks
     * @return list<array{key: string, label: string, tone: string|null, count: int, tasks: Collection<int, Task>}>
     */
    private function byAssignee(Collection $tasks): array
    {
        $groups = [];

        foreach ($tasks as $task) {
            $assignees = $task->relationLoaded('assignees') ? $task->assignees : $task->assignees()->get();

            if ($assignees->isEmpty()) {
                $groups['unassigned'] ??= ['label' => 'Unassigned', 'tasks' => []];
                $groups['unassigned']['tasks'][] = $task;

                continue;
            }

            foreach ($assignees as $assignee) {
                $key = (string) $assignee->id;
                $groups[$key] ??= ['label' => $this->employeeName($assignee), 'tasks' => []];
                $groups[$key]['tasks'][] = $task;
            }
        }

        // Named people first, alphabetically; Unassigned is a bucket, not a person, so it
        // sits at the end whatever its name would sort as.
        uksort($groups, function (string $a, string $b) use ($groups): int {
            if ($a === 'unassigned') {
                return 1;
            }

            if ($b === 'unassigned') {
                return -1;
            }

            return strcasecmp($groups[$a]['label'], $groups[$b]['label']);
        });

        return $this->finalise($groups);
    }

    /**
     * One group per project, alphabetically.
     *
     * @param  Collection<int, Task>  $tasks
     * @return list<array{key: string, label: string, tone: string|null, count: int, tasks: Collection<int, Task>}>
     */
    private function byProject(Collection $tasks): array
    {
        $groups = [];

        foreach ($tasks as $task) {
            $key = (string) $task->project_id;
            $groups[$key] ??= ['label' => $task->project?->name ?? "#{$task->project_id}", 'tasks' => []];
            $groups[$key]['tasks'][] = $task;
        }

        uksort($groups, fn (string $a, string $b): int => strcasecmp($groups[$a]['label'], $groups[$b]['label']));

        return $this->finalise($groups);
    }

    /**
     * @param  array<string, array{label: string, tasks: list<Task>}>  $groups
     * @return list<array{key: string, label: string, tone: string|null, count: int, tasks: Collection<int, Task>}>
     */
    private function finalise(array $groups): array
    {
        $out = [];

        foreach ($groups as $key => $group) {
            $out[] = [
                // Cast back to string: PHP turns a numeric array key into an int on the way
                // in, so an employee id keyed group would otherwise come out typed differently
                // from a status keyed one and the screen would need two branches.
                'key' => (string) $key,
                'label' => $group['label'],
                'tone' => null,
                'count' => count($group['tasks']),
                'tasks' => new Collection($group['tasks']),
            ];
        }

        return $out;
    }

    private function employeeName(Employee $employee): string
    {
        return $employee->user?->name ?? "#{$employee->id}";
    }

    /**
     * Every filter key, defaulted, so callers and the payload agree on the shape even when
     * the request carried none of them. An unrecognised enum value becomes null rather than
     * reaching the query as a literal.
     *
     * `date_from` / `date_to` are the calendar's window, and the predicate they build is an
     * OVERLAP, not a containment:
     *
     *     coalesce(due_date, start_date) >= date_from
     *     coalesce(start_date, due_date) <= date_to
     *
     * `whereBetween('due_date', …)` would be wrong, and wrong in the direction that loses
     * work. A task that starts 28 August and is due 3 September belongs on BOTH months' grids
     * — it is being worked on for the whole of the 1st, 2nd and 3rd of September — and a test
     * on `due_date` alone drops it from August's, where a bar should run to the edge. The
     * coalesce is what makes a task with only one of the two dates a single day on that date
     * instead of a row with a NULL that no comparison matches: both ends are null only when
     * the task has no dates at all, and then the comparison is NULL and the task is correctly
     * absent from every window.
     *
     * Either end may stand alone: `date_from` on its own is an open-ended "from here on".
     *
     * @param  array<string, mixed>  $filters
     * @return array{search: string|null, project_id: int|null, status: string|null, priority: string|null, assignee_id: int|null, tag_id: int|null, bucket: string|null, mine: bool, date_from: Carbon|null, date_to: Carbon|null, overdue: bool, archived: bool, as_of: Carbon}
     */
    public function filters(array $filters): array
    {
        $search = trim((string) ($filters['search'] ?? ''));
        $asOf = $filters['as_of'] ?? null;

        return [
            'search' => $search === '' ? null : $search,
            'project_id' => $this->id($filters['project_id'] ?? null),
            'status' => TaskStatus::tryFrom((string) ($filters['status'] ?? ''))?->value,
            // An unrecognised bucket becomes null rather than an error, exactly as an
            // unrecognised status does — a stale link asks a question that no longer exists,
            // and the honest answer is the unnarrowed list, not a 500.
            'bucket' => TaskBucket::tryFrom((string) ($filters['bucket'] ?? ''))?->value,
            'mine' => (bool) ($filters['mine'] ?? false),
            'priority' => TaskPriority::tryFrom((string) ($filters['priority'] ?? ''))?->value,
            'assignee_id' => $this->id($filters['assignee_id'] ?? null),
            'tag_id' => $this->id($filters['tag_id'] ?? null),
            'date_from' => $this->date($filters['date_from'] ?? null),
            'date_to' => $this->date($filters['date_to'] ?? null),
            'overdue' => (bool) ($filters['overdue'] ?? false),
            'archived' => (bool) ($filters['archived'] ?? false),
            // Overdue is relative to a date, so the date is a parameter rather than a call to
            // today() buried in the query — that is what makes it testable at a fixed date.
            'as_of' => $asOf instanceof Carbon ? $asOf : Carbon::today(),
        ];
    }

    private function id(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * A window end, normalised to midnight. An unparseable one becomes null rather than
     * reaching the query, the same way an unrecognised enum filter does above — over HTTP
     * ViewTaskCalendarRequest has already named it as an error, and a caller that is not HTTP
     * gets a default window rather than a database error.
     */
    private function date(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value->copy()->startOfDay();
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse(trim($value))->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }
}
