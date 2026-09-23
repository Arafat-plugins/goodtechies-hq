<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\TimerStateException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Time\RejectTimeEntryRequest;
use App\Http\Resources\TimeEntryResource;
use App\Models\Employee;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Services\TimerService;
use App\Support\TrackingMode;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Admin → Workforce → Time (master prompt Part D §7, Phase 4).
 *
 * ## The screen exists because of decision 4-16
 *
 * `manual_time_requires_approval` is seeded **on**, so a manual or corrected entry is written
 * with `approved_at` null and counts toward nothing. Until this screen there was nowhere to sign
 * one off, which 4-16 records as the thing that would bite at GATE C: hours somebody actually
 * worked, reported in words and genuinely uncounted, with no way to change that.
 *
 * So the queue leads the page. Its job is to make one decision obvious at a glance — **who,
 * when, how long, and why it is waiting** — and there are exactly two verbs.
 *
 * ## What the two verbs do
 *
 * **Approve** sets `approved_at`, and that single predicate is the whole of the change: every
 * total in the application starts including the entry, because decision 4-7 made
 * `approved_at is not null` the one question a total asks. **Reject** leaves `approved_at` null
 * — so the entry counts no more than it did while it waited — and writes `rejected_at` with the
 * Admin's reason beside it. It does **not** delete: the employee keeps seeing the afternoon they
 * recorded, with the sentence saying why it is not in their total. Both are audit-logged with
 * old and new values, because these hours become somebody's pay in Phase 9.
 *
 * ## Everything else on the page is a count, and every count is scoped
 *
 * Hours today and this week, by employee, by project and by task — three groupings of one query
 * shape (`TimerService::secondsByGroup()`), each scoped by `TimeEntry::visibleTo()`. No screen
 * here totals people up against each other, ranks them, or divides anything by a target: Part H
 * forbids a productivity score outright and `tests/Feature/Admin/TimeApprovalTest.php` greps
 * this file and its Vue for the words.
 *
 * ## 403 and 404
 *
 * The three routes are behind `surface:admin`, so every other shell is refused there — **403**,
 * a fact about who is asking (Part C §1). On top of that `TimeEntryPolicy::review` gates the
 * page and `::approve` gates each decision, so an Admin-surface role without
 * `attendance.manage_others` is 403 as well.
 *
 * **404** is the record rule. `{timeEntry}` is re-resolved through `TimeEntry::visibleTo()`
 * before the policy is asked, so an entry outside the requester's scope is ABSENT rather than
 * refused — a 403 there would confirm it exists. Every holder of `attendance.manage_others` sees
 * every entry today, so in practice that 404 is an unknown id; it is coded rather than argued
 * for the same reason the attendance roster's is.
 */
class TimeController extends Controller
{
    /**
     * How many waiting entries the queue draws.
     *
     * The audience is one Admin clearing a short queue once or twice a week, so this is a list
     * to finish rather than a backlog to paginate. If it ever overflows, the screen says how many
     * are behind it — a truncated list that does not admit it is a list that gets trusted.
     */
    private const QUEUE_LIMIT = 50;

    /** How many already-counted flagged entries, and how many recent decisions, to show. */
    private const SHORTLIST = 10;

    /** How many rows each of the three breakdown tables holds. */
    private const BREAKDOWN_LIMIT = 25;

    public function __construct(private readonly TimerService $timer) {}

    public function index(Request $request): Response
    {
        Gate::authorize('review', TimeEntry::class);

        $user = $request->user();
        $date = $this->date($request);
        [$weekFrom, $weekTo] = $this->week($date);

        $queue = $this->timer->awaitingDecision($user, self::QUEUE_LIMIT);
        $flagged = $this->timer->flaggedAndCounted($user, $weekFrom, $weekTo, self::SHORTLIST);
        $decided = $this->timer->recentlyDecided($user, self::SHORTLIST);

        return Inertia::render('Admin/Time/Index', [
            'date' => [
                'value' => $date->toDateString(),
                'label' => $date->isoFormat('dddd, D MMMM YYYY'),
                'previous' => $date->copy()->subDay()->toDateString(),
                'next' => $date->copy()->addDay()->toDateString(),
                'today' => Carbon::today()->toDateString(),
            ],
            'week' => [
                'from' => $weekFrom->toDateString(),
                'to' => $weekTo->toDateString(),
                'label' => $weekFrom->isoFormat('D MMM').' – '.$weekTo->isoFormat('D MMM YYYY'),
            ],
            'queue' => $this->entries($request, $queue),
            // The whole count, not the list's length: a queue that says "12 waiting" while
            // showing 50 rows has told the Admin something they can act on.
            'queue_total' => $this->timer->awaitingDecisionCount($user),
            'queue_limit' => self::QUEUE_LIMIT,
            'flagged' => $this->entries($request, $flagged),
            'decided' => $this->entries($request, $decided),
            'byEmployee' => $this->byEmployee($user, $date, $weekFrom, $weekTo),
            'byProject' => $this->byProject($user, $date, $weekFrom, $weekTo),
            'byTask' => $this->byTask($user, $date, $weekFrom, $weekTo),
        ]);
    }

    /**
     * Sign an entry off. The hours count from here.
     */
    public function approve(Request $request, TimeEntry $timeEntry): RedirectResponse
    {
        $entry = $this->visible($request, $timeEntry);

        Gate::authorize('approve', $entry);

        try {
            $this->timer->approve($entry, $request->user());
        } catch (TimerStateException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', sprintf(
            '%s — %s on %s now counts.',
            $this->duration((int) $entry->duration_seconds),
            $entry->employee?->user?->name ?? 'That employee',
            $entry->work_date?->isoFormat('D MMM YYYY') ?? 'that day',
        ));
    }

    /**
     * Turn an entry down, with a reason. The row and its hours stay; they do not count.
     */
    public function reject(RejectTimeEntryRequest $request, TimeEntry $timeEntry): RedirectResponse
    {
        $entry = $this->visible($request, $timeEntry);

        Gate::authorize('approve', $entry);

        try {
            $this->timer->reject($entry, $request->reason(), $request->user());
        } catch (TimerStateException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', sprintf(
            '%s — %s on %s was turned down. It stays on their record and does not count.',
            $this->duration((int) $entry->duration_seconds),
            $entry->employee?->user?->name ?? 'That employee',
            $entry->work_date?->isoFormat('D MMM YYYY') ?? 'that day',
        ));
    }

    /**
     * Hours by employee — everybody the timer tracks, plus anybody who recorded time this week.
     *
     * The second half matters on exactly one day: the day somebody's `tracking_mode` is changed.
     * Their earlier entries do not disappear from the week they were made in, which is the same
     * rule `time_entries.project_id` is denormalised for.
     *
     * A remote employee with nothing recorded is **in the list at zero**, not missing from it.
     * Zero is an answer — "nobody has tracked anything today" is the thing an Admin opening this
     * at nine in the morning needs to read — and a table whose rows come and go through the week
     * is one nobody can compare against yesterday.
     *
     * @return list<array<string, mixed>>
     */
    private function byEmployee($user, Carbon $date, Carbon $from, Carbon $to): array
    {
        $totals = $this->timer->secondsByGroup($user, 'employee_id', $from, $to, $date);

        $employees = Employee::query()
            ->attendanceVisibleTo($user)
            ->where(function ($query) use ($totals): void {
                $query->where('tracking_mode', TrackingMode::RemoteTimer);

                if ($totals !== []) {
                    $query->orWhereIn('employees.id', array_keys($totals));
                }
            })
            ->with('user:id,name')
            ->join('users', 'users.id', '=', 'employees.user_id')
            ->orderBy('users.name')
            ->select('employees.*')
            ->get();

        return $employees->map(fn (Employee $employee): array => [
            'id' => (int) $employee->getKey(),
            'name' => $employee->user?->name ?? 'Unknown',
            'href' => '/attendance/'.$employee->getKey(),
            ...$this->seconds($totals[(int) $employee->getKey()] ?? null),
        ])->values()->all();
    }

    /**
     * Hours by project, busiest week first. Only projects with time on them: a project nobody
     * touched this week is not a zero worth a row — unlike an employee, where the zero IS the
     * answer to "did anybody track anything".
     *
     * @return list<array<string, mixed>>
     */
    private function byProject($user, Carbon $date, Carbon $from, Carbon $to): array
    {
        $totals = $this->timer->secondsByGroup($user, 'project_id', $from, $to, $date);

        $projects = Project::query()
            ->whereIn('id', array_keys($totals))
            ->get(['id', 'name'])
            ->keyBy('id');

        return $this->ranked($totals, fn (int $id): ?array => match (true) {
            $projects->has($id) => [
                'id' => $id,
                'name' => (string) $projects[$id]->name,
                'href' => '/admin/projects/'.$id,
            ],
            default => null,
        });
    }

    /**
     * Hours by task. Same shape as by project, one level down.
     *
     * @return list<array<string, mixed>>
     */
    private function byTask($user, Carbon $date, Carbon $from, Carbon $to): array
    {
        $totals = $this->timer->secondsByGroup($user, 'task_id', $from, $to, $date);

        $tasks = Task::query()
            ->whereIn('tasks.id', array_keys($totals))
            ->with('project:id,name')
            ->get(['id', 'title', 'project_id'])
            ->keyBy('id');

        return $this->ranked($totals, fn (int $id): ?array => match (true) {
            $tasks->has($id) => [
                'id' => $id,
                'name' => (string) $tasks[$id]->title,
                'meta' => $tasks[$id]->project?->name,
                'href' => '/admin/tasks/'.$id,
            ],
            default => null,
        });
    }

    /**
     * Sort a grouped total by the week's hours and cap it.
     *
     * This orders **things** — projects and tasks — and never people: `byEmployee()` above sorts
     * by name for exactly that reason. A list of projects by hours spent is a workload question;
     * the same list of people would be a league table, which Part H forbids.
     *
     * @param  array<int, array{today: int, week: int, pending_week: int}>  $totals
     * @param  callable(int): (array<string, mixed>|null)  $describe
     * @return list<array<string, mixed>>
     */
    private function ranked(array $totals, callable $describe): array
    {
        $rows = [];

        foreach ($totals as $id => $seconds) {
            $described = $describe((int) $id);

            if ($described === null) {
                continue;
            }

            $rows[] = [...$described, ...$this->seconds($seconds)];
        }

        usort($rows, fn (array $a, array $b): int => $b['week_seconds'] <=> $a['week_seconds']);

        return array_slice($rows, 0, self::BREAKDOWN_LIMIT);
    }

    /**
     * The three numbers every breakdown row carries. Pending is beside the total, never inside
     * it (decision 4-7) — and never hidden, because an employee's waiting afternoon has to be
     * visible to the person who can sign it off.
     *
     * @param  array{today: int, week: int, pending_week: int}|null  $seconds
     * @return array<string, int>
     */
    private function seconds(?array $seconds): array
    {
        return [
            'today_seconds' => (int) ($seconds['today'] ?? 0),
            'week_seconds' => (int) ($seconds['week'] ?? 0),
            'pending_week_seconds' => (int) ($seconds['pending_week'] ?? 0),
        ];
    }

    /**
     * @param  EloquentCollection<int, TimeEntry>  $entries
     * @return list<array<string, mixed>>
     */
    private function entries(Request $request, EloquentCollection $entries): array
    {
        return $entries
            ->map(fn (TimeEntry $entry): array => (new TimeEntryResource($entry))->resolve($request))
            ->values()
            ->all();
    }

    /**
     * Re-resolve the entry through the visibility scope, so one the requester may not see is
     * ABSENT rather than refused. Route-model binding found it by id; this decides whether it
     * exists as far as this person is concerned (Part C §1).
     */
    private function visible(Request $request, TimeEntry $entry): TimeEntry
    {
        $visible = TimeEntry::query()
            ->visibleTo($request->user())
            ->with(['employee.user:id,name'])
            ->whereKey($entry->getKey())
            ->first();

        if ($visible === null) {
            throw new NotFoundHttpException;
        }

        return $visible;
    }

    /**
     * The day the page is reporting on. A mistyped value falls back to today rather than
     * throwing — the same rule the attendance roster keeps, and for the same reason.
     */
    private function date(Request $request): Carbon
    {
        $value = trim((string) $request->query('date', ''));

        if ($value === '') {
            return Carbon::today();
        }

        try {
            $date = Carbon::createFromFormat('Y-m-d', $value)->startOfDay();
        } catch (\Throwable) {
            return Carbon::today();
        }

        // Carbon rolls a bad day over rather than refusing it, so `2026-09-31` parses as the
        // first of October. The round trip is what makes this strict.
        return $date->toDateString() === $value ? $date : Carbon::today();
    }

    /**
     * The week containing `$date`, Sunday to Saturday — the same week the seeded schedules run
     * (Sunday to Thursday), so "this week" on this screen and a working week in Bangladesh are
     * the same seven days.
     *
     * @return array{Carbon, Carbon}
     */
    private function week(Carbon $date): array
    {
        $from = $date->copy()->startOfWeek(Carbon::SUNDAY);

        return [$from, $from->copy()->addDays(6)];
    }

    /** `2h 30m`, `45m`, `0m` — the same reading `formatSeconds()` gives in the Vue. */
    private function duration(int $seconds): string
    {
        $minutes = intdiv($seconds, 60);
        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        if ($hours === 0) {
            return $rest.'m';
        }

        return $rest === 0 ? $hours.'h' : $hours.'h '.$rest.'m';
    }
}
