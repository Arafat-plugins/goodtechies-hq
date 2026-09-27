<?php

namespace App\Http\Controllers\Concerns;

use App\Http\Resources\TaskResource;
use App\Models\Task;
use App\Models\User;
use App\Support\GanttZoom;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The Gantt's payload, built once for both surfaces.
 *
 * Nothing here decides who may see what. The rows come out of `TaskService::query()`, which
 * funnels through `Task::visibleTo()` exactly as the List, the Board and the Calendar do, so
 * "employees see only their accessible tasks" is not a rule this file implements — it is a
 * rule this file cannot avoid. The same goes for the dates: a bar is drawn from
 * `tasks.start_date` and `tasks.due_date` and moved through `PUT …/tasks/{task}`, so the one
 * validation `UpdateTaskRequest` performs is the one a drag gets.
 *
 * What IS decided here is geometry, and it is decided on the server for the reason the
 * Calendar's window is: a timeline that infers which dates it is drawing is a timeline that
 * will one day draw the wrong ones. The screen receives the window it got, the columns above
 * the bars, and per task the two dates clipped to that window — and computes only a left and a
 * width from them.
 *
 * **Three shapes, because a task has two nullable dates and therefore four cases.**
 *
 *   - both dates → a `bar` from one to the other;
 *   - no start date → a `milestone`, a diamond on the due date. The plan names this one;
 *   - no due date → an `open_ended` mark on the start date. The plan does NOT name this one.
 *     It is drawn as a start mark with a tail that fades out, because the two honest-looking
 *     alternatives are both lies: a one-day bar on the start date says the work takes a day,
 *     and a bar running to the edge of the window says it is due at the edge of the window.
 *     A planning view that quietly drops a task is worse than one that draws it oddly, so it
 *     is drawn, it says "no due date" in its accessible name and in the table, and it is the
 *     one shape whose right-hand resize handle **adds** a due date rather than moving one;
 *   - neither date → not on a timeline at all. Counted as `unscheduled_count` and said out
 *     loud, the same way the Calendar says it.
 *
 * **Dependencies are two different absences and they are answered differently.** An arrow is
 * drawn only when BOTH ends are in this payload. When the other end is merely outside the
 * window, the task carries a count of how many — the reader is told there is more, which is
 * the whole point of a planning view. When the other end is outside the reader's ACCESS,
 * nothing is drawn, nothing is counted, and no id crosses the wire: an arrow pointing at a
 * task somebody may not see would be a leak in the shape of a line, and a count of them would
 * be a smaller leak in the shape of a number.
 *
 * The using class supplies `private readonly TaskService $tasks` through its constructor, and
 * a `presentFilters()`, the way the three existing views do.
 */
trait BuildsGanttPayload
{
    /**
     * The whole Gantt payload for one request.
     *
     * @param  array<string, mixed>  $filters  already through `TaskService::filters()`
     * @return array<string, mixed>
     */
    private function ganttPayload(Request $request, array $filters): array
    {
        $user = $request->user();
        $zoom = GanttZoom::resolve($request->query('zoom'));
        [$from, $to, $clamped] = $this->ganttWindow($filters, $zoom);

        // The window IS the query. Every row that comes back overlaps it, which is what makes
        // this a bounded read rather than a scan of the whole table, and it is the same
        // overlap predicate the Calendar uses — see TaskService::filters().
        $windowed = [...$filters, 'date_from' => $from, 'date_to' => $to];

        $entries = [];

        foreach ($this->tasks->query($user, $windowed)->get() as $task) {
            $geometry = $this->ganttGeometry($task, $from, $to);

            if ($geometry !== null) {
                $entries[] = ['task' => $task, 'gantt' => $geometry];
            }
        }

        [$entries, $edges] = $this->ganttDependencies($user, $entries);

        return [
            'window' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                // Inclusive of both ends, and the denominator every bar's left and width are
                // measured against. The axis is days at every zoom.
                'days' => (int) $from->diffInDays($to) + 1,
                'zoom' => $zoom->value,
                // True when the requested window was wider than this zoom draws. Said out loud
                // rather than silently applied, so a clamped window is visible and not
                // mysterious.
                'clamped' => $clamped,
                'today' => Carbon::today()->toDateString(),
            ],
            'units' => $zoom->units($from, $to),
            'rows' => $this->ganttRows($entries, $request),
            'edges' => $edges,
            'total' => count($entries),
            // The tasks this timeline can never draw, because they have no date at all. A
            // count, exactly as the Calendar sends one, so the screen can say so instead of
            // quietly losing them.
            'unscheduled_count' => $this->tasks
                ->query($user, [...$filters, 'date_from' => null, 'date_to' => null])
                ->whereNull('start_date')
                ->whereNull('due_date')
                ->count(),
        ];
    }

    /**
     * The window the timeline actually draws.
     *
     * Half a window is still a window, as on the Calendar — `date_from` alone runs this zoom's
     * span forwards from there, `date_to` alone runs it backwards to there — and a window that
     * runs backwards collapses to a day rather than producing a negative axis. What is new
     * here is the clamp: the number of columns is the number of days, so a hand-edited
     * `date_from=1900-01-01` is not a slow page, it is a hundred thousand of them.
     *
     * @param  array<string, mixed>  $filters
     * @return array{0: Carbon, 1: Carbon, 2: bool}
     */
    private function ganttWindow(array $filters, GanttZoom $zoom): array
    {
        $from = $filters['date_from'] instanceof Carbon ? $filters['date_from']->copy() : null;
        $to = $filters['date_to'] instanceof Carbon ? $filters['date_to']->copy() : null;

        if ($from === null && $to === null) {
            [$from, $to] = $zoom->defaultWindow($filters['as_of'] ?? Carbon::today());
        } else {
            $span = $zoom->defaultWindow($filters['as_of'] ?? Carbon::today());
            $length = (int) $span[0]->diffInDays($span[1]);

            $from ??= $to->copy()->subDays($length);
            $to ??= $from->copy()->addDays($length);
        }

        $from = $from->startOfDay();
        $to = $to->startOfDay();

        if ($to->lt($from)) {
            $to = $from->copy();
        }

        $max = $zoom->maxDays();
        $clamped = ((int) $from->diffInDays($to) + 1) > $max;

        if ($clamped) {
            $to = $from->copy()->addDays($max - 1);
        }

        return [$from, $to, $clamped];
    }

    /**
     * One task's shape and geometry inside one window.
     *
     * `visible_*` is the span clipped to the window and `continues_*` says which edge it runs
     * off — the same two ideas `TaskService::span()` gives the Calendar, kept in the same
     * words so the two payloads read alike. What differs is the shape: the Calendar draws a
     * one-day bar for a task carrying only one of the two dates, and a Gantt must not, because
     * "a milestone on the due date" and "a one-day bar" are different claims about the work.
     *
     * @return array<string, mixed>|null
     */
    private function ganttGeometry(Task $task, Carbon $from, Carbon $to): ?array
    {
        $start = $task->start_date?->copy()->startOfDay();
        $due = $task->due_date?->copy()->startOfDay();

        if ($start === null && $due === null) {
            return null;
        }

        if ($start === null) {
            $shape = 'milestone';
            $anchorStart = $due->copy();
            $anchorEnd = $due->copy();
        } elseif ($due === null) {
            $shape = 'open_ended';
            $anchorStart = $start->copy();
            $anchorEnd = $start->copy();
        } else {
            $shape = 'bar';
            // UpdateTaskRequest refuses a due date before its start date, so this only catches
            // a row that got its dates some other way. Swapping beats a negative-width bar.
            [$anchorStart, $anchorEnd] = $due->lt($start)
                ? [$due->copy(), $start->copy()]
                : [$start->copy(), $due->copy()];
        }

        $visibleStart = $anchorStart->lt($from) ? $from->copy() : $anchorStart->copy();
        $visibleEnd = $anchorEnd->gt($to) ? $to->copy() : $anchorEnd->copy();

        return [
            'shape' => $shape,
            // The dates as stored, null where they are null. A drag reads these to compute the
            // move, so a milestone that is moved stays a milestone instead of quietly growing
            // the start date the screen invented for it.
            'start_date' => $start?->toDateString(),
            'due_date' => $due?->toDateString(),
            // Where the mark sits on the axis, which for a milestone and an open-ended mark is
            // one day and for a bar is two.
            'start' => $anchorStart->toDateString(),
            'end' => $anchorEnd->toDateString(),
            'visible_start' => $visibleStart->toDateString(),
            'visible_end' => $visibleEnd->toDateString(),
            // Day offsets from the window's first day, and a length — the two numbers a left
            // and a width are computed from, so the screen does no date arithmetic at all.
            'offset_days' => (int) $from->diffInDays($visibleStart),
            'days' => (int) $visibleStart->diffInDays($visibleEnd) + 1,
            'total_days' => (int) $anchorStart->diffInDays($anchorEnd) + 1,
            'continues_before' => $anchorStart->lt($from),
            'continues_after' => $anchorEnd->gt($to),
            'is_single_day' => $anchorStart->isSameDay($anchorEnd),
            // Filled in by ganttDependencies().
            'depends_on_visible' => 0,
            'depends_on_offscreen' => 0,
            'blocks_visible' => 0,
            'blocks_offscreen' => 0,
        ];
    }

    /**
     * The arrows, and the two counts that stand in for the arrows that cannot be drawn.
     *
     * One query over `task_dependencies` for every row in the payload, and one more to ask
     * which of the partners OUTSIDE the payload this reader may see at all. That second query
     * is the whole privacy rule: a partner it does not return is not drawn, not counted, and
     * not named — `Task::visibleTo()` answers it, so there is no second access rule here.
     *
     * @param  list<array{task: Task, gantt: array<string, mixed>}>  $entries
     * @return array{0: list<array{task: Task, gantt: array<string, mixed>}>, 1: list<array{from: int, to: int}>}
     */
    private function ganttDependencies(User $user, array $entries): array
    {
        $inside = [];

        foreach ($entries as $index => $entry) {
            $inside[(int) $entry['task']->getKey()] = $index;
        }

        if ($inside === []) {
            return [$entries, []];
        }

        $ids = array_keys($inside);

        $rows = DB::table('task_dependencies')
            ->select(['task_id', 'depends_on_task_id'])
            ->where(fn ($query) => $query
                ->whereIn('task_id', $ids)
                ->orWhereIn('depends_on_task_id', $ids))
            ->get();

        // Everything at the far end of an edge that is not itself on this timeline. Asked
        // about in one go, and only as "may this reader see it at all" — never fetched, never
        // sent.
        $outside = [];

        foreach ($rows as $row) {
            $dependent = (int) $row->task_id;
            $prerequisite = (int) $row->depends_on_task_id;

            if (! isset($inside[$dependent])) {
                $outside[$dependent] = true;
            }

            if (! isset($inside[$prerequisite])) {
                $outside[$prerequisite] = true;
            }
        }

        $visibleOutside = $outside === []
            ? []
            : Task::query()
                ->visibleTo($user)
                ->whereIn('id', array_keys($outside))
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->flip()
                ->all();

        $edges = [];

        foreach ($rows as $row) {
            $dependent = (int) $row->task_id;
            $prerequisite = (int) $row->depends_on_task_id;
            $hasDependent = isset($inside[$dependent]);
            $hasPrerequisite = isset($inside[$prerequisite]);

            if ($hasDependent && $hasPrerequisite) {
                // Drawn. The arrow runs from the thing that has to finish to the thing that is
                // waiting, which is the direction a reader traces a plan in.
                $edges[] = ['from' => $prerequisite, 'to' => $dependent];
                $entries[$inside[$dependent]]['gantt']['depends_on_visible']++;
                $entries[$inside[$prerequisite]]['gantt']['blocks_visible']++;

                continue;
            }

            if ($hasDependent) {
                // This task waits for something that is not on screen. Counted only if the
                // reader may see that something; otherwise the dependency is not theirs to
                // know about and this loop does nothing at all.
                if (isset($visibleOutside[$prerequisite])) {
                    $entries[$inside[$dependent]]['gantt']['depends_on_offscreen']++;
                }

                continue;
            }

            if (isset($visibleOutside[$dependent])) {
                $entries[$inside[$prerequisite]]['gantt']['blocks_offscreen']++;
            }
        }

        return [$entries, $edges];
    }

    /**
     * The rows, grouped by project.
     *
     * `tasks.project_id` is NOT NULL, so every task has a project and there is no orphan group
     * to invent a home for. Projects are ordered by name and the tasks inside one by where
     * they sit on the axis, so two requests for the same window produce the same picture — a
     * timeline whose rows shuffle between renders is a timeline nobody can point at.
     *
     * The project's name reaches the screen through TaskResource, which composes
     * ProjectResource — so a row heading on the employee surface is the domain, and no finance
     * field can arrive on a Gantt row by somebody widening a select list.
     *
     * @param  list<array{task: Task, gantt: array<string, mixed>}>  $entries
     * @return list<array{project: array<string, mixed>|null, project_id: int, tasks: list<array<string, mixed>>}>
     */
    private function ganttRows(array $entries, Request $request): array
    {
        usort($entries, function (array $a, array $b): int {
            $left = [
                (string) ($a['task']->project?->name ?? ''),
                (int) $a['task']->project_id,
                (string) $a['gantt']['start'],
                (string) $a['gantt']['end'],
                (int) $a['task']->getKey(),
            ];
            $right = [
                (string) ($b['task']->project?->name ?? ''),
                (int) $b['task']->project_id,
                (string) $b['gantt']['start'],
                (string) $b['gantt']['end'],
                (int) $b['task']->getKey(),
            ];

            return $left <=> $right;
        });

        $rows = [];

        foreach ($entries as $entry) {
            $projectId = (int) $entry['task']->project_id;
            $task = (new TaskResource($entry['task']))->resolve($request) + ['gantt' => $entry['gantt']];

            if (! isset($rows[$projectId])) {
                $rows[$projectId] = [
                    'project_id' => $projectId,
                    // Read off the row's own first task rather than composed a second time. A
                    // heading built from a different serialiser than the rows under it is a
                    // heading that can one day say a different name — and on the employee
                    // surface ProjectResource says the DOMAIN, which is exactly the field that
                    // must not differ between the two.
                    'project' => is_array($task['project'] ?? null) ? $task['project'] : null,
                    'tasks' => [],
                ];
            }

            $rows[$projectId]['tasks'][] = $task;
        }

        return array_values($rows);
    }
}
