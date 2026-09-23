<?php

namespace App\Support;

use App\Models\Task;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * A bucket is a QUESTION a task list is asked — "what is late", "what is due today", "what is
 * waiting on a reviewer" — as opposed to a column value, which is what the `status` filter is.
 *
 * It exists because a count and the screen that count links to have to mean the same thing. A
 * card reading "Overdue 4" that leads to a list built from a slightly different predicate is a
 * card that will one day show five rows, and nobody will be able to say which number was
 * wrong. The card and the destination both name the bucket, and apply() below is the only
 * place any bucket is spelled out.
 *
 * Nothing here knows WHOSE tasks are being counted. The scope is `Task::visibleTo()`, plus the
 * `mine` filter on the My Tasks page; a bucket narrows by state and date and by nothing else.
 * That is what lets the same eight definitions answer an employee's plate and the agency's.
 */
enum TaskBucket: string
{
    /** Everything still owed. The My Tasks page's umbrella bucket. */
    case Open = 'open';

    case DueToday = 'due_today';
    case Overdue = 'overdue';
    case InProgress = 'in_progress';
    case Waiting = 'waiting';
    case InReview = 'in_review';
    case Completed = 'completed';

    /** The Company dashboard's "Completed today" — finished, and finished on the as-of day. */
    case CompletedToday = 'completed_today';

    /**
     * The seven the plan names for the My Tasks page, in the order it names them.
     *
     * @return list<self>
     */
    public static function myTasks(): array
    {
        return [
            self::Open,
            self::DueToday,
            self::Overdue,
            self::InProgress,
            self::Waiting,
            self::InReview,
            self::Completed,
        ];
    }

    /**
     * The four task questions the Company dashboard asks. "Active projects", the fifth card,
     * is not a task question at all and is `ProjectService`'s to answer.
     *
     * @return list<self>
     */
    public static function companyDashboard(): array
    {
        return [self::DueToday, self::Overdue, self::InReview, self::CompletedToday];
    }

    /**
     * What this bucket is called as a FILTER — on the Tasks List's chip bar, where the rows
     * are the agency's and "open" means open.
     */
    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::DueToday => 'Due today',
            self::Overdue => 'Overdue',
            self::InProgress => 'In progress',
            self::Waiting => 'Waiting',
            self::InReview => 'In review',
            self::Completed => 'Completed',
            self::CompletedToday => 'Completed today',
        };
    }

    /**
     * What this bucket is called as a CARD on a screen that is already about one person's
     * plate — the My Tasks page and the employee dashboard.
     *
     * Only one of the eight differs, and it is the one the plan itself renames: the umbrella
     * bucket is the filter `open` and the card "My tasks". Two methods rather than one label
     * doing double duty, because "Bucket: My tasks" on the agency-wide Tasks List would be a
     * chip claiming something about the reader that the query does not say.
     */
    public function cardLabel(): string
    {
        return $this === self::Open ? 'My tasks' : $this->label();
    }

    /**
     * The predicate — the one statement of what each bucket means.
     *
     * Every arm is a scope on the model, so the definitions sit beside the columns they read
     * and a second copy cannot appear in a controller or a Vue computed. Overdue in
     * particular is `Task::scopeOverdue()` and nothing else: the spec's `due_date < today and
     * status not in {COMPLETED, CANCELLED}`, computed at query time.
     *
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    public function apply(Builder $query, Carbon $asOf): Builder
    {
        return match ($this) {
            self::Open => $query->open(),
            self::DueToday => $query->dueOn($asOf),
            self::Overdue => $query->overdue($asOf),
            self::CompletedToday => $query->completedOn($asOf),
            // The four that are exactly one status. They go through the bucket vocabulary
            // rather than the `status` filter so that every card on a bucket screen links in
            // one vocabulary — a strip where six cards say `?bucket=` and two say `?status=`
            // is a strip somebody will eventually get wrong.
            default => $query->where('status', $this->status()?->value),
        };
    }

    /**
     * The status this bucket is, when it is exactly one — and null when it is a question the
     * status column cannot answer on its own.
     */
    public function status(): ?TaskStatus
    {
        return match ($this) {
            self::InProgress => TaskStatus::InProgress,
            self::Waiting => TaskStatus::Waiting,
            self::InReview => TaskStatus::InReview,
            self::Completed, self::CompletedToday => TaskStatus::Completed,
            default => null,
        };
    }
}
