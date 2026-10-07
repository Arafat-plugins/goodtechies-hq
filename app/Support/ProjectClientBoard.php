<?php

namespace App\Support;

use App\Models\Client;
use App\Models\Employee;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Polish 030: the Admin Projects page by client (ClickUp-style, the client's own picture).
 *
 * Left: every client — by nickname when it has one — with its projects underneath and how many
 * open tasks each has. Right: the chosen project's tasks as a board, To do → In progress →
 * Review → Done. Read-only here; the full board (drag and drop) is one press away.
 */
final class ProjectClientBoard
{
    /** Done cards shown per board: the latest ones, so a long-running project stays readable. */
    public const DONE_LIMIT = 20;

    /** The board's columns, each the statuses it gathers. */
    public const COLUMNS = [
        'todo' => ['label' => 'To do', 'statuses' => [TaskStatus::Backlog, TaskStatus::Todo]],
        'in_progress' => ['label' => 'In progress', 'statuses' => [TaskStatus::InProgress]],
        'review' => ['label' => 'Review', 'statuses' => [TaskStatus::InReview, TaskStatus::ChangesRequested, TaskStatus::Waiting]],
        'done' => ['label' => 'Done', 'statuses' => [TaskStatus::Completed]],
    ];

    /**
     * Clients with their (not archived) projects and open-task counts, by client label.
     * Projects with no client come last, as "Internal".
     *
     * @return list<array<string, mixed>>
     */
    public static function tree(User $viewer): array
    {
        $open = array_map(fn (TaskStatus $status): string => $status->value, array_filter(
            TaskStatus::cases(),
            fn (TaskStatus $status): bool => $status->isOpen(),
        ));

        $projects = Project::query()
            ->visibleTo($viewer)
            ->notArchived()
            ->with('client:id,name,nickname')
            ->withCount([
                'tasks as open_tasks_count' => fn (Builder $query) => $query
                    ->whereNull('parent_id')
                    ->whereNull('archived_at')
                    ->whereIn('status', $open),
                'tasks as tasks_count' => fn (Builder $query) => $query
                    ->whereNull('parent_id')
                    ->whereNull('archived_at'),
            ])
            ->orderBy('name')
            ->get();

        /** @var Collection<string, Collection<int, Project>> $groups */
        $groups = $projects->groupBy(fn (Project $project): string => $project->client_id === null ? 'internal' : 'c'.$project->client_id);

        $rows = $groups->map(function (Collection $items, string $key): array {
            /** @var Client|null $client */
            $client = $items->first()?->client;
            $nickname = trim((string) ($client?->nickname ?? ''));

            return [
                'key' => $key,
                'id' => $client?->getKey(),
                'label' => $client === null ? 'Internal' : ($nickname !== '' ? $nickname : (string) $client->name),
                'name' => $client?->name,
                'open' => (int) $items->sum('open_tasks_count'),
                'total' => (int) $items->sum('tasks_count'),
                'projects' => $items->map(fn (Project $project): array => [
                    'id' => (int) $project->getKey(),
                    'name' => (string) $project->name,
                    'domain' => $project->domain,
                    'status' => $project->status?->value,
                    'status_label' => $project->status?->label(),
                    'tone' => $project->status?->tone(),
                    'open' => (int) $project->open_tasks_count,
                    'total' => (int) $project->tasks_count,
                ])->values()->all(),
            ];
        });

        return $rows
            ->sortBy(fn (array $row): string => ($row['key'] === 'internal' ? '~' : '').mb_strtolower((string) $row['label']))
            ->values()
            ->all();
    }

    /**
     * One project's tasks as board columns.
     *
     * @return array<string, mixed>
     */
    public static function board(User $viewer, Project $project): array
    {
        $tasks = Task::query()
            ->visibleTo($viewer)
            ->where('project_id', $project->getKey())
            ->notArchived()
            ->topLevel()
            ->with(['assignees.user:id,name'])
            ->orderByRaw('due_date is null')
            ->orderBy('due_date')
            ->orderByDesc('id')
            ->get();

        $columns = [];

        foreach (self::COLUMNS as $key => $column) {
            $values = array_map(fn (TaskStatus $status): string => $status->value, $column['statuses']);
            $inColumn = $tasks->filter(fn (Task $task): bool => in_array($task->status?->value, $values, true));

            if ($key === 'done') {
                $inColumn = $inColumn->sortByDesc(fn (Task $task) => $task->completed_at ?? $task->updated_at)->take(self::DONE_LIMIT);
            }

            $columns[] = [
                'key' => $key,
                'label' => $column['label'],
                'count' => $key === 'done'
                    ? $tasks->filter(fn (Task $task): bool => in_array($task->status?->value, $values, true))->count()
                    : $inColumn->count(),
                'cards' => $inColumn->map(fn (Task $task): array => [
                    'id' => (int) $task->getKey(),
                    'title' => (string) $task->title,
                    'status_label' => $task->status?->label(),
                    'tone' => $task->status?->tone(),
                    'priority' => $task->priority?->value,
                    'priority_label' => $task->priority?->label(),
                    'due_date' => $task->due_date?->toDateString(),
                    'tracked_seconds' => (int) $task->tracked_seconds,
                    'assignees' => $task->assignees
                        ->map(fn (Employee $employee): string => (string) ($employee->user?->name ?? ''))
                        ->filter()
                        ->values()
                        ->all(),
                ])->values()->all(),
            ];
        }

        $project->loadMissing('client:id,name,nickname');

        return [
            'project' => [
                'id' => (int) $project->getKey(),
                'name' => (string) $project->name,
                'domain' => $project->domain,
                'client' => $project->client?->name,
                'type' => $project->project_type?->label(),
                'status_label' => $project->status?->label(),
                'tone' => $project->status?->tone(),
                'deadline' => $project->deadline?->toDateString(),
                'tracked_seconds' => (int) TimeEntry::query()->tracked()->where('project_id', $project->getKey())->sum('duration_seconds'),
            ],
            'columns' => $columns,
        ];
    }
}
