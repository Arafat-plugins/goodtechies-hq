<?php

use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| The index audit (Phase 12 — the performance pass)
|--------------------------------------------------------------------------
|
| Part E, Phase 12 Backend: "performance pass (N+1 audit, indexes)".
|
| **PostgreSQL does not index a foreign key.** The constraint is enforced
| against the referenced table's key; the referencing column is left bare. That
| is how `audit_logs` reached Phase 12 with one index on it (decision 12-19), and
| it is why the audit here is a query against `pg_constraint` and `pg_index`
| rather than a read of the migrations.
|
| The audit's own conclusion was mostly that the schema is right: of 33 bare
| foreign keys, 31 are correctly bare (see
| database/migrations/2026_10_26_0001_add_hot_path_indexes.php for the list and
| the two groups they fall into). This file pins the two that were not, pins the
| three the audit-viewer slice added so a later migration cannot quietly drop
| them, and — the part that will earn its keep — states the RULE that found them,
| so the next table read by a new screen is checked the same way.
|
| Every constant and helper is prefixed IDX_ / idx*, because Pest declares both
| globally across the whole suite (AGENTS.md).
|
*/

/**
 * Every index on a table, as `{columns} => index name`, read from the live schema.
 *
 * `pg_get_indexdef` rather than Laravel's schema builder: an expression index and a partial index
 * both exist in this schema and neither survives a column-name listing intact.
 *
 * @return array<string, string>
 */
function idxIndexesOn(string $table): array
{
    $rows = DB::select(
        'SELECT i.relname AS name, pg_get_indexdef(i.oid) AS definition
         FROM pg_class t
         JOIN pg_index x ON x.indrelid = t.oid
         JOIN pg_class i ON i.oid = x.indexrelid
         WHERE t.relname = ? AND t.relkind = \'r\'',
        [$table],
    );

    return collect($rows)->mapWithKeys(fn ($row): array => [$row->name => $row->definition])->all();
}

/** Is there an index on `$table` whose definition mentions every one of `$columns`, in order? */
function idxHasIndexOver(string $table, array $columns): bool
{
    foreach (idxIndexesOn($table) as $definition) {
        $inside = substr($definition, (int) strpos($definition, '('));
        $position = 0;
        $ok = true;

        foreach ($columns as $column) {
            $found = strpos($inside, $column, $position);

            if ($found === false) {
                $ok = false;
                break;
            }

            $position = $found + strlen($column);
        }

        if ($ok) {
            return true;
        }
    }

    return false;
}

/*
|--------------------------------------------------------------------------
| The two this slice added
|--------------------------------------------------------------------------
*/

it('indexes the two append-only tables a screen filters by person and orders by time', function (string $table, array $columns, string $screen): void {
    expect(idxHasIndexOver($table, $columns))->toBeTrue(
        $table.' has no index over ('.implode(', ', $columns).'), which is what '.$screen.' reads it by.',
    );
})->with([
    // ProfileController::show() — `where user_id = ? order by created_at desc, id desc limit N`,
    // on a table that had NO index at all beyond its primary key and that grows on every login
    // AND every failed login (RecordFailedLogin). `throttle:login` bounds the rate at which an
    // unauthenticated visitor can add rows to it; it does not bound the total.
    'login history, by person and time' => ['login_history', ['user_id', 'created_at'], 'Profile → login history'],
    // Employee\DashboardController::recentActivity(), decision 10-45 — the card every employee
    // sees every morning. `activity_logs` had (object_type, object_id), which is the task
    // timeline, and nothing for the other direction.
    'activity by actor and time' => ['activity_logs', ['actor_id', 'created_at'], 'the employee dashboard’s Recent activity'],
]);

/*
|--------------------------------------------------------------------------
| And the three the audit viewer added are still there
|--------------------------------------------------------------------------
*/

it('keeps the audit viewer’s three filter indexes', function (array $columns): void {
    expect(idxHasIndexOver('audit_logs', $columns))->toBeTrue(
        'audit_logs lost its index over ('.implode(', ', $columns).').',
    );
})->with([
    'the default ordering and the date range' => [['created_at']],
    'the who-did-this filter' => [['actor_id']],
    'the what-was-this-done-to filter' => [['target_type', 'target_id']],
]);

/*
|--------------------------------------------------------------------------
| The rule that found them
|--------------------------------------------------------------------------
*/

/**
 * The audit itself, executable.
 *
 * This does NOT assert that every foreign key is indexed — 31 correctly are not, and a test that
 * demanded it would be answered by adding 31 write costs for reads that never happen. What it
 * asserts is that the **set of bare foreign keys has not grown**: a new migration that adds a
 * referencing column which a screen then filters on shows up here as a name this test has never
 * seen, and the question "is this one hot?" gets asked once, on the day the column is added,
 * rather than on the day a client's list view gets slow.
 *
 * Adding a row to the list below is the correct way to pass this test. Doing it without reading
 * the two groups in the migration's docblock is not.
 */
const IDX_ACCEPTED_BARE_FOREIGN_KEYS = [
    // Provenance: read forwards through a belongsTo, never as a predicate. The parents (`users`,
    // `employees`) are never deleted — Part B §3 rule 11 is deactivate, never delete — so the
    // referential check on a parent DELETE, the other reason a bare FK hurts, cannot fire.
    'attendance_records.edited_by',
    'employee_salaries.set_by',
    'expenses.recorded_by',
    'files.uploaded_by',
    'income.recorded_by',
    'messages.author_id',
    'payroll_periods.lock_reversed_by',
    'recurring_tasks.created_by',
    'recurring_tasks.default_assignee_id',
    'task_checklists.completed_by',
    'task_dependencies.created_by',
    'task_links.created_by',
    'tasks.completed_by',
    'tasks.created_by',
    'tasks.first_completed_by',
    'tasks.work_summary_by',
    'time_entries.approved_by',
    'time_entries.edited_by',
    'time_entries.rejected_by',

    // Already served by a composite whose LEADING column is the one every query supplies.
    // `project_members.employee_id` is the one worth naming: it looked like the worst gap in the
    // schema, because Project::scopeForEmployee() and ProjectPolicy::isAssigned() both read it on
    // every employee's task list — but both go through the `members` relation, so the SQL is
    // correlated on `project_id` as well and `(project_id, employee_id)` answers it.
    'employees.manager_id',
    'employees.role_id',
    'leave_balances.leave_type_id',
    'leave_requests.approver_id',
    'leave_requests.leave_type_id',
    'project_members.employee_id',
    'projects.pm_id',
    'recurring_generation_log.previous_open_task_id',
    'recurring_generation_log.task_id',
    'role_permissions.permission_id',
    'user_project_permissions.permission_id',
    'expenses.category_id,category_kind',
    'income.category_id,category_kind',
];

it('has not grown a new unindexed foreign key since the audit', function (): void {
    $bare = collect(DB::select(<<<'SQL'
        SELECT c.conrelid::regclass::text AS tbl,
               (SELECT string_agg(a.attname, ',' ORDER BY x.ord)
                FROM unnest(c.conkey) WITH ORDINALITY AS x(attnum, ord)
                JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = x.attnum) AS cols
        FROM pg_constraint c
        WHERE c.contype = 'f'
          AND NOT EXISTS (
              SELECT 1 FROM pg_index i
              WHERE i.indrelid = c.conrelid
                AND (i.indkey::int2[])[0:cardinality(c.conkey) - 1] @> c.conkey
                AND (i.indkey::int2[])[0:cardinality(c.conkey) - 1] <@ c.conkey
          )
    SQL))
        ->map(fn ($row): string => $row->tbl.'.'.$row->cols)
        ->sort()
        ->values()
        ->all();

    $unexpected = array_values(array_diff($bare, IDX_ACCEPTED_BARE_FOREIGN_KEYS));

    expect($unexpected)->toBe([], implode("\n", [
        'These foreign keys have no index and are not on the audited list:',
        '  '.implode("\n  ", $unexpected),
        '',
        'PostgreSQL does not index a foreign key. Decide which group each one is in — read the',
        'docblock on database/migrations/2026_10_26_0001_add_hot_path_indexes.php — then either',
        'add an index migration or add the column to IDX_ACCEPTED_BARE_FOREIGN_KEYS with a reason.',
    ]));

    // The other direction: a name on the list that no longer appears means somebody indexed it
    // (fine, tidy the list) or dropped the column. Either way the list is stale.
    expect(array_values(array_diff(IDX_ACCEPTED_BARE_FOREIGN_KEYS, $bare)))->toBe(
        [],
        'IDX_ACCEPTED_BARE_FOREIGN_KEYS names foreign keys that are now indexed or gone. Remove them.',
    );
});
