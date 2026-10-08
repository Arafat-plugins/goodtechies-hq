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
                // Polish 033: the sidebar counts a client's projects.
                'project_count' => $items->count(),
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

    /**
     * Polish 033: one client's picture — its projects sorted into the service boxes
     * (Development, SEO, Maintenance, Marketing, … — the Admin's own list), each project with its
     * OPEN tasks underneath (each with its own time), and an overview of where the time went: the
     * client's total and each box's (polish 034: no separate per-task list).
     *
     * `$key` is the client's id, or `internal` for the projects with no client. Null when the
     * viewer can see none of that client's projects.
     *
     * @return array<string, mixed>|null
     */
    public static function client(User $viewer, string $key): ?array
    {
        $clientId = $key === 'internal' ? null : (ctype_digit($key) ? (int) $key : -1);

        if ($clientId === -1) {
            return null;
        }

        $projects = Project::query()
            ->visibleTo($viewer)
            ->notArchived()
            ->when(
                $clientId === null,
                fn (Builder $query) => $query->whereNull('client_id'),
                fn (Builder $query) => $query->where('client_id', $clientId),
            )
            ->with('client:id,name,nickname')
            ->orderBy('name')
            ->get();

        if ($projects->isEmpty()) {
            return null;
        }

        $projectIds = $projects->modelKeys();

        /** @var array<int, int> $projectSeconds */
        $projectSeconds = TimeEntry::query()
            ->tracked()
            ->whereIn('project_id', $projectIds)
            ->groupBy('project_id')
            ->selectRaw('project_id, sum(duration_seconds) as seconds')
            ->pluck('seconds', 'project_id')
            ->map(fn ($seconds): int => (int) $seconds)
            ->all();

        $tasks = Task::query()
            ->visibleTo($viewer)
            ->whereIn('project_id', $projectIds)
            ->notArchived()
            ->topLevel()
            ->with(['assignees.user:id,name'])
            ->orderByRaw('due_date is null')
            ->orderBy('due_date')
            ->orderByDesc('id')
            ->get();

        $byProject = $tasks->groupBy('project_id');

        $config = ProjectServiceBoxes::configured();
        $typeToBox = ProjectServiceBoxes::typeToBox($config);

        $boxes = [];

        foreach ($config as $index => $box) {
            $boxes['b'.$index] = ['key' => 'b'.$index, 'name' => $box['name'], 'seconds' => 0, 'projects' => []];
        }

        $boxes[ProjectServiceBoxes::OTHER_KEY] = ['key' => ProjectServiceBoxes::OTHER_KEY, 'name' => 'Other', 'seconds' => 0, 'projects' => []];

        foreach ($projects as $project) {
            $boxKey = $typeToBox[$project->project_type?->value ?? ''] ?? ProjectServiceBoxes::OTHER_KEY;

            /** @var Collection<int, Task> $own */
            $own = $byProject->get($project->getKey(), collect());
            // The project's approved time; never less than its own tasks' time, so a box always
            // adds up to at least what it shows.
            $seconds = max($projectSeconds[$project->getKey()] ?? 0, (int) $own->sum('tracked_seconds'));
            $projectSeconds[$project->getKey()] = $seconds;
            $open = $own->filter(fn (Task $task): bool => (bool) $task->status?->isOpen());

            $boxes[$boxKey]['seconds'] += $seconds;
            $boxes[$boxKey]['projects'][] = [
                'id' => (int) $project->getKey(),
                'name' => (string) $project->name,
                'type' => $project->project_type?->label(),
                'status_label' => $project->status?->label(),
                'tone' => $project->status?->tone(),
                'tracked_seconds' => $seconds,
                'open_count' => $open->count(),
                'tasks' => $open->map(fn (Task $task): array => [
                    'id' => (int) $task->getKey(),
                    'title' => (string) $task->title,
                    'status_label' => $task->status?->label(),
                    'tone' => $task->status?->tone(),
                    'due_date' => $task->due_date?->toDateString(),
                    'tracked_seconds' => (int) $task->tracked_seconds,
                    // Polish 041: who it is assigned to — id for the person's colour, name for
                    // the face and the tooltip (as on the Tasks board).
                    'assignees' => $task->assignees
                        ->map(fn (Employee $employee): array => [
                            'id' => (int) $employee->getKey(),
                            'name' => (string) ($employee->user?->name ?? ''),
                        ])
                        ->filter(fn (array $person): bool => $person['name'] !== '')
                        ->values()
                        ->all(),
                ])->values()->all(),
            ];
        }

        $total = array_sum($projectSeconds);
        $onTasks = (int) $tasks->sum('tracked_seconds');

        /** @var Client|null $client */
        $client = $projects->first()?->client;
        $nickname = trim((string) ($client?->nickname ?? ''));

        return [
            'client' => [
                'key' => $clientId === null ? 'internal' : (string) $clientId,
                'id' => $client?->getKey(),
                'label' => $client === null ? 'Internal' : ($nickname !== '' ? $nickname : (string) $client->name),
                'name' => $client?->name,
                'project_count' => $projects->count(),
                'open_count' => $tasks->filter(fn (Task $task): bool => (bool) $task->status?->isOpen())->count(),
            ],
            'overview' => [
                'tracked_seconds' => $total,
                // Time logged on the projects but not on one of their (top-level) tasks.
                'untasked_seconds' => max(0, $total - $onTasks),
                'boxes' => collect($boxes)
                    ->filter(fn (array $box): bool => $box['projects'] !== [])
                    ->map(fn (array $box): array => ['key' => $box['key'], 'name' => $box['name'], 'seconds' => $box['seconds']])
                    ->values()
                    ->all(),
            ],
            'boxes' => collect($boxes)->filter(fn (array $box): bool => $box['projects'] !== [])->values()->all(),
        ];
    }

    /**
     * The service boxes as the Admin set them, and every project type with its name — what the
     * "Edit boxes" dialog needs.
     *
     * @return array<string, mixed>
     */
    public static function boxSettings(): array
    {
        return [
            'boxes' => ProjectServiceBoxes::configured(),
            'types' => array_map(fn (ProjectType $type): array => ['value' => $type->value, 'label' => $type->label()], ProjectType::cases()),
        ];
    }
}
