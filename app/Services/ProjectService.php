<?php

namespace App\Services;

use App\Events\ProjectCancelled;
use App\Exceptions\ProjectStateException;
use App\Models\Conversation;
use App\Models\Employee;
use App\Models\File;
use App\Models\Message;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Support\AuditEvent;
use App\Support\BillingType;
use App\Support\ProjectRecurrenceFrequency;
use App\Support\ProjectStatus;
use App\Support\RoleName;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Creating and running a project: members, status lifecycle, archiving.
 *
 * Money never passes through here — it is handed to ProjectFinanceService, which enforces its
 * own permission, so a caller who may edit a project cannot change its price by the back door.
 */
class ProjectService
{
    /**
     * The project attributes a caller may write. Finance fields are deliberately absent, and so
     * is `status`: it belongs to changeStatus(), archive() and unarchive(), so a `status` key
     * handed to create() or update() is dropped here rather than quietly moving the column.
     */
    private const FIELDS = [
        'client_id',
        'name',
        'domain',
        'project_type',
        'billing_type',
        'recurrence_frequency',
        'start_date',
        'deadline',
        'priority',
        'pm_id',
        'internal_notes',
        'employee_notes',
    ];

    /**
     * from => the statuses it may move to. Archiving is not a transition: use archive().
     *
     * @var array<string, list<string>>
     */
    private const TRANSITIONS = [
        'active' => ['on_hold', 'completed', 'cancelled'],
        'on_hold' => ['active', 'completed', 'cancelled'],
        'completed' => ['cancelled', 'active'],
        'cancelled' => ['active'],
    ];

    public function __construct(
        private readonly ProjectFinanceService $finance,
        private readonly AuditLogger $audit,
        private readonly ActivityLogger $activity,
        private readonly ConversationService $conversations,
    ) {}

    /**
     * How many Active projects this user may see — the Company dashboard's fifth task card.
     *
     * Scoped through `Project::visibleTo()` like every other project list, and counted in SQL
     * rather than by fetching rows. The two predicates are the ones `/admin/projects?status=
     * active` applies, in the same order, so the card's number and the list it opens are the
     * same set: `notArchived()` is that list's default, and a project is not Active once it
     * has been archived anyway.
     */
    public function activeCount(User $actor): int
    {
        return Project::query()
            ->visibleTo($actor)
            ->where('status', ProjectStatus::Active->value)
            ->notArchived()
            ->count();
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<int>  $memberIds
     * @param  array<string, mixed>|null  $finance
     *
     * @throws AuthorizationException
     */
    public function create(User $actor, array $attributes, array $memberIds = [], ?array $finance = null): Project
    {
        if (! Gate::forUser($actor)->allows('create', Project::class)) {
            throw new AuthorizationException('You are not allowed to create projects.');
        }

        return DB::transaction(function () use ($actor, $attributes, $memberIds, $finance): Project {
            // A project is born Active and is moved on by changeStatus(): the caller does not
            // get to pick the status it starts in.
            $project = Project::create(
                $this->withRecurrence($attributes) + ['status' => ProjectStatus::Active],
            );

            if ($memberIds !== []) {
                $project->members()->sync($this->memberPayload($memberIds));
            }

            if ($finance !== null) {
                // Throws if the actor may not write money; the whole creation rolls back.
                $this->finance->upsert($actor, $project, $finance);
            }

            // The project's channel, born with the project (Phase 6). Inside the same
            // transaction as the project itself, so there is no moment at which a project
            // exists without somewhere to talk about it — the same promise TaskService makes
            // for a task's discussion, and the reason the Phase 6 backfill only ever has to
            // deal with projects that predate this line.
            //
            // Who may read it is not decided here and nothing is synced: ConversationPolicy
            // asks ProjectPolicy::view about this project on every request, so adding or
            // removing a member changes the channel's audience by changing the project's, with
            // no second list to keep in step (decision 2-24, generalised in Phase 6).
            $this->conversations->forProject($project);

            $this->audit->record(
                AuditEvent::ProjectCreated,
                $project,
                null,
                $this->auditableAttributes($project),
                $actor,
            );

            $this->activity->record($project, 'Project created', $actor);

            return $project->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws AuthorizationException
     * @throws ProjectStateException
     */
    public function update(User $actor, Project $project, array $attributes): Project
    {
        $this->guardWritable($actor, $project, 'update', 'edited');

        return DB::transaction(function () use ($actor, $project, $attributes): Project {
            $project->fill($this->withRecurrence($attributes, $project));

            $changed = array_keys($project->getDirty());

            if ($changed === []) {
                return $project;
            }

            $project->save();

            $this->activity->record(
                $project,
                'Project updated: '.implode(', ', $changed),
                $actor,
            );

            return $project->refresh();
        });
    }

    /**
     * @param  list<int>  $memberIds
     * @param  array<int, string|null>  $rolesById
     *
     * @throws AuthorizationException
     * @throws ProjectStateException
     */
    public function syncMembers(User $actor, Project $project, array $memberIds, array $rolesById = []): void
    {
        $this->guardWritable($actor, $project, 'manageMembers', 'given members');

        DB::transaction(function () use ($actor, $project, $memberIds, $rolesById): void {
            $before = $project->members()->pluck('employees.id')->all();

            $changes = $project->members()->sync($this->memberPayload($memberIds, $rolesById));

            $added = array_map('intval', $changes['attached']);
            $removed = array_map('intval', $changes['detached']);

            $names = $this->employeeNames(array_merge($before, $added, $removed));

            foreach ($added as $id) {
                $this->activity->record($project, 'Member added: '.($names[$id] ?? "#{$id}"), $actor);
            }

            foreach ($removed as $id) {
                $this->activity->record($project, 'Member removed: '.($names[$id] ?? "#{$id}"), $actor);
            }
        });
    }

    /**
     * Move the project along its lifecycle. Archiving is not a status change: use archive().
     *
     * @throws AuthorizationException
     * @throws ProjectStateException
     */
    public function changeStatus(User $actor, Project $project, ProjectStatus $to, ?string $reason = null): Project
    {
        if ($project->isArchived()) {
            throw ProjectStateException::archived('moved to another status');
        }

        $from = $project->status;

        if ($from === null || ! in_array($to->value, self::TRANSITIONS[$from->value] ?? [], true)) {
            throw ProjectStateException::transition($from ?? ProjectStatus::Active, $to);
        }

        $this->authoriseTransition($actor, $project, $from, $to);

        return DB::transaction(function () use ($actor, $project, $from, $to, $reason): Project {
            $project->status = $to;
            $project->save();

            $line = sprintf('Status changed from %s to %s', $from->label(), $to->label());

            $this->activity->record(
                $project,
                $reason === null ? $line : $line.' — '.$reason,
                $actor,
            );

            if ($to === ProjectStatus::Cancelled) {
                $this->promptToCloseOpenTasks($actor, $project);
            }

            return $project->refresh();
        });
    }

    /**
     * @throws AuthorizationException
     * @throws ProjectStateException
     */
    public function archive(User $actor, Project $project): Project
    {
        if ($project->isArchived()) {
            throw ProjectStateException::alreadyArchived();
        }

        if (! Gate::forUser($actor)->allows('archive', $project)) {
            throw new AuthorizationException('You are not allowed to archive this project.');
        }

        return DB::transaction(function () use ($actor, $project): Project {
            $previous = $project->status;

            $project->forceFill([
                'status' => ProjectStatus::Archived,
                'archived_at' => now(),
            ])->save();

            $this->activity->record(
                $project,
                sprintf('Project archived (was %s)', $previous?->label() ?? 'unknown'),
                $actor,
            );

            return $project->refresh();
        });
    }

    /**
     * @throws AuthorizationException
     * @throws ProjectStateException
     */
    public function unarchive(User $actor, Project $project): Project
    {
        if (! $project->isArchived()) {
            throw ProjectStateException::notArchived();
        }

        if (! Gate::forUser($actor)->allows('unarchive', $project)) {
            throw new AuthorizationException('You are not allowed to unarchive this project.');
        }

        return DB::transaction(function () use ($actor, $project): Project {
            $project->forceFill([
                'status' => ProjectStatus::Active,
                'archived_at' => null,
            ])->save();

            $this->activity->record($project, 'Project unarchived', $actor);

            return $project->refresh();
        });
    }

    /**
     * Delete an archived project for good: its tasks, files, time, members, finance and
     * channels go with it through the database's cascades, and the bytes of every file those
     * cascades take are removed from disk once the delete has committed.
     *
     * Refused while money or paid time hangs off the project — income RESTRICTS the delete at
     * the database anyway, and paid time is what payroll was computed from, so both are kept by
     * keeping the project archived instead.
     *
     * @throws AuthorizationException
     * @throws ProjectStateException
     */
    public function forceDelete(User $actor, Project $project, string $confirmName): void
    {
        if (! $project->isArchived()) {
            throw ProjectStateException::deleteNeedsArchive();
        }

        if (trim($confirmName) !== $project->name) {
            throw ProjectStateException::deleteNameMismatch();
        }

        if (DB::table('income')->where('project_id', $project->getKey())->exists()) {
            throw ProjectStateException::deleteHasIncome();
        }

        if (DB::table('time_entries')
            ->where('project_id', $project->getKey())
            ->where('counts_toward_hours', true)
            ->exists()) {
            throw ProjectStateException::deleteHasPaidTime();
        }

        if (! Gate::forUser($actor)->allows('forceDelete', $project)) {
            throw new AuthorizationException('You are not allowed to delete this project.');
        }

        /** @var list<array{disk: string, path: string}> $blobs */
        $blobs = DB::transaction(function () use ($actor, $project): array {
            $taskIds = Task::withTrashed()
                ->where('project_id', $project->getKey())
                ->pluck('id')
                ->all();

            $conversationIds = Conversation::query()
                ->where('linked_project_id', $project->getKey())
                ->when($taskIds !== [], fn ($query) => $query->orWhereIn('linked_task_id', $taskIds))
                ->pluck('id')
                ->all();

            $files = File::withTrashed()
                ->where(function ($query) use ($project, $taskIds, $conversationIds): void {
                    $query->where('project_id', $project->getKey());

                    if ($taskIds !== []) {
                        $query->orWhereIn('task_id', $taskIds);
                    }

                    if ($conversationIds !== []) {
                        $query->orWhereIn('message_id', Message::query()
                            ->whereIn('conversation_id', $conversationIds)
                            ->select('id'));
                    }
                })
                ->get(['id', 'disk', 'path']);

            $this->audit->record(
                AuditEvent::ProjectDeleted,
                $project,
                [
                    'id' => $project->getKey(),
                    'name' => $project->name,
                    'client' => $project->client?->name,
                    'tasks' => count($taskIds),
                    'files' => $files->count(),
                ],
                null,
                $actor,
            );

            $project->delete();

            return $files
                ->filter(fn (File $file): bool => $file->disk !== null && $file->path !== null)
                ->map(fn (File $file): array => ['disk' => (string) $file->disk, 'path' => (string) $file->path])
                ->values()
                ->all();
        });

        // After the commit: a delete that rolled back must not have lost any bytes. A blob that
        // is already gone (a file deleted earlier) is not an error.
        foreach ($blobs as $blob) {
            try {
                Storage::disk($blob['disk'])->delete($blob['path']);
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }

    /**
     * The Phase 1 side effect, made real (spec Part D §21: "Project cancelled → open tasks
     * prompted bulk-close/reassign").
     *
     * Phase 1 could only leave a note. It recorded an activity line ending "(Phase 2)" —
     * literally a comment in the timeline saying somebody would have to do this by hand —
     * because there was no engine to prompt anybody through and no tasks table to count. Both
     * exist now, so the line says what is actually outstanding and a High-priority System
     * notification goes to the people who can deal with it: the project's PM and every Admin.
     *
     * Three things it deliberately does NOT do:
     *
     *   - **It does not close the tasks.** The spec says "prompted", and it is right to: the
     *     tasks on a cancelled project are not all waste — some are owed to the client anyway,
     *     some belong on another project. Cancelling a project is a decision about the project.
     *     Cancelling twenty tasks is twenty decisions, and they are not this method's to make.
     *   - **It does not move a status.** A task's status moves through TaskService::transition()
     *     and the model's one door, and this class is not going to open a second one.
     *   - **It says nothing when there is nothing to say.** A project cancelled with no open
     *     tasks left leaves no line and sends no prompt — an empty to-do list is not news.
     */
    private function promptToCloseOpenTasks(User $actor, Project $project): void
    {
        // Every open task, whoever they belong to — not `visibleTo($actor)`. What a
        // cancellation leaves behind is a fact about the project, and an Admin and a PM
        // cancelling the same project must be told the same number.
        $openTaskIds = $project->tasks()
            ->open()
            ->notArchived()
            ->pluck('tasks.id')
            ->map('intval')
            ->all();

        if ($openTaskIds === []) {
            return;
        }

        $this->activity->record($project, sprintf(
            'Project cancelled — %d open %s must be closed or reassigned',
            count($openTaskIds),
            count($openTaskIds) === 1 ? 'task' : 'tasks',
        ), $actor);

        // Inside changeStatus()'s transaction, so a cancellation that rolls back prompts
        // nobody. NotificationDispatcher decides who hears it.
        event(new ProjectCancelled($project, $actor, $openTaskIds));
    }

    /**
     * Reopening a finished or cancelled project, and cancelling one, are Admin moves.
     *
     * @throws AuthorizationException
     */
    private function authoriseTransition(User $actor, Project $project, ProjectStatus $from, ProjectStatus $to): void
    {
        $gate = Gate::forUser($actor);

        if ($to === ProjectStatus::Cancelled) {
            if (! $gate->allows('cancel', $project)) {
                throw new AuthorizationException('You are not allowed to cancel this project.');
            }

            return;
        }

        if ($to === ProjectStatus::Active && ! $from->isOpen()) {
            if (! $gate->allows('update', $project) || ! $actor->hasRole(RoleName::ADMIN)) {
                throw new AuthorizationException('Only an administrator may reopen a finished or cancelled project.');
            }

            return;
        }

        if (! $gate->allows('update', $project)) {
            throw new AuthorizationException('You are not allowed to change this project\'s status.');
        }
    }

    /**
     * @throws AuthorizationException
     * @throws ProjectStateException
     */
    private function guardWritable(User $actor, Project $project, string $ability, string $action): void
    {
        if ($project->isArchived()) {
            throw ProjectStateException::archived($action);
        }

        if (! Gate::forUser($actor)->allows($ability, $project)) {
            throw new AuthorizationException('You are not allowed to change this project.');
        }
    }

    /**
     * @param  list<int>  $memberIds
     * @param  array<int, string|null>  $rolesById
     * @return array<int, array{role_on_project: string|null}>
     */
    private function memberPayload(array $memberIds, array $rolesById = []): array
    {
        $payload = [];

        foreach ($memberIds as $id) {
            $payload[(int) $id] = ['role_on_project' => $rolesById[$id] ?? null];
        }

        return $payload;
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, string>
     */
    private function employeeNames(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return Employee::with('user')
            ->whereIn('id', array_unique($ids))
            ->get()
            ->mapWithKeys(fn (Employee $employee): array => [
                $employee->id => $employee->user?->name ?? "#{$employee->id}",
            ])
            ->all();
    }

    /**
     * The writable attributes, with the server as the source of truth for recurrence: a
     * Recurring project gets its frequency and a deadline computed from the start date
     * (whatever deadline the caller sent is ignored); any other project has no frequency and
     * keeps the deadline it was given. Keys the caller left out fall back to $project's values.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function withRecurrence(array $attributes, ?Project $project = null): array
    {
        $attributes = array_intersect_key($attributes, array_flip(self::FIELDS));

        // An update that touches none of the inputs leaves the stored schedule as it is.
        $inputs = ['billing_type', 'recurrence_frequency', 'start_date', 'deadline'];

        if ($project !== null && array_intersect_key($attributes, array_flip($inputs)) === []) {
            return $attributes;
        }

        $billing = array_key_exists('billing_type', $attributes)
            ? $this->billingType($attributes['billing_type'])
            : $project?->billing_type;

        if ($billing !== BillingType::Recurring) {
            $attributes['recurrence_frequency'] = null;

            return $attributes;
        }

        $frequency = array_key_exists('recurrence_frequency', $attributes)
            ? $this->frequency($attributes['recurrence_frequency'])
            : $project?->recurrence_frequency;

        $start = array_key_exists('start_date', $attributes) ? $attributes['start_date'] : $project?->start_date;

        if ($frequency === null || $start === null || $start === '') {
            throw new \InvalidArgumentException('A recurring project needs a frequency and a start date.');
        }

        $start = $start instanceof DateTimeInterface ? Carbon::instance($start) : Carbon::parse((string) $start);

        $attributes['recurrence_frequency'] = $frequency;
        $attributes['deadline'] = $frequency->deadlineFrom($start)->toDateString();

        return $attributes;
    }

    private function billingType(mixed $value): ?BillingType
    {
        if ($value instanceof BillingType || $value === null) {
            return $value;
        }

        return BillingType::from((string) $value);
    }

    private function frequency(mixed $value): ?ProjectRecurrenceFrequency
    {
        if ($value instanceof ProjectRecurrenceFrequency || $value === null || $value === '') {
            return $value === '' ? null : $value;
        }

        return ProjectRecurrenceFrequency::from((string) $value);
    }

    /**
     * The project's own attributes for the audit trail. Finance is never included: a price
     * lands in audit_logs through ProjectPriceChanged only.
     *
     * @return array<string, mixed>
     */
    private function auditableAttributes(Project $project): array
    {
        return [
            'name' => $project->name,
            'client_id' => $project->client_id,
            'domain' => $project->domain,
            'project_type' => $project->project_type?->value,
            'billing_type' => $project->billing_type?->value,
            'recurrence_frequency' => $project->recurrence_frequency?->value,
            'status' => $project->status?->value,
            'priority' => $project->priority?->value,
            'pm_id' => $project->pm_id,
            'start_date' => $project->start_date?->toDateString(),
            'deadline' => $project->deadline?->toDateString(),
        ];
    }
}
