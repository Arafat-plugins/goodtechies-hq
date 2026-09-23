<?php

namespace App\Models;

use App\Exceptions\TaskStateException;
use App\Support\Permission;
use App\Support\RecurrenceRule;
use App\Support\RoleName;
use App\Support\TaskPriority;
use App\Support\TaskStatus;
use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

#[Fillable([
    'project_id',
    'title',
    'description',
    'status',
    'priority',
    'start_date',
    'due_date',
    'created_by',
    'estimated_minutes',
    'work_summary',
    'position',
])]
class Task extends Model
{
    /** @use HasFactory<TaskFactory> */
    use HasFactory;

    // Delete is Admin/Manager, audit-logged, and must not take the task's history with it.
    use SoftDeletes;

    /** At most two people on a task, exactly one of them primary. */
    public const MAX_ASSIGNEES = 2;

    /**
     * How many tags one task takes. Not a business rule so much as a bound on the list a
     * request may send: the picker offers a project's tags plus the global ones, and a task
     * wearing ten labels has already stopped saying anything.
     */
    public const MAX_TAGS = 10;

    /**
     * The gap drag-and-drop leaves between two cards, so a drop between them is a midpoint and
     * not a renumbering of the column. See TaskService::reorder().
     */
    public const POSITION_STEP = 1000;

    /**
     * This instance is inside applyTransition(), the one sanctioned status write.
     *
     * Per instance rather than static: a nested save of some other task must not inherit
     * another task's permission to move.
     */
    private bool $transitioning = false;

    /** Demo seeding only — see withoutStatusGuard(). */
    private static bool $statusGuardSuspended = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TaskStatus::class,
            'priority' => TaskPriority::class,
            'start_date' => 'date',
            'due_date' => 'date',
            'completed_at' => 'datetime',
            'first_completed_at' => 'datetime',
            'work_summary_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * The status guard.
     *
     * `tasks.status` is writable on a row that does not exist yet — a task is born somewhere —
     * and after that ONLY through applyTransition(), which re-checks TaskStatus::TRANSITIONS
     * itself. That makes two things true of the code rather than of anybody's intentions:
     *
     *   - no second code path can move a task. A future drag handler, a queue job, a console
     *     command or a controller that fills `status` from request input all hit this and
     *     throw, so "every drag goes through TaskService" is enforced, not hoped for;
     *   - no code path, sanctioned or not, can park a task in a status the machine does not
     *     have, because the legality check is inside the only door.
     *
     * Permission is a separate question and stays in TaskPolicy, which TaskService consults
     * before it opens this door.
     */
    protected static function booted(): void
    {
        static::saving(function (Task $task): void {
            if (! $task->exists || ! $task->isDirty('status')) {
                return;
            }

            if ($task->transitioning || self::$statusGuardSuspended) {
                return;
            }

            throw TaskStateException::statusWrittenOutsideTheMachine();
        });

        static::saved(function (Task $task): void {
            $task->transitioning = false;
        });
    }

    /**
     * Move this task's status. The only door in the guard above.
     *
     * It checks the map and nothing else: who may make the move is TaskPolicy's answer and
     * TaskService asks for it before calling this.
     *
     * @throws TaskStateException
     */
    public function applyTransition(TaskStatus $to): static
    {
        $from = $this->status;

        if ($from === null || ! $from->canTransitionTo($to)) {
            throw TaskStateException::transition($from, $to);
        }

        $this->transitioning = true;
        $this->status = $to;

        return $this;
    }

    /**
     * Suspend the guard for the duration of the callback.
     *
     * This exists for ONE caller: the demo seeder, which paints a board that shows every status
     * at once and is therefore not making transitions at all. Application code has no business
     * here — use TaskService::transition().
     */
    public static function withoutStatusGuard(callable $callback): mixed
    {
        self::$statusGuardSuspended = true;

        try {
            return $callback();
        } finally {
            self::$statusGuardSuspended = false;
        }
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Everyone assigned, primary or not. `is_primary` rides on the pivot.
     *
     * @return BelongsToMany<Employee, $this>
     */
    public function assignees(): BelongsToMany
    {
        return $this->belongsToMany(Employee::class, 'task_assignees')
            ->withPivot('is_primary')
            ->withTimestamps();
    }

    /**
     * The one assignee who owns completion: the work summary must be theirs.
     *
     * @return BelongsToMany<Employee, $this>
     */
    public function primaryAssignee(): BelongsToMany
    {
        return $this->assignees()->wherePivot('is_primary', true);
    }

    /**
     * @return BelongsToMany<Tag, $this>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'task_tags');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    /**
     * Whoever wrote the work summary that is on the row now. Completion checks this against
     * the primary assignee — an anonymous summary could be anybody's.
     *
     * @return BelongsTo<User, $this>
     */
    public function workSummaryAuthor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'work_summary_by');
    }

    /**
     * Who completed it the FIRST time, preserved across every reopening.
     *
     * @return BelongsTo<User, $this>
     */
    public function firstCompleter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'first_completed_by');
    }

    /**
     * The template this task was generated from, when it was generated at all.
     *
     * Phase 3. `recurring_task_id` and `recurring_period` are NOT fillable and are not in
     * TaskService::FIELDS either: a task's provenance is set once, in the same INSERT that
     * creates it, and is never editable afterwards — see TaskService::BIRTH_FIELDS.
     *
     * @return BelongsTo<RecurringTask, $this>
     */
    public function recurringTask(): BelongsTo
    {
        return $this->belongsTo(RecurringTask::class, 'recurring_task_id');
    }

    /**
     * Was this task generated by the recurring engine?
     */
    public function isGenerated(): bool
    {
        return $this->recurring_task_id !== null;
    }

    /**
     * The period this instance belongs to, as a person reads it — the "period <Month YYYY>" half
     * of task detail's "Generated from: <template> · period <Month YYYY>".
     *
     * Derived from the stored key alone, so it survives the template being deleted.
     */
    public function recurringPeriodLabel(): ?string
    {
        return RecurrenceRule::labelForPeriod($this->recurring_period);
    }

    /**
     * @return HasMany<TaskChecklistItem, $this>
     */
    public function checklistItems(): HasMany
    {
        return $this->hasMany(TaskChecklistItem::class)->orderBy('position')->orderBy('id');
    }

    /**
     * @return HasMany<TaskLink, $this>
     */
    public function links(): HasMany
    {
        return $this->hasMany(TaskLink::class)->orderBy('id');
    }

    /**
     * The task's attachments — the CURRENT version of each, newest first.
     *
     * Superseded versions are excluded here rather than filtered by every caller: a panel and a
     * count both want "the files on this task", and a task showing five attachments because two
     * of them have been revised twice is a count nobody asked for. One file's history is a
     * separate question, asked per file through FileService::history().
     *
     * @return HasMany<File, $this>
     */
    public function files(): HasMany
    {
        return $this->hasMany(File::class)->whereNull('superseded_at')->orderByDesc('id');
    }

    /**
     * The tasks this one is waiting for.
     *
     * @return BelongsToMany<Task, $this>
     */
    public function dependencies(): BelongsToMany
    {
        return $this->belongsToMany(
            Task::class,
            'task_dependencies',
            'task_id',
            'depends_on_task_id',
        );
    }

    /**
     * The tasks waiting for this one — the same table read the other way round, never a
     * second row.
     *
     * @return BelongsToMany<Task, $this>
     */
    public function dependents(): BelongsToMany
    {
        return $this->belongsToMany(
            Task::class,
            'task_dependencies',
            'depends_on_task_id',
            'task_id',
        );
    }

    /**
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    public function scopeNotArchived(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    /**
     * Tasks this employee is assigned to, primary or not.
     *
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    public function scopeForEmployee(Builder $query, Employee $employee): Builder
    {
        return $query->whereHas(
            'assignees',
            fn (Builder $assignees) => $assignees->where('employees.id', $employee->id),
        );
    }

    /**
     * Still owed: the status is not one of TaskStatus::closed().
     *
     * Extracted from scopeOverdue() rather than written beside it, because "somebody still
     * owes work on this" is half of the overdue definition AND the whole of the Due today and
     * My Tasks buckets. Two spellings of it is how a task ends up counted as due today on one
     * screen and not on another.
     *
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('status', array_map(
            fn (TaskStatus $status): string => $status->value,
            TaskStatus::closed(),
        ));
    }

    /**
     * Overdue, computed here and never stored: `due_date < today` and the status is one
     * somebody still owes work on. A stored flag would be wrong every midnight.
     *
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    public function scopeOverdue(Builder $query, ?Carbon $asOf = null): Builder
    {
        return $query
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', ($asOf ?? Carbon::today())->toDateString())
            ->open();
    }

    /**
     * Due on one exact day, and still owed. The Due today bucket, as a query.
     *
     * Open-only for the same reason overdue is: a task somebody finished this morning is not
     * something they have to do today. It reads the same as-of date overdue does, so the two
     * buckets can never disagree about where midnight is.
     *
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    public function scopeDueOn(Builder $query, ?Carbon $asOf = null): Builder
    {
        return $query
            ->whereDate('due_date', ($asOf ?? Carbon::today())->toDateString())
            ->open();
    }

    /**
     * Completed on one exact day — the Company dashboard's "Completed today".
     *
     * `completed_at` is cleared by a reopen, which is the behaviour this wants: a task that
     * was finished this morning and reopened this afternoon is not a thing finished today.
     *
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    public function scopeCompletedOn(Builder $query, ?Carbon $asOf = null): Builder
    {
        return $query
            ->where('status', TaskStatus::Completed->value)
            ->whereDate('completed_at', ($asOf ?? Carbon::today())->toDateString());
    }

    /**
     * The tasks the user may see at all (TaskPolicy::view, as a query), mirroring
     * Project::visibleTo() exactly — same shape, same order of checks, same "no permission
     * means no rows" ending, so a list endpoint never filters the result afterwards.
     *
     * The difference from a project is the last line: an Admin or Manager sees every task,
     * but an employee sees the tasks ASSIGNED to them, not every task on a project they
     * happen to be a member of.
     *
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if (! $user->isActive() || ! $user->hasPermission(Permission::TasksView)) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->hasRole(RoleName::ADMIN, RoleName::MANAGER)) {
            return $query;
        }

        $employee = $user->employee;

        return $employee === null
            ? $query->whereRaw('1 = 0')
            : $query->forEmployee($employee);
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * The one assignee whose work summary completes this task, or null when nobody is assigned.
     *
     * Reads the loaded relation when there is one, so a list does not become a query per row.
     */
    public function primary(): ?Employee
    {
        $assignees = $this->relationLoaded('assignees')
            ? $this->assignees
            : $this->assignees()->get();

        return $assignees->first(fn (Employee $employee): bool => (bool) $employee->pivot?->is_primary);
    }

    /**
     * Has this task ever been completed? True even while it is reopened and back in progress —
     * that is the whole point of the first_completed_* columns.
     */
    public function wasEverCompleted(): bool
    {
        return $this->first_completed_at !== null || $this->completed_at !== null;
    }

    /**
     * Whether this task is overdue right now. The query-time scope above is the one that
     * matters for lists; this is for a single loaded task.
     */
    public function isOverdue(?Carbon $asOf = null): bool
    {
        if ($this->due_date === null || $this->status === null || ! $this->status->isOpen()) {
            return false;
        }

        return $this->due_date->lt(($asOf ?? Carbon::today())->startOfDay());
    }
}
