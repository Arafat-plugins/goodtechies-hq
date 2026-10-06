<?php

namespace App\Support;

use App\Models\Employee;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;

/**
 * The tasks a project page lists (client request 2026-10-06): an employee sees the tasks on this
 * project that are assigned to them; an Admin sees every task on the project.
 *
 * Who sees what is `Task::visibleTo()` — the same rule as every Tasks screen — so this is a view
 * of that query, not a second permission. Open work first (soonest due first), then finished work,
 * newest first. Capped at LIMIT rows; `total` says how many there are, and the page links to the
 * full Tasks list filtered to the project for the rest.
 */
final class ProjectTaskList
{
    public const LIMIT = 50;

    /**
     * @return array{total: int, open: int, rows: list<array<string, mixed>>}
     */
    public static function for(User $viewer, Project $project, string $surface): array
    {
        $base = Task::query()
            ->visibleTo($viewer)
            ->where('project_id', $project->getKey())
            ->notArchived()
            ->topLevel();

        $total = (clone $base)->count();
        $open = (clone $base)->open()->count();

        $closed = array_map(fn (TaskStatus $status): string => $status->value, TaskStatus::closed());

        $rows = (clone $base)
            ->with(['assignees.user:id,name'])
            ->orderByRaw('case when status in ('.implode(',', array_fill(0, count($closed), '?')).') then 1 else 0 end', $closed)
            ->orderByRaw('due_date is null')
            ->orderBy('due_date')
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (Task $task): array => [
                'id' => (int) $task->getKey(),
                'title' => (string) $task->title,
                'status' => $task->status?->value,
                'status_label' => $task->status?->label(),
                'tone' => $task->status?->tone(),
                'is_open' => $task->status?->isOpen() ?? true,
                'due_date' => $task->due_date?->toDateString(),
                'assignees' => $task->assignees
                    ->map(fn (Employee $employee): string => (string) ($employee->user?->name ?? ''))
                    ->filter()
                    ->values()
                    ->all(),
                'href' => "/{$surface}/tasks/{$task->getKey()}",
            ])
            ->values()
            ->all();

        return ['total' => $total, 'open' => $open, 'rows' => $rows];
    }
}
