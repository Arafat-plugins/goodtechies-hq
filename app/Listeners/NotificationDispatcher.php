<?php

namespace App\Listeners;

use App\Events\ProjectCancelled;
use App\Events\TaskAssigned;
use App\Events\TaskBecameOverdue;
use App\Events\TaskCommented;
use App\Events\TaskCompleted;
use App\Events\TaskDeleted;
use App\Events\TaskDueTomorrow;
use App\Events\TaskReassigned;
use App\Events\TaskStatusChanged;
use App\Events\TaskSubmittedForReview;
use App\Models\Employee;
use App\Models\Message;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\TaskService;
use App\Support\NotificationType;
use App\Support\RoleName;
use App\Support\UserStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * The one listener: it turns an event into "these people should hear about this" and hands the
 * rest to NotificationService.
 *
 * ## Why there is exactly one of these
 *
 * Recipient rules are the part of a notification system that rots. Put "and copy the PM" in the
 * handler for one event and it is not in the handler for the next; put it in a service and the
 * rule is somewhere nobody looks. One class, one method per event, and every rule in this
 * application's notifications readable top to bottom in one file.
 *
 * ## Recipients come from policy-adjacent facts, never from role names
 *
 * Two filters run on every candidate list and both have to pass:
 *
 *   1. **The type-shaped half**, in NotificationService: the person holds the permission
 *      `NotificationType::requires()` names. The Accountant holds no `tasks.*` key and so
 *      receives no task notification — proved by permission, with their role named nowhere.
 *   2. **The object-shaped half**, here: `Gate::allows('view', $task)`. Being on somebody's
 *      candidate list is not enough; they have to be able to see the thing the notification is
 *      about. An employee who is not an assignee cannot see the task, and so is not told about
 *      it — the same 404-shaped rule the list endpoints enforce, applied to the mail.
 *
 * The one deliberate exception is a person REMOVED from a task, and it is marked where it
 * happens.
 *
 * ## It never writes anything but notifications
 *
 * Nothing in here touches a task, a project or a conversation. A status still moves only through
 * TaskService::transition(); this class reads.
 */
class NotificationDispatcher
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly TaskService $tasks,
    ) {}

    /**
     * Every event this class answers, and the method that answers it. Registered in
     * AppServiceProvider with Event::subscribe(), so this list is the registration — there is
     * no second place that says which events produce notifications.
     *
     * @return array<class-string, string>
     */
    public function subscribe(): array
    {
        return [
            TaskAssigned::class => 'onTaskAssigned',
            TaskReassigned::class => 'onTaskReassigned',
            TaskStatusChanged::class => 'onTaskStatusChanged',
            TaskCommented::class => 'onTaskCommented',
            TaskSubmittedForReview::class => 'onTaskSubmittedForReview',
            TaskCompleted::class => 'onTaskCompleted',
            TaskDeleted::class => 'onTaskDeleted',
            TaskBecameOverdue::class => 'onTaskBecameOverdue',
            TaskDueTomorrow::class => 'onTaskDueTomorrow',
            ProjectCancelled::class => 'onProjectCancelled',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | The seven task events
    |--------------------------------------------------------------------------
    */

    /**
     * Assigned: the people it was given to.
     */
    public function onTaskAssigned(TaskAssigned $event): void
    {
        $this->notifications->notify(
            NotificationType::TaskAssigned,
            $event->task,
            $this->canSee($event->task, $this->usersOfEmployees($event->assigneeIds)),
            $this->taskPayload($event->task),
            $event->actor,
        );
    }

    /**
     * Reassigned: everybody whose relationship to the task changed — added, removed, or newly
     * carrying (or newly relieved of) the primary's responsibility for completing it.
     *
     * **The removed person is told without the `view` gate**, and that is the one exception in
     * this class. It is not a hole: they were an assignee at the moment this event describes, so
     * they could see the task a second ago, and the payload carries nothing but its title. The
     * alternative is that the one person who most needs to know they are off a task is the only
     * one who is never told — because the fact that would have let them be told is precisely the
     * fact that just changed.
     */
    public function onTaskReassigned(TaskReassigned $event): void
    {
        $previous = array_map('intval', $event->previousAssigneeIds);
        $current = array_map('intval', $event->currentAssigneeIds);

        $removed = $this->usersOfEmployees(array_values(array_diff($previous, $current)));

        // Added, plus a primary that moved between two people who were both already on the
        // task — that is a hand-off, and both of them need to know.
        $handedOver = [];

        if ($event->previousPrimaryId !== $event->currentPrimaryId) {
            foreach ([$event->previousPrimaryId, $event->currentPrimaryId] as $id) {
                if ($id !== null && in_array((int) $id, $current, true)) {
                    $handedOver[] = (int) $id;
                }
            }
        }

        $stillOn = array_values(array_unique(array_merge(
            array_values(array_diff($current, $previous)),
            $handedOver,
        )));

        $this->notifications->notify(
            NotificationType::TaskReassigned,
            $event->task,
            $this->canSee($event->task, $this->usersOfEmployees($stillOn))->merge($removed),
            $this->taskPayload($event->task),
            $event->actor,
        );
    }

    /**
     * Any move that is not a review submission or a completion — those two have their own
     * events, so nobody is told the same thing twice. See App\Events\TaskStatusChanged.
     *
     * The people who care are the ones doing the work and the one who asked for it.
     */
    public function onTaskStatusChanged(TaskStatusChanged $event): void
    {
        $this->notifications->notify(
            NotificationType::TaskStatusChanged,
            $event->task,
            $this->canSee($event->task, $this->assigneesAndCreator($event->task)),
            $this->taskPayload($event->task, [
                'from' => $event->from->value,
                'from_label' => $event->from->label(),
                'to' => $event->to->value,
                'to_label' => $event->to->label(),
                'reason' => $event->reason,
            ]),
            $event->actor,
        );
    }

    /**
     * A comment: the people working on the task, the person who asked for it, and anybody
     * already talking in the thread.
     *
     * Prior authors are included and then gate-filtered like everyone else, so a Manager who
     * commented last week keeps hearing the answer, and somebody who has since lost access to
     * the task does not.
     */
    public function onTaskCommented(TaskCommented $event): void
    {
        $priorAuthorIds = Message::query()
            ->where('conversation_id', $event->message->conversation_id)
            ->distinct()
            ->pluck('author_id')
            ->map('intval')
            ->all();

        $candidates = $this->assigneesAndCreator($event->task)
            ->merge($this->usersByIds($priorAuthorIds));

        $this->notifications->notify(
            NotificationType::TaskCommented,
            $event->task,
            $this->canSee($event->task, $candidates),
            $this->taskPayload($event->task, [
                'message_id' => (int) $event->message->getKey(),
            ]),
            $event->actor,
        );
    }

    /**
     * Submitted for review: the reviewer, as TaskService::reviewersFor() defines them — the
     * project's PM, or every Admin when it has none. Asked for, never restated.
     */
    public function onTaskSubmittedForReview(TaskSubmittedForReview $event): void
    {
        $this->notifications->notify(
            NotificationType::TaskSubmittedForReview,
            $event->task,
            $this->canSee($event->task, $this->tasks->reviewersFor($event->task)),
            $this->taskPayload($event->task),
            $event->actor,
        );
    }

    /**
     * Completed: the spec's "original assigner/reviewer" — `created_by` and the reviewers —
     * **plus the assignees**, which the spec's recipient list leaves out.
     *
     * That addition is deliberate and it settles a contradiction inside the plan itself. The
     * Phase 2 Backend paragraph lists `TaskCompleted` → `created_by` + reviewer; the same
     * phase's "Done when" sentence says *"Shahadat requests changes then approves;
     * notifications appear for each step."* On the seeded team those two people are usually one
     * person, and they are the actor, who is always dropped — so an approval wrote **zero**
     * rows and the one person who did the work was never told it was accepted. Found by walking
     * the acceptance at the Phase 2 close-out, not by a test.
     *
     * The acceptance sentence wins over the recipient list: it is what "done" means, and the
     * list reads as an implementation note written before anybody walked the flow. Nobody
     * learns anything they could not already see — an assignee can open the task and read its
     * status — so this widens who is told, not what is known.
     */
    public function onTaskCompleted(TaskCompleted $event): void
    {
        $candidates = $this->usersByIds([(int) $event->task->created_by])
            ->merge($this->tasks->reviewersFor($event->task))
            ->merge($this->assignees($event->task));

        $this->notifications->notify(
            NotificationType::TaskCompleted,
            $event->task,
            $this->canSee($event->task, $candidates),
            $this->taskPayload($event->task),
            $event->actor,
        );
    }

    /**
     * Deleted: the people who were working on it and the person who asked for it. The delete is
     * soft, so the task and its assignee rows are all still there to be asked about.
     */
    public function onTaskDeleted(TaskDeleted $event): void
    {
        $this->notifications->notify(
            NotificationType::TaskDeleted,
            $event->task,
            $this->canSee($event->task, $this->assigneesAndCreator($event->task)),
            $this->taskPayload($event->task),
            $event->actor,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | The two side effects
    |--------------------------------------------------------------------------
    */

    /**
     * Overdue: "assignee + manager/Admin" (spec §19).
     *
     * The manager is `employees.manager_id` — the person who actually answers for this
     * employee's work — and every Admin stands in when an employee has none, which is the same
     * shape TaskReviewers uses for a project with no PM. There is no actor: a date passed.
     */
    public function onTaskBecameOverdue(TaskBecameOverdue $event): void
    {
        $assignees = $event->task->assignees()->with(['user', 'manager.user'])->get();

        $candidates = new Collection;

        foreach ($assignees as $assignee) {
            $candidates = $candidates->push($assignee->user);

            $manager = $assignee->manager?->user;

            $candidates = $manager !== null
                ? $candidates->push($manager)
                : $candidates->merge($this->admins());
        }

        // Nobody is assigned: an overdue task with no owner is exactly the thing an Admin has
        // to see, so it goes to all of them rather than nowhere.
        if ($assignees->isEmpty()) {
            $candidates = $this->admins();
        }

        $this->notifications->notify(
            NotificationType::TaskOverdue,
            $event->task,
            $this->canSee($event->task, $candidates),
            $this->taskPayload($event->task, [
                'due_date' => $event->task->due_date?->toDateString(),
                'as_of' => $event->asOf->toDateString(),
            ]),
        );
    }

    /**
     * Due tomorrow: "notify assignee" (spec §19), and nobody else.
     *
     * The difference from overdue one method up is not an oversight. Overdue says "assignee +
     * manager/admin" because lateness is somebody else's problem too; due tomorrow says
     * "assignee" because it is a heads-up to the person holding the work, and a manager told
     * every night about every task anybody has due tomorrow stops reading the bell. So there is
     * no escalation and no fallback to the Admins: an unassigned task due tomorrow tells nobody,
     * and the Due-today and Overdue buckets are what surface it the morning after.
     *
     * No actor, like every date-driven rule.
     */
    public function onTaskDueTomorrow(TaskDueTomorrow $event): void
    {
        $assignees = $event->task->assignees()->with('user')->get()
            ->map(fn (Employee $employee): ?User => $employee->user)
            ->filter();

        $this->notifications->notify(
            NotificationType::TaskDueTomorrow,
            $event->task,
            $this->canSee($event->task, new Collection($assignees->all())),
            $this->taskPayload($event->task, [
                'due_date' => $event->task->due_date?->toDateString(),
                'as_of' => $event->asOf->toDateString(),
            ]),
        );
    }

    /**
     * Cancelled project: the prompt to bulk-close or reassign what is left open (spec §21).
     *
     * It goes to the people who can actually answer it — the project's PM and every Admin —
     * filtered by `update` on the project rather than `view`, because this notification is a
     * request to act and not a piece of news.
     */
    public function onProjectCancelled(ProjectCancelled $event): void
    {
        if ($event->openTaskIds === []) {
            return;
        }

        $pm = $event->project->pm?->user;

        $candidates = $this->admins();

        if ($pm !== null) {
            $candidates = $candidates->push($pm);
        }

        $this->notifications->notify(
            NotificationType::ProjectCancelled,
            $event->project,
            $this->canAct($event->project, $candidates),
            [
                'title' => (string) $event->project->name,
                'context' => [
                    'project_id' => (int) $event->project->getKey(),
                    'open_task_count' => count($event->openTaskIds),
                    'open_task_ids' => $event->openTaskIds,
                ],
            ],
            $event->actor,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Candidate lists
    |--------------------------------------------------------------------------
    */

    /**
     * The people doing the work and the person who asked for it.
     *
     * @return Collection<int, User>
     */
    private function assigneesAndCreator(Task $task): Collection
    {
        return $this->assignees($task)
            ->merge($this->usersByIds([(int) $task->created_by]));
    }

    /**
     * The people doing the work, as users.
     *
     * @return Collection<int, User>
     */
    private function assignees(Task $task): Collection
    {
        $users = $task->assignees()->with('user')->get()
            ->map(fn (Employee $employee): ?User => $employee->user)
            ->filter();

        return new Collection($users->all());
    }

    /**
     * Users behind a list of `employees.id`.
     *
     * @param  list<int>  $employeeIds
     * @return Collection<int, User>
     */
    private function usersOfEmployees(array $employeeIds): Collection
    {
        $employeeIds = array_values(array_filter(array_map('intval', $employeeIds)));

        if ($employeeIds === []) {
            return new Collection;
        }

        return new Collection(Employee::query()
            ->with('user')
            ->whereKey($employeeIds)
            ->get()
            ->map(fn (Employee $employee): ?User => $employee->user)
            ->filter()
            ->all());
    }

    /**
     * @param  list<int>  $userIds
     * @return Collection<int, User>
     */
    private function usersByIds(array $userIds): Collection
    {
        $userIds = array_values(array_filter(array_map('intval', $userIds)));

        if ($userIds === []) {
            return new Collection;
        }

        return new Collection(User::query()->whereKey($userIds)->get()->all());
    }

    /**
     * Every active Admin.
     *
     * Not TaskReviewers': that class answers "who may pass a verdict on THIS task", which is
     * the PM before it is ever the Admins. This answers "who runs the agency", which is the
     * right fallback for an unassigned overdue task and for a cancelled project, and would be
     * the wrong answer to the review question.
     *
     * @return Collection<int, User>
     */
    private function admins(): Collection
    {
        return new Collection(User::query()
            ->where('status', UserStatus::Active->value)
            ->whereHas('employee.role', fn (Builder $role) => $role->where('name', RoleName::ADMIN->value))
            ->get()
            ->all());
    }

    /*
    |--------------------------------------------------------------------------
    | The object-shaped half of the rule
    |--------------------------------------------------------------------------
    */

    /**
     * Keep the candidates who can see this task. The per-object half — see the class docblock.
     *
     * @param  Collection<int, User>  $candidates
     * @return Collection<int, User>
     */
    private function canSee(Task $task, Collection $candidates): Collection
    {
        return $candidates
            ->filter(fn (mixed $user): bool => $user instanceof User)
            ->unique(fn (User $user): int => (int) $user->getKey())
            ->filter(fn (User $user): bool => Gate::forUser($user)->allows('view', $task))
            ->values();
    }

    /**
     * Keep the candidates who could actually do something about this project.
     *
     * @param  Collection<int, User>  $candidates
     * @return Collection<int, User>
     */
    private function canAct(Project $project, Collection $candidates): Collection
    {
        return $candidates
            ->filter(fn (mixed $user): bool => $user instanceof User)
            ->unique(fn (User $user): int => (int) $user->getKey())
            ->filter(fn (User $user): bool => Gate::forUser($user)->allows('update', $project))
            ->values();
    }

    /**
     * What every task notification stores: the title, and whatever the event adds.
     *
     * The title and nothing else about the task. A payload is written once and read by one
     * person later, so it must not carry anything whose visibility could change in between —
     * which is also why the deep link is built at read time and not stored.
     *
     * @param  array<string, mixed>  $context
     * @return array{title: string, context: array<string, mixed>}
     */
    private function taskPayload(Task $task, array $context = []): array
    {
        return [
            'title' => (string) $task->title,
            'context' => array_filter(
                $context + ['project_id' => $task->project_id],
                fn (mixed $value): bool => $value !== null,
            ),
        ];
    }
}
