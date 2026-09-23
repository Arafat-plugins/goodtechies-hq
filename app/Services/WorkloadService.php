<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Support\TaskBucket;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Admin → Workforce → Workload (master prompt Part D §7, Phase 4).
 *
 * Four questions, and the plan's own words for the limit on them: *task count per employee,
 * overdue per employee, estimated vs tracked, projects with most pending work — **counts
 * only**.* An Admin opens it when deciding who can take the next job.
 *
 * ## Counts only, and why that is structural here
 *
 * Part H forbids productivity scoring outright, so this service produces no ratio, no
 * percentage, no variance and no order that depends on a total:
 *
 *   - **People are ordered by NAME.** Never by hours, never by how far a total sits from an
 *     estimate. An order chosen by a number is a league table whatever the column is called,
 *     and the first row of one reads as the winner.
 *   - **Estimated and tracked are two separate numbers.** They are never divided, subtracted or
 *     tinted. An estimate that has not been met may mean the work was harder, the estimate was
 *     optimistic, or the job changed — the screen does not get to pick one.
 *   - **`unestimated_count` travels with the estimate**, because a sum over a set where half the
 *     tasks carry no estimate is a smaller number that looks like less work. A reader who is
 *     not told that is being misled by an honest sum.
 *
 * Projects ARE ordered by their pending count, and that is not the same thing: the plan asks
 * for "projects with most pending work", a project is not a person, and nothing here attributes
 * a project's backlog to anybody.
 *
 * ## Every count is a query, scoped by the viewer
 *
 * The two per-employee counts go through `TaskService::count()` with `assignee_id` and a
 * `bucket`, which is exactly what `/admin/tasks?assignee_id=…&bucket=…` runs — so the number on
 * this screen and the list it describes cannot disagree. "Overdue" is `TaskBucket::Overdue` and
 * nothing else (decision 2-37); this file contains no second definition of it.
 *
 * The aggregates that `TaskService` has no shape for — the estimate and the tracked hours — are
 * built on the same base: `Task::visibleTo($viewer)`, not archived, narrowed by
 * `TaskBucket::Open->apply()`. Same scope, same bucket, same one definition.
 */
class WorkloadService
{
    public function __construct(private readonly TaskService $tasks) {}

    /**
     * The whole screen, for this viewer, as of this day.
     *
     * @return array<string, mixed>
     */
    public function forViewer(User $viewer, ?Carbon $asOf = null): array
    {
        $asOf ??= Carbon::today();

        $openTaskIds = $this->openTaskIds($viewer, $asOf);
        $employees = $this->employees($viewer);

        $estimates = $this->estimatesByEmployee($openTaskIds);
        $tracked = $this->trackedByEmployee($openTaskIds);

        $rows = $employees
            ->map(fn (Employee $employee): array => $this->employeeRow($viewer, $employee, $asOf, $estimates, $tracked))
            ->values()
            ->all();

        return [
            'as_of' => [
                'value' => $asOf->toDateString(),
                'label' => $asOf->isoFormat('dddd, D MMMM YYYY'),
            ],
            'employees' => $rows,
            'projects' => $this->projects($viewer, $asOf),
            'totals' => [
                // The agency's own two counts, from the same two buckets, so the strip at the
                // top and the rows below it are the same question asked twice.
                'open_count' => $this->tasks->count($viewer, ['bucket' => TaskBucket::Open->value, 'as_of' => $asOf]),
                'overdue_count' => $this->tasks->count($viewer, ['bucket' => TaskBucket::Overdue->value, 'as_of' => $asOf]),
                'employee_count' => count($rows),
            ],
        ];
    }

    /**
     * One person's line.
     *
     * @param  Collection<int, object>  $estimates
     * @param  Collection<int, object>  $tracked
     * @return array<string, mixed>
     */
    private function employeeRow(
        User $viewer,
        Employee $employee,
        Carbon $asOf,
        Collection $estimates,
        Collection $tracked,
    ): array {
        $id = (int) $employee->getKey();
        $estimate = $estimates->get($id);

        return [
            'id' => $id,
            'name' => $employee->user?->name ?? 'Unknown',
            'employee_number' => $employee->employee_number,
            'role' => $employee->role?->name,

            // The two counts, each the exact query the Tasks list runs under the same filter.
            'open_count' => $this->tasks->count($viewer, [
                'assignee_id' => $id,
                'bucket' => TaskBucket::Open->value,
                'as_of' => $asOf,
            ]),
            'overdue_count' => $this->tasks->count($viewer, [
                'assignee_id' => $id,
                'bucket' => TaskBucket::Overdue->value,
                'as_of' => $asOf,
            ]),

            // Estimated and tracked, side by side and never combined. Both are about the TASKS
            // — the estimate is a field on the task, so the hours beside it are the task's
            // hours, everybody's, not only this person's. Two people on one task therefore
            // share both figures, which is the truth about shared work rather than a division
            // of it that nobody agreed.
            'estimated_minutes' => (int) ($estimate->estimated_minutes ?? 0),
            'unestimated_count' => (int) ($estimate->unestimated_count ?? 0),
            'tracked_seconds' => (int) ($tracked->get($id)->tracked_seconds ?? 0),
        ];
    }

    /**
     * Whose workload this viewer may read: every tracked employee in their scope, by name.
     *
     * `Employee::attendanceVisibleTo()` is the one statement of that rule (decision 2-37) —
     * an Admin sees everybody and a Manager their own team — and it is a scope rather than a
     * policy call, so somebody outside it is absent from the list instead of refused.
     *
     * @return EloquentCollection<int, Employee>
     */
    private function employees(User $viewer): EloquentCollection
    {
        return Employee::query()
            ->attendanceVisibleTo($viewer)
            ->tracked()
            ->with(['user', 'role'])
            ->join('users', 'users.id', '=', 'employees.user_id')
            ->orderBy('users.name')
            ->select('employees.*')
            ->get();
    }

    /**
     * The ids of every open task this viewer can see, once.
     *
     * The two aggregates below both need this set, and asking for it once keeps them measuring
     * the same tasks — a second `->open()` in the second query is a second chance for the two
     * to be run a midnight apart.
     *
     * @return list<int>
     */
    private function openTaskIds(User $viewer, Carbon $asOf): array
    {
        return TaskBucket::Open
            ->apply($this->base($viewer), $asOf)
            ->pluck('tasks.id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * Estimated minutes per employee over those tasks, and how many carry no estimate at all.
     *
     * A join on `task_assignees` rather than a `whereHas`, because a task with two assignees
     * belongs to both of their lines — the same rule `TaskService::grouped()` states for an
     * assignee grouping. The two lines therefore sum higher than the agency's total, which is
     * correct, and why no screen prints a sum of these.
     *
     * @param  list<int>  $taskIds
     * @return Collection<int, object>
     */
    private function estimatesByEmployee(array $taskIds): Collection
    {
        if ($taskIds === []) {
            return collect();
        }

        return Task::query()
            ->whereIn('tasks.id', $taskIds)
            ->join('task_assignees', 'task_assignees.task_id', '=', 'tasks.id')
            ->groupBy('task_assignees.employee_id')
            ->selectRaw('task_assignees.employee_id as employee_id')
            ->selectRaw('coalesce(sum(tasks.estimated_minutes), 0) as estimated_minutes')
            ->selectRaw('count(*) filter (where tasks.estimated_minutes is null) as unestimated_count')
            ->get()
            ->keyBy(fn (object $row): int => (int) $row->employee_id);
    }

    /**
     * Tracked seconds per employee over those same tasks.
     *
     * Read from `time_entries` with the phase's one predicate — stopped and approved, decision
     * 4-7 — and not from `tasks.tracked_seconds`. The column is a cache refreshed on every stop
     * (decision 4-8), and Phase 2's seeder wrote fabricated figures onto about twenty tasks
     * that no real session has replaced yet. A capacity screen reading those would be quoting
     * numbers nobody worked.
     *
     * @param  list<int>  $taskIds
     * @return Collection<int, object>
     */
    private function trackedByEmployee(array $taskIds): Collection
    {
        if ($taskIds === []) {
            return collect();
        }

        return TimeEntry::query()
            ->whereIn('time_entries.task_id', $taskIds)
            ->stopped()
            ->counted()
            ->join('task_assignees', 'task_assignees.task_id', '=', 'time_entries.task_id')
            ->groupBy('task_assignees.employee_id')
            ->selectRaw('task_assignees.employee_id as employee_id')
            ->selectRaw('coalesce(sum(time_entries.duration_seconds), 0) as tracked_seconds')
            ->get()
            ->keyBy(fn (object $row): int => (int) $row->employee_id);
    }

    /**
     * Projects with pending work, most first.
     *
     * Two grouped counts rather than one query with a FILTER clause: each is the bucket's own
     * `apply()`, so "open" and "overdue" mean here exactly what they mean on the Tasks list. A
     * hand-written `where` inside a filter clause would have been a second definition of both.
     *
     * Only projects that actually have open work appear — a project with nothing pending is not
     * a project with the most pending work, and a long tail of zeroes would bury the four rows
     * the question was asked about.
     *
     * @return list<array<string, mixed>>
     */
    private function projects(User $viewer, Carbon $asOf): array
    {
        $open = $this->countsByProject($viewer, TaskBucket::Open, $asOf);

        if ($open->isEmpty()) {
            return [];
        }

        $overdue = $this->countsByProject($viewer, TaskBucket::Overdue, $asOf);

        $names = Project::query()
            ->whereIn('id', $open->keys()->all())
            ->with('client:id,name')
            ->get(['id', 'name', 'client_id']);

        $rows = $names
            ->map(fn (Project $project): array => [
                'id' => (int) $project->id,
                'name' => (string) $project->name,
                // A project without a client is Internal (decision 1-1), said in words rather
                // than left blank — a blank cell reads as missing data.
                'client' => $project->client?->name ?? 'Internal',
                'open_count' => (int) $open->get($project->id, 0),
                'overdue_count' => (int) $overdue->get($project->id, 0),
            ])
            ->values()
            ->all();

        usort($rows, fn (array $a, array $b): int => $b['open_count'] <=> $a['open_count']
            ?: strcasecmp($a['name'], $b['name']));

        return $rows;
    }

    /**
     * How many tasks in this bucket each project holds.
     *
     * @return Collection<int, int>
     */
    private function countsByProject(User $viewer, TaskBucket $bucket, Carbon $asOf): Collection
    {
        return $bucket
            ->apply($this->base($viewer), $asOf)
            ->whereNotNull('tasks.project_id')
            ->groupBy('tasks.project_id')
            ->selectRaw('tasks.project_id as project_id, count(*) as tasks_count')
            ->pluck('tasks_count', 'project_id')
            ->map(fn ($count): int => (int) $count);
    }

    /**
     * The rows this viewer may see at all, before a bucket narrows them.
     *
     * `visibleTo` plus "not archived" — the same two the Tasks list starts from, so a count
     * built here and a count built by `TaskService` are counting the same population.
     *
     * @return Builder<Task>
     */
    private function base(User $viewer): Builder
    {
        return Task::query()->visibleTo($viewer)->notArchived();
    }
}
