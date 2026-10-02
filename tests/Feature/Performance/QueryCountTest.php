<?php

use App\Models\Conversation;
use App\Models\Employee;
use App\Models\Task;
use App\Models\User;
use App\Support\ReportKey;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Query counts per surface (Phase 12 — the performance pass)
|--------------------------------------------------------------------------
|
| Part E, Phase 12 Backend: "performance pass (N+1 audit, indexes)". An N+1 is
| not something you can read off a page; it is a number. Every figure in this
| file and in the report that came with it was produced by `DB::listen` through
| the real HTTP stack on the seeded database — not inferred from eager-load
| lists.
|
| ## Two kinds of assertion, and the second is the one that matters
|
| **Absolute ceilings** say a surface answers in fewer than N statements. They
| catch a new screen that composes itself out of forty service calls. They are
| set roughly 1.5× the measured figure, because the seed dates its work
| relatively (AGENTS.md) and a count that is 54 one morning is 57 the next.
|
| **Slope ceilings** say how much a surface grows when rows are added — measured
| by adding ten employees and ten tasks and counting again. That is what an N+1
| actually is, and it is the assertion a bigger client would have discovered for
| us. They are ceilings on the slope rather than equalities, so they still pass
| when somebody FIXES an N+1 and the slope goes to zero; they fail when somebody
| adds a worse one.
|
| ## What the audit found, for anybody reading this before the backlog
|
| Three N+1s are live and none of them is fixed here — each lives in a file this
| slice was not allowed to touch, and all three are reported for the backlog:
|
|   1. **`TaskResource` lazy-loads four `belongsTo User` relations** —
|      `created_by` (line 91), `work_summary_by` (79), `completed_by` (83) and
|      `first_completion.by` (205 via 87). `TaskService::RELATIONS` loads none of
|      them and the resource serialises them unconditionally, so a task list is
|      one to four extra queries **per row**: 39 of `/admin/tasks`'s statements
|      on 25 seeded tasks, and +1 per task added.
|   2. **`User::hasPermission()` has no per-role cache** — the key list is
|      memoised on the User INSTANCE, so any loop over users costs two queries
|      each (`$this->employee`, then the permissions join). `/messages` builds
|      two such loops (the mention list and the DM picker) and grows ~3.7
|      queries per employee in the agency.
|   3. **`WorkloadService` counts per employee** — already recorded as decision
|      10-44's `openCountsFor()` follow-up; 2 queries per employee on
|      `/admin/workload` and on the Admin dashboard's card.
|
| The sixteen reports have **no** N+1: the heaviest is 38 statements and every
| repeated SQL string appears once.
|
| Every constant and helper is prefixed PERF_ / perf*, because Pest declares both
| globally across the whole suite (AGENTS.md).
|
*/

beforeEach(function (): void {
    $this->seed();

    $this->perfAdmin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->perfYaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();
    $this->perfAccountant = User::where('email', 'accountant@goodtechies.test')->firstOrFail();
});

/**
 * Hit one URL as one user and return how many statements it ran, with the SQL.
 *
 * `DB::listen` rather than the query log: nothing is buffered into memory, and the count is of
 * statements actually sent on the connection — the session read and the route binding included,
 * because those are part of what the page costs.
 *
 * @return array{count: int, queries: list<string>}
 */
function perfMeasure(User $user, string $url): array
{
    $queries = [];

    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $response = test()->actingAs($user)->get($url);

    DB::getEventDispatcher()->forget(QueryExecuted::class);

    expect($response->status())->toBe(200, $url.' did not answer 200');

    return ['count' => count($queries), 'queries' => $queries];
}

/** The failure message: the count, the ceiling, and the SQL that got there. */
function perfReport(string $url, array $result, int $ceiling): string
{
    $repeats = collect($result['queries'])
        ->countBy()
        ->filter(fn (int $n): bool => $n > 1)
        ->sortDesc()
        ->map(fn (int $n, string $sql): string => $n.'x  '.mb_substr($sql, 0, 160))
        ->values()
        ->all();

    return implode("\n", array_merge(
        [sprintf('%s ran %d queries (ceiling %d). Repeated statements, worst first:', $url, $result['count'], $ceiling)],
        $repeats === [] ? ['  (none — every statement is distinct, so this is composition cost, not an N+1)'] : array_map(fn ($l): string => '  '.$l, $repeats),
    ));
}

/*
|--------------------------------------------------------------------------
| Absolute ceilings
|--------------------------------------------------------------------------
*/

it('answers each heavy surface within its ceiling', function (string $actor, string $url, int $ceiling): void {
    $result = perfMeasure($this->{$actor}, $url);

    expect($result['count'])->toBeLessThanOrEqual($ceiling, perfReport($url, $result, $ceiling));
})->with([
    // Measured 2026-09-26 on the seeded database; the figure is in the comment, and where it
    // says "(was N)" that is what the surface cost before `TaskService::RELATIONS` gained the
    // four `User` belongs-tos `TaskResource` had been lazy-loading on every row. The ceilings
    // were tightened at the same time: a ceiling with 40 queries of slack in it would have
    // absorbed the fix silently and let it regress just as silently.
    'Tasks list (admin)' => ['perfAdmin', '/admin/tasks', 32],                 // 22 (was 57)
    'Tasks board (admin)' => ['perfAdmin', '/admin/tasks/board', 32],          // 22 (was 57)
    'Tasks calendar (admin)' => ['perfAdmin', '/admin/tasks/calendar', 34],    // 23 (was 49)
    'Tasks Gantt (admin)' => ['perfAdmin', '/admin/tasks/gantt', 35],          // 24 (was 49)
    'Tasks list (employee)' => ['perfYaseen', '/employee/tasks', 56],          // 40 (was 51); +1 shared `clock` prop (12-84)
    'Tasks board (employee)' => ['perfYaseen', '/employee/tasks/board', 56],   // 40 (was 51); +1 `clock` (12-84)
    'Tasks Gantt (employee)' => ['perfYaseen', '/employee/tasks/gantt', 56],   // 40 (was 50); +1 `clock` (12-84)
    'My tasks (admin)' => ['perfAdmin', '/admin/tasks?scope=mine&bucket=open', 34], // was /admin/my-tasks, now a 302 here
    'Admin dashboard' => ['perfAdmin', '/admin/dashboard', 95],                // 64
    'Employee dashboard' => ['perfYaseen', '/employee/dashboard', 40],         // 22
    'Accountant dashboard' => ['perfAccountant', '/accountant/dashboard', 30], // 14
    'Messages index' => ['perfAdmin', '/messages', 60],                        // 36
    'Employees list' => ['perfAdmin', '/admin/employees', 20],                 // 9
    'Users & roles view' => ['perfAdmin', '/admin/employees?view=access', 20], // 9
    'Workload' => ['perfAdmin', '/admin/workload', 40],                        // 23
    'Reports catalogue' => ['perfAdmin', '/admin/reports', 11],                // 3; +1 `clock` (12-84)
    'Global search' => ['perfAdmin', '/search?q=seo', 25],                     // 16
]);

it('answers each of the sixteen reports within one ceiling', function (): void {
    // One ceiling for all sixteen on purpose: a report is data in one shape (decision 10-21), so
    // a builder that costs three times its neighbours is a builder that stopped using the
    // contract. Heaviest measured: website-project at 38.
    foreach (ReportKey::cases() as $key) {
        $url = '/admin/reports/'.$key->value;
        $result = perfMeasure($this->perfAdmin, $url);

        expect($result['count'])->toBeLessThanOrEqual(60, perfReport($url, $result, 60));
    }

    expect(ReportKey::cases())->toHaveCount(16);
});

it('answers a task detail and a conversation within their ceilings', function (): void {
    $task = Task::query()->whereNotNull('project_id')->firstOrFail();
    $conversation = Conversation::query()->firstOrFail();

    foreach ([
        '/admin/tasks/'.$task->getKey() => 70,        // 49
        '/messages/'.$conversation->getKey() => 35,   // 17
    ] as $url => $ceiling) {
        $result = perfMeasure($this->perfAdmin, $url);

        expect($result['count'])->toBeLessThanOrEqual($ceiling, perfReport($url, $result, $ceiling));
    }
});

/*
|--------------------------------------------------------------------------
| Slope ceilings — how much a surface grows per row
|--------------------------------------------------------------------------
*/

it('does not grow faster than its measured slope when rows are added', function (): void {
    $admin = $this->perfAdmin;

    // Ten more people on a role that already exists, and ten more tasks with a creator set —
    // `created_by` is what TaskResource lazy-loads, and a factory task without one costs nothing,
    // which is why an earlier version of this measurement read +0 and was wrong.
    $template = Employee::query()->whereNotNull('role_id')->firstOrFail();
    $projectId = Task::query()->whereNotNull('project_id')->value('project_id');

    $urls = [
        // +10 employees: measured +0. The one surface here that is genuinely flat in headcount.
        '/admin/employees' => 3,
        // +10 tasks: measured +10 before the fix — one query per row, which WAS the
        // `TaskResource` N+1. Now +0: the four `User` belongs-tos are eager-loaded, so a page of
        // ten tasks and a page of a thousand cost the same. The ceiling is 2 rather than 0 so
        // that a single new constant query is not a failure, and it would catch any return of
        // per-row growth on the first row.
        '/admin/tasks' => 2,
        // +10 employees: measured +37 (~3.7 each) — User::hasPermission() re-resolving a role's
        // key list per User instance, twice over.
        '/messages' => 45,
        // +10 employees: measured +30.
        '/admin/dashboard' => 36,
        // +10 employees: measured +20 — decision 10-44's two counts per employee.
        '/admin/workload' => 26,
    ];

    $before = [];
    foreach (array_keys($urls) as $url) {
        $before[$url] = perfMeasure($admin, $url)['count'];
    }

    for ($i = 0; $i < 10; $i++) {
        Employee::factory()->create([
            'user_id' => User::factory()->create()->getKey(),
            'role_id' => $template->role_id,
        ]);
    }

    Task::factory()->count(10)->create([
        'project_id' => $projectId,
        'created_by' => $admin->getKey(),
    ]);

    foreach ($urls as $url => $allowedGrowth) {
        $after = perfMeasure($admin, $url)['count'];
        $growth = $after - $before[$url];

        expect($growth)->toBeLessThanOrEqual($allowedGrowth, sprintf(
            '%s went from %d to %d queries (+%d) for ten more employees and ten more tasks; '
            .'the measured slope allows +%d. Something now runs a query per row.',
            $url,
            $before[$url],
            $after,
            $growth,
            $allowedGrowth,
        ));
    }
});
