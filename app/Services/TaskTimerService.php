<?php

namespace App\Services;

use App\Exceptions\AttendanceStateException;
use App\Exceptions\TimerStateException;
use App\Models\Employee;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * The task timer for everyone who works tasks — flow F3, decision 12-73.
 *
 * `TimerService` still owns every rule about a `time_entries` row; this class owns the one
 * thing the remote timer never needed: **which clock the person's day is on.**
 *
 *   - **Office employees and Admins** (`tracking_mode = office_attendance`): the day is the
 *     office clock. A task timer runs only inside an open clock-in — ▶ while clocked out
 *     clocks them in first when they confirm, in the same transaction — and its rows are written
 *     `counts_toward_hours = false`, a breakdown of the clocked day by task that never reaches an
 *     hours figure. Clock-out stops it (`AttendanceService::clockOut`).
 *   - **Remote-timer employees**: their timer already IS their day. ▶ starts or switches that
 *     same timer on the task, counting exactly as it always has.
 *
 * Either way it is one open entry per person, and ▶ on another task switches
 * (`TimerService::switchTo`).
 */
class TaskTimerService
{
    /** Where one request's board snapshot is kept, so two hundred cards cost one query. */
    private const SNAPSHOT_ATTRIBUTE = 'task_timer_snapshot';

    public function __construct(
        private readonly TimerService $timer,
        private readonly AttendanceService $attendance,
    ) {}

    /**
     * ▶ on `$task`. With `$clockIn`, an office employee who is not clocked in is clocked in first
     * (the confirm's "Clock in and start?"); without it they are refused.
     *
     * @throws TimerStateException
     * @throws AttendanceStateException the clock-in itself was refused
     */
    public function start(Employee $employee, Task $task, ?string $clientUuid, bool $clockIn): TimeEntry
    {
        $clientUuid ??= (string) Str::uuid();
        $office = $this->attendance->clocks($employee);

        return DB::transaction(function () use ($employee, $task, $clientUuid, $clockIn, $office): TimeEntry {
            if ($office && ! $this->attendance->isClockedIn($employee)) {
                if (! $clockIn) {
                    throw TimerStateException::clockInFirst();
                }

                $this->attendance->clockIn($employee);
            }

            return $this->timer->switchTo($employee, $task, $clientUuid, countsTowardHours: ! $office);
        });
    }

    /**
     * The open entries a board shows this viewer, read ONCE per request.
     *
     * - `mine`: the viewer's own open entry (at most one), by task id.
     * - `others`: everybody else's, by task id — filled only for a viewer who may watch live
     *   (`TimeEntryPolicy::watchLive`). For anybody else the query never reads another person's
     *   row, so there is nothing to leak further down.
     *
     * One statement (a join for the names), memoised on the request, whatever the number of cards.
     *
     * @return array{watch: bool, mine: array<int, TimeEntry>, others: array<int, list<array{employee_id: int, name: string, initials: string, started_at: string|null, state: string, elapsed_seconds: int}>>}
     */
    public function snapshotFor(User $viewer, Request $request): array
    {
        $cached = $request->attributes->get(self::SNAPSHOT_ATTRIBUTE);

        if (is_array($cached) && ($cached['viewer'] ?? null) === $viewer->getKey()) {
            return $cached['snapshot'];
        }

        $snapshot = $this->readSnapshot($viewer);

        $request->attributes->set(self::SNAPSHOT_ATTRIBUTE, ['viewer' => $viewer->getKey(), 'snapshot' => $snapshot]);

        return $snapshot;
    }

    /**
     * @return array{watch: bool, mine: array<int, TimeEntry>, others: array<int, list<array{employee_id: int, name: string, initials: string, started_at: string|null, state: string, elapsed_seconds: int}>>}
     */
    private function readSnapshot(User $viewer): array
    {
        $watch = Gate::forUser($viewer)->allows('watchLive', TimeEntry::class);
        $employeeId = $viewer->employee?->getKey();
        $snapshot = ['watch' => $watch, 'mine' => [], 'others' => []];

        if (! $watch && $employeeId === null) {
            return $snapshot;
        }

        $rows = TimeEntry::query()
            ->open()
            ->when(! $watch, fn ($query) => $query->where('time_entries.employee_id', $employeeId))
            ->join('employees', 'employees.id', '=', 'time_entries.employee_id')
            ->leftJoin('users', 'users.id', '=', 'employees.user_id')
            ->orderBy('time_entries.started_at')
            ->get(['time_entries.*', 'users.name as employee_name']);

        $now = Carbon::now();

        foreach ($rows as $entry) {
            $taskId = (int) $entry->task_id;

            if ($employeeId !== null && (int) $entry->employee_id === (int) $employeeId) {
                $snapshot['mine'][$taskId] = $entry;

                continue;
            }

            $name = (string) ($entry->getAttribute('employee_name') ?? 'Someone');

            $snapshot['others'][$taskId][] = [
                'employee_id' => (int) $entry->employee_id,
                'name' => $name,
                'initials' => self::initials($name),
                'started_at' => $entry->started_at?->toIso8601String(),
                'state' => $entry->stateKey(),
                'elapsed_seconds' => $entry->elapsedSeconds($now),
            ];
        }

        return $snapshot;
    }

    /**
     * "Working now" — every open task timer, running or paused, for the Admin Time page and the
     * dashboard. Flow F3.
     *
     * `null` for anybody `TimeEntryPolicy::watchLive` refuses: running-timer identities reach
     * watchers only, and the caller leaves the prop OUT rather than sending an empty list that
     * would read as "nobody is working". Only tasks the viewer may see (`Task::visibleTo`), so a
     * title never reaches somebody the task itself is hidden from.
     *
     * **One statement** — the person, the task and the project are joins, not relations — so the
     * panel costs the same with one person timing as with the whole team. Ordered by name, never
     * by time: who, what, how long, and no league table (Part H).
     *
     * `$projectId` narrows the same statement to one project's entries (the project page).
     *
     * @return list<array{id: int, employee: array{id: int, name: string, initials: string}, task: array{id: int, title: string, href: string}, project: array{id: int, name: string}|null, started_at: string|null, paused: bool, elapsed_seconds: int}>|null
     */
    public function workingNow(User $viewer, ?int $projectId = null): ?array
    {
        if (! Gate::forUser($viewer)->allows('watchLive', TimeEntry::class)) {
            return null;
        }

        $rows = TimeEntry::query()
            ->open()
            ->whereIn('time_entries.task_id', Task::query()->visibleTo($viewer)->select('tasks.id'))
            ->when($projectId !== null, fn ($query) => $query->where('time_entries.project_id', $projectId))
            ->join('employees', 'employees.id', '=', 'time_entries.employee_id')
            ->leftJoin('users', 'users.id', '=', 'employees.user_id')
            ->join('tasks', 'tasks.id', '=', 'time_entries.task_id')
            ->leftJoin('projects', 'projects.id', '=', 'time_entries.project_id')
            ->orderBy('users.name')
            ->orderBy('time_entries.id')
            ->get([
                'time_entries.*',
                'users.name as employee_name',
                'tasks.title as task_title',
                'projects.name as project_name',
            ]);

        $now = Carbon::now();

        return $rows->map(function (TimeEntry $entry) use ($now): array {
            $name = (string) ($entry->getAttribute('employee_name') ?? 'Someone');
            $projectName = $entry->getAttribute('project_name');

            return [
                'id' => (int) $entry->getKey(),
                'employee' => [
                    'id' => (int) $entry->employee_id,
                    'name' => $name,
                    'initials' => self::initials($name),
                ],
                'task' => [
                    'id' => (int) $entry->task_id,
                    'title' => (string) $entry->getAttribute('task_title'),
                    'href' => '/admin/tasks/'.$entry->task_id,
                ],
                'project' => $projectName === null ? null : [
                    'id' => (int) $entry->project_id,
                    'name' => (string) $projectName,
                ],
                'started_at' => $entry->started_at?->toIso8601String(),
                'paused' => $entry->isPaused(),
                'elapsed_seconds' => $entry->elapsedSeconds($now),
            ];
        })->values()->all();
    }

    /**
     * The same rows grouped by project id — the Projects list's "Working now" marker. Still the
     * one statement; entries with no project are left out. Null without `watchLive`.
     *
     * @return array<int, list<array<string, mixed>>>|null
     */
    public function workingNowByProject(User $viewer): ?array
    {
        $rows = $this->workingNow($viewer);

        if ($rows === null) {
            return null;
        }

        $grouped = [];
        foreach ($rows as $row) {
            if ($row['project'] !== null) {
                $grouped[$row['project']['id']][] = $row;
            }
        }

        return $grouped;
    }

    /** "Yaseen Arafat" → "YA", "Tapu" → "T" — the avatar's letters, the same rule the screens use. */
    private static function initials(string $name): string
    {
        $words = preg_split('/\s+/', trim($name)) ?: [];
        $letters = array_map(fn (string $word): string => mb_strtoupper(mb_substr($word, 0, 1)), array_slice(array_filter($words), 0, 2));

        return implode('', $letters) ?: '?';
    }

    /**
     * The viewer's own open entry, as a card and the drawer draw it — or null.
     *
     * @return array{state: string, started_at: string|null, elapsed_seconds: int}|null
     */
    public static function present(?TimeEntry $entry): ?array
    {
        if ($entry === null) {
            return null;
        }

        return [
            'state' => $entry->stateKey(),
            'started_at' => $entry->started_at?->toIso8601String(),
            'elapsed_seconds' => $entry->elapsedSeconds(),
        ];
    }
}
