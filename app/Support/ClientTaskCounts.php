<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Polish 049: a client's tasks in three counts — To do, In progress, Complete — for the Clients
 * list and a client's page. One grouped query, never a query per row.
 *
 *   To do       = backlog, todo
 *   In progress = in_progress, in_review, changes_requested, waiting
 *   Complete    = completed
 *
 * Cancelled tasks and deleted tasks are not counted. `total` is the three added up.
 */
final class ClientTaskCounts
{
    public const TODO = ['backlog', 'todo'];

    public const PROGRESS = ['in_progress', 'in_review', 'changes_requested', 'waiting'];

    public const COMPLETE = ['completed'];

    /**
     * @param  list<int>  $clientIds
     * @return array<int, array{todo: int, progress: int, complete: int, total: int}>
     */
    public static function forClients(array $clientIds): array
    {
        return self::grouped('projects.client_id', $clientIds);
    }

    /**
     * @param  list<int>  $projectIds
     * @return array<int, array{todo: int, progress: int, complete: int, total: int}>
     */
    public static function forProjects(array $projectIds): array
    {
        return self::grouped('tasks.project_id', $projectIds);
    }

    /** @return array{todo: int, progress: int, complete: int, total: int} */
    public static function empty(): array
    {
        return ['todo' => 0, 'progress' => 0, 'complete' => 0, 'total' => 0];
    }

    /**
     * Where a client's tasks were last touched, per client — the list's "Last activity".
     *
     * @param  list<int>  $clientIds
     * @return array<int, string>
     */
    public static function lastActivity(array $clientIds): array
    {
        if ($clientIds === []) {
            return [];
        }

        return DB::table('tasks')
            ->join('projects', 'projects.id', '=', 'tasks.project_id')
            ->whereIn('projects.client_id', $clientIds)
            ->whereNull('tasks.deleted_at')
            ->groupBy('projects.client_id')
            ->selectRaw('projects.client_id as id, max(tasks.updated_at) as at')
            ->pluck('at', 'id')
            ->map(fn ($at): string => (string) $at)
            ->all();
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, array{todo: int, progress: int, complete: int, total: int}>
     */
    private static function grouped(string $column, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $in = fn (array $statuses): string => "'".implode("','", $statuses)."'";

        $rows = DB::table('tasks')
            ->join('projects', 'projects.id', '=', 'tasks.project_id')
            ->whereIn($column, $ids)
            ->whereNull('tasks.deleted_at')
            ->groupBy($column)
            ->selectRaw(
                "{$column} as id,
                 count(*) filter (where tasks.status in ({$in(self::TODO)})) as todo,
                 count(*) filter (where tasks.status in ({$in(self::PROGRESS)})) as progress,
                 count(*) filter (where tasks.status in ({$in(self::COMPLETE)})) as complete"
            )
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $todo = (int) $row->todo;
            $progress = (int) $row->progress;
            $complete = (int) $row->complete;

            $out[(int) $row->id] = [
                'todo' => $todo,
                'progress' => $progress,
                'complete' => $complete,
                'total' => $todo + $progress + $complete,
            ];
        }

        return $out;
    }
}
