<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The remote timer's records (master prompt Part D §7, Phase 4).
     *
     * One row is one stretch of tracked work: who, on which task, from when to when, minus the
     * time they were paused. It is written by the timer (`entry_type = auto`) or typed in
     * afterwards (`entry_type = manual`). Phase 11's activity columns — `active_minutes`,
     * `video_minutes`, `idle_minutes`, `activity_source` — are deliberately NOT here: that phase
     * is blocked pending a client amendment to spec §46, and a column added early is a column
     * something starts writing early.
     *
     * ## There is no `state` column
     *
     * Running, paused and stopped are read off the two timestamps that cause them:
     *
     *   running  ended_at is null and paused_at is null
     *   paused   ended_at is null and paused_at is not null
     *   stopped  ended_at is not null
     *
     * A `state` string beside them would be a second statement of the same three predicates, and
     * the two would disagree the first time a watchdog stopped an entry without updating one of
     * them (decision 2-37: one statement of each predicate). `TimeEntry`'s scopes are the single
     * spelling, and the partial unique index below is written against the same predicate.
     *
     * ## One open entry per employee, guaranteed by the database
     *
     * `time_entries_one_open_per_employee` is a partial unique index on `employee_id` where
     * `ended_at is null`. An `if` in the service cannot promise this — the timer widget, a
     * replayed offline batch and the watchdog can all touch the same employee in the same
     * millisecond, and two of those arrive from outside a single request. This is Phase 3's
     * decision 3-1 applied again: the check in `TimerService` exists to produce a sentence a
     * person can read, the index exists so the rule cannot be broken.
     *
     * Note that PAUSED counts as open. A paused entry is an unfinished session, and letting a
     * second one start beside it is exactly how an employee ends up with two afternoons running
     * at once.
     *
     * ## Totals ask one question: `approved_at is not null`
     *
     * An auto entry is approved by the system the moment it stops (`approved_by` null). A manual
     * entry is approved on creation when `settings.manual_time_requires_approval` is off, and
     * left null when it is on — which is what keeps it out of every total until somebody signs
     * it off. One nullable timestamp, one predicate, no `status` enum that can disagree with it.
     */
    public function up(): void
    {
        Schema::create('time_entries', function (Blueprint $table) {
            $table->id();

            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();

            // Time is always tracked against a task — that is the whole shape of §7, and a manual
            // entry names one too. Not nullable, so "what did Tapu work on" never has a blank row.
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();

            // Denormalised from the task AT WRITE TIME, on purpose. A task moved to another
            // project next month did not move last month's hours with it, and the Admin "by
            // project" views would silently rewrite history if they joined through the task.
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();

            // The local day the entry belongs to, in the app timezone (`settings.timezone`),
            // derived from `started_at` when the row is written. Stored rather than computed so
            // that "today's total" and "entries by day" are an index lookup instead of a
            // timezone conversion inside a WHERE clause.
            $table->date('work_date');

            $table->timestampTz('started_at');

            // Null while the entry is open. Set by a stop — the employee's, or the watchdog's.
            $table->timestampTz('ended_at')->nullable();

            // When the CURRENT pause began; null while running. Completed pauses are already in
            // `paused_seconds`.
            $table->timestampTz('paused_at')->nullable();

            // Every completed pause, added up. The live pause is not in here until it ends.
            $table->unsignedInteger('paused_seconds')->default(0);

            // Wall clock minus pauses, written once at stop. Null while the entry is open,
            // because an open entry's duration is a question about `now()` and not a fact about
            // the row — see TimerService::elapsedSeconds().
            $table->unsignedInteger('duration_seconds')->nullable();

            // 'auto' — the timer wrote it. 'manual' — a person typed it, with a reason.
            $table->string('entry_type');

            // The client's idempotency key, generated before the first request leaves the
            // browser. It is what makes a replayed offline batch land on the row it already
            // created instead of a second one. Manual entries get a server-generated uuid so
            // every row has exactly one key and no code path has to reason about a null.
            $table->uuid('client_uuid')->unique();

            // The running client pings every 60 s. Watchdog rule 1 reads this and nothing else:
            // an entry with no ping for `settings.heartbeat_timeout_minutes` is stopped AT this
            // timestamp, so a closed laptop logs the time it was open and not a minute more.
            $table->timestampTz('last_heartbeat_at')->nullable();

            // A flag is a fact with a reason. `flagged_at` is the fact, `flag_reason` is the
            // sentence the Time page prints — never a code the screen has to translate, because
            // a flag nobody can read is a flag nobody investigates.
            $table->timestampTz('flagged_at')->nullable();
            $table->text('flag_reason')->nullable();

            // The one predicate every total asks. Null = does not count yet.
            $table->timestampTz('approved_at')->nullable();
            // Who approved it. Null with `approved_at` set means the system did.
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();

            // Why this entry reads the way it does: the reason a manual entry was typed, or the
            // reason it was last edited. One field, because both answer the same question and
            // the full history is in `audit_logs` where an edit is recorded old-value-to-new.
            $table->text('reason')->nullable();

            $table->timestampTz('edited_at')->nullable();
            $table->foreignId('edited_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // The Time page (entries by day) and "today's total vs target".
            $table->index(['employee_id', 'work_date']);

            // The per-task rollup that keeps `tasks.tracked_seconds` honest.
            $table->index('task_id');

            // Admin's hours-by-project view, and the same question asked for a date range.
            $table->index(['project_id', 'work_date']);
        });

        // One open entry per employee — the rule an `if` cannot keep. See the docblock.
        DB::statement(<<<'SQL'
            create unique index time_entries_one_open_per_employee
            on time_entries (employee_id)
            where ended_at is null
        SQL);

        // The watchdog's sweep: every open entry, every minute. Tiny by construction — it holds
        // at most one row per remote employee.
        DB::statement(<<<'SQL'
            create index time_entries_open_heartbeat
            on time_entries (last_heartbeat_at)
            where ended_at is null
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('time_entries');
    }
};
