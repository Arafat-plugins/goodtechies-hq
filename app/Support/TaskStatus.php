<?php

namespace App\Support;

enum TaskStatus: string
{
    /**
     * from => the statuses it may move to.
     *
     * Read down the first five rows and you get the spec's happy path:
     * BACKLOG → TO DO → IN PROGRESS → IN REVIEW → COMPLETED. Everything else is the
     * exits from it — blocked, rejected, cancelled — plus the two reopenings.
     *
     * Archiving is NOT a transition (it is orthogonal to status, like a project's), and
     * neither is deleting.
     *
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        'backlog' => ['todo', 'waiting', 'cancelled'],
        'todo' => ['in_progress', 'backlog', 'waiting', 'cancelled'],
        'in_progress' => ['in_review', 'todo', 'waiting', 'cancelled'],
        // The two review verdicts, plus the assignee pulling their own work back out.
        'in_review' => ['completed', 'changes_requested', 'in_progress', 'cancelled'],
        'changes_requested' => ['in_progress', 'waiting', 'cancelled'],
        'waiting' => ['backlog', 'todo', 'in_progress', 'cancelled'],
        // Reopening. Admin only — see mayRoleTransition().
        'completed' => ['in_progress'],
        'cancelled' => ['backlog', 'todo'],
    ];

    case Backlog = 'backlog';
    case Todo = 'todo';
    case InProgress = 'in_progress';
    case InReview = 'in_review';
    case ChangesRequested = 'changes_requested';
    case Waiting = 'waiting';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Backlog => 'Backlog',
            self::Todo => 'To do',
            self::InProgress => 'In progress',
            self::InReview => 'In review',
            self::ChangesRequested => 'Changes requested',
            self::Waiting => 'Waiting / Blocked',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * The StatusBadge tone this status paints as — the `StatusKey` union in
     * `Components/StatusBadge.vue`. It is a separate concept from the value on purpose:
     * the badge has eight tones and the enum has eight cases, but nothing guarantees a
     * future ninth status gets a ninth colour, and the mapping is the place that would
     * have to answer for it.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Backlog => 'backlog',
            self::Todo => 'todo',
            self::InProgress => 'progress',
            self::InReview => 'review',
            self::ChangesRequested => 'changes',
            self::Waiting => 'waiting',
            self::Completed => 'done',
            self::Cancelled => 'cancelled',
        };
    }

    /**
     * The statuses in the order the board draws its columns and the List view stacks its
     * groups. Cases are already declared in this order; this is the explicit promise of it.
     *
     * @return list<self>
     */
    public static function boardOrder(): array
    {
        return self::cases();
    }

    /**
     * The statuses a task is NOT overdue in, whatever its due date says.
     *
     * Overdue is `due_date < today AND status NOT IN (these)`, computed at query time and
     * never stored — see TaskService::overdue().
     *
     * @return list<self>
     */
    public static function closed(): array
    {
        return [self::Completed, self::Cancelled];
    }

    /** A task still on somebody's plate. */
    public function isOpen(): bool
    {
        return ! in_array($this, self::closed(), true);
    }

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return array_map(
            fn (string $value): self => self::from($value),
            self::TRANSITIONS[$this->value],
        );
    }

    /**
     * Is this a legal move at all, for anyone? Role is a separate question.
     */
    public function canTransitionTo(self $to): bool
    {
        return in_array($to->value, self::TRANSITIONS[$this->value], true);
    }

    /**
     * May this ROLE make this transition?
     *
     * This answers the role half only. The project-scoped half — "the reviewer is the
     * project's PM, and if there is none then every Admin", and "an employee only moves a
     * task they are assigned to" — is a fact about a particular task and lives in
     * TaskPolicy. Both halves must pass; neither is sufficient alone.
     *
     * An illegal transition is refused for every role, including Admin: a role escalates
     * who may make a move, never which moves exist.
     */
    public function mayRoleTransition(RoleName $role, self $to): bool
    {
        if (! $this->canTransitionTo($to)) {
            return false;
        }

        return in_array($role, $this->rolesFor($to), true);
    }

    /**
     * The roles allowed to make one specific legal transition, most-privileged first.
     *
     * @return list<RoleName>
     */
    private function rolesFor(self $to): array
    {
        $admin = [RoleName::ADMIN];
        $managers = [RoleName::ADMIN, RoleName::MANAGER];
        $workers = [RoleName::ADMIN, RoleName::MANAGER, RoleName::EMPLOYEE, RoleName::REMOTE_EMPLOYEE];

        // Reopening a finished task, or restoring a cancelled one, is an Admin move — the
        // same rule ProjectService applies to a project.
        if ($this === self::Completed || $this === self::Cancelled) {
            return $admin;
        }

        // Cancelling is not work, it is a decision about whether the work happens.
        if ($to === self::Cancelled) {
            return $managers;
        }

        // The review verdicts. Passing or rejecting your own work is the thing the reviewer
        // rule exists to prevent, so no employee role appears here at all.
        if ($this === self::InReview && in_array($to, [self::Completed, self::ChangesRequested], true)) {
            return $managers;
        }

        // Everything else is ordinary work on the board. The Accountant appears in no list
        // above, so there is no transition, legal or otherwise, that they may make.
        return $workers;
    }
}
