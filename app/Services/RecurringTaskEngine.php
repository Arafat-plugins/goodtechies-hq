<?php

namespace App\Services;

use App\Models\Project;
use App\Models\RecurringGenerationLog;
use App\Models\RecurringTask;
use App\Models\Task;
use App\Models\User;
use App\Support\GenerationOutcome;
use App\Support\RecurrenceRule;
use App\Support\RoleName;
use App\Support\TaskStatus;
use App\Support\UserStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * The recurring task engine: monthly retainer work that generates itself.
 *
 * The audience is one agency with a handful of retainers. Nobody watches the 00:05 run. What
 * they see is that October's maintenance task is simply there on the 1st — once — with its
 * checklist, on the right person's plate, and a notification to say so. Everything below exists
 * to make that sentence true on the second month as reliably as on the first.
 *
 * ## It creates tasks the only way tasks are created
 *
 * Through `TaskService::create()`, with an actor, a gate check and an audit trail, exactly like
 * the form on the Admin board. This repo has refused a second creation path twice (decisions 2-9
 * and 2-36) and this is not the third attempt: the engine chooses WHO and WHAT, and TaskService
 * decides whether that is allowed and writes the row. A status still moves only through
 * `transition()`; nothing here moves one.
 *
 * ## The duplicate rule is a constraint, not an `if`
 *
 * Two things stop a period being generated twice, and only one of them is load-bearing:
 *
 *   1. **`tasks_recurring_task_period_unique`.** `TaskService::create()` writes
 *      `recurring_task_id` and `recurring_period` in the same INSERT as the task, so the index
 *      is checked on that row. Two schedulers on two boxes, a retried queue job and an Admin
 *      pressing "Generate now" in the same second cannot get past it, because the database
 *      serialises them whatever the application believed a moment earlier.
 *   2. **The existing-instance check below**, which runs first and turns the ordinary case — a
 *      second run later the same day — into a readable log row instead of an exception. It is
 *      the nice error. It is not the guarantee, and `generate()` catches the constraint
 *      violation and logs the identical outcome precisely so that the two cases are
 *      indistinguishable from outside.
 *
 * ## Stop means stop, out loud
 *
 * §21: a cancelled project stops generating immediately; an ended one has its rule stop too. The
 * engine derives that from the project on every run rather than flipping `active` off, for two
 * reasons: a derived stop is automatically correct the moment the project is unarchived, and a
 * stored one would leave an unarchived project silently generating nothing. Either way the
 * refusal is WRITTEN — `skipped_project_closed` with the status named in the message — because a
 * month that produced nothing and said nothing is indistinguishable from a broken scheduler.
 *
 * ## Time travel is the test, never the implementation
 *
 * Every date this class uses comes from its `$asOf` parameter, which defaults to `Carbon::today()`
 * and is threaded all the way down into RecurrenceRule. There is no branch anywhere that asks
 * whether it is running under test. The two-consecutive-months test sets Carbon's test-now and
 * runs the real command twice.
 */
class RecurringTaskEngine
{
    /**
     * Postgres' unique_violation. The one error code this class treats as an answer rather than
     * as a failure.
     */
    private const UNIQUE_VIOLATION = '23505';

    public function __construct(private readonly TaskService $tasks) {}

    /*
    |--------------------------------------------------------------------------
    | What to attempt
    |--------------------------------------------------------------------------
    */

    /**
     * The templates today's run should attempt.
     *
     * A template is due when the rule's generation day inside the CURRENT period has arrived,
     * and then on exactly two kinds of day:
     *
     *   - **the generation day itself**, every time. Two runs that morning are two attempts, the
     *     second of which finds the first's task and logs the duplicate warning the spec asks
     *     for. This is the case the spec's own test names: "run twice → one task + warning log".
     *   - **any later day of the period on which no attempt has been recorded at all.** That is
     *     the catch-up: a server that was down on the 1st still generates October on the 2nd. It
     *     asks "attempted", not "generated", so a period that was refused because the project is
     *     cancelled is refused once and not on all thirty remaining mornings.
     *
     * Inactive templates are not here; `active` is the toggle that means "do not run this", and
     * a row a day saying so would be noise. `skipped_inactive` is therefore reachable only
     * through a forced "Generate now", which is the one place somebody has asked and deserves an
     * answer.
     *
     * Templates on cancelled and archived projects ARE here, so that the stop is logged rather
     * than being a silence — see generate().
     *
     * @return Collection<int, RecurringTask>
     */
    public function due(?Carbon $asOf = null): Collection
    {
        $asOf = $this->asOf($asOf);

        return RecurringTask::query()
            ->active()
            ->with(['project', 'defaultAssignee'])
            ->orderBy('id')
            ->get()
            ->filter(fn (RecurringTask $template): bool => $this->isDue($template, $asOf))
            ->values();
    }

    /**
     * See due(). Extracted so that the rule is one readable paragraph rather than a closure.
     */
    private function isDue(RecurringTask $template, Carbon $asOf): bool
    {
        $rule = $template->rule();
        $periodStart = $rule->periodStart($asOf);
        $generateOn = $rule->generationDate($periodStart);

        if ($asOf->lt($generateOn)) {
            return false;
        }

        return $asOf->isSameDay($generateOn)
            || ! $template->hasAttempted($rule->periodKey($asOf));
    }

    /*
    |--------------------------------------------------------------------------
    | One attempt
    |--------------------------------------------------------------------------
    */

    /**
     * Attempt one template for the period containing `$asOf`, and write exactly one log row.
     *
     * `$force` is the "Generate now" button: it skips due(), which is the only thing it skips.
     * Every other rule — the project's state, the existing instance, the unique index — applies
     * identically, because a button that could produce a duplicate that the scheduler cannot is
     * a button that will eventually be pressed.
     *
     * Deliberately NOT wrapped in a transaction of its own. `TaskService::create()` has one, and
     * the unique violation that may come out of it has to roll that transaction back and leave
     * this method free to write the log row that explains it. An outer transaction would be
     * poisoned by the same error and take the explanation down with it.
     */
    public function generate(RecurringTask $template, ?Carbon $asOf = null, bool $force = false): RecurringGenerationLog
    {
        $asOf = $this->asOf($asOf);
        $rule = $template->rule();
        $period = $rule->periodKey($asOf);

        if (! $template->active) {
            return $this->record($template, $period, GenerationOutcome::SkippedInactive, [
                'message' => 'The template is switched off.',
            ]);
        }

        // Re-read rather than trusting a relation loaded minutes ago in the command: the whole
        // point of this check is that it reflects the project as it is NOW, and a queue job may
        // run a while after the sweep that dispatched it.
        $project = $template->project()->first();
        $stop = $this->stopReason($project);

        if ($stop !== null) {
            $template->refreshNextRunAt($asOf);

            return $this->record($template, $period, GenerationOutcome::SkippedProjectClosed, [
                'message' => $stop,
            ]);
        }

        // §6's flag: last period's task is still open. A WARNING, never a refusal — it is worth
        // saying and it is not a reason to skip this month, so it is read before the create and
        // carried onto whichever row comes out.
        $previousOpen = $this->previousOpenInstance($template, $period);

        // The nice error. The index below is the guarantee; this is what makes the second run of
        // an ordinary morning a sentence instead of a stack trace.
        $existing = $this->instanceFor($template, $period);

        if ($existing !== null) {
            $template->refreshNextRunAt($asOf);

            return $this->record($template, $period, GenerationOutcome::SkippedDuplicate, [
                'task_id' => $existing->getKey(),
                'previous_open_task_id' => $previousOpen?->getKey(),
                'message' => sprintf(
                    'An instance for %s already exists (task #%d).',
                    RecurrenceRule::labelForPeriod($period) ?? $period,
                    (int) $existing->getKey(),
                ),
            ]);
        }

        $actor = $this->actorFor($template, $project);

        if ($actor === null) {
            return $this->record($template, $period, GenerationOutcome::SkippedNoActor, [
                'message' => 'Nobody active may create tasks on this project.',
            ]);
        }

        try {
            $task = $this->createInstance($actor, $template, $rule, $asOf, $period);
        } catch (QueryException $exception) {
            // The race. Another scheduler, a retried job or a "Generate now" got past the same
            // check a moment ago and committed first; the index refused this INSERT. Any other
            // database error is a real failure and is rethrown untouched.
            if (! $this->isDuplicate($exception)) {
                throw $exception;
            }

            $template->refreshNextRunAt($asOf);

            return $this->record($template, $period, GenerationOutcome::SkippedDuplicate, [
                'task_id' => $this->instanceFor($template, $period)?->getKey(),
                'previous_open_task_id' => $previousOpen?->getKey(),
                'message' => sprintf(
                    'Another run created %s first; the unique index refused this one.',
                    RecurrenceRule::labelForPeriod($period) ?? $period,
                ),
            ]);
        }

        $template->refreshNextRunAt($asOf);

        return $this->record($template, $period, GenerationOutcome::Generated, [
            'task_id' => $task->getKey(),
            'previous_open_task_id' => $previousOpen?->getKey(),
            'message' => $previousOpen === null
                ? null
                : sprintf(
                    'Generated, but the previous period (%s) is still open as task #%d.',
                    RecurrenceRule::labelForPeriod($previousOpen->recurring_period) ?? (string) $previousOpen->recurring_period,
                    (int) $previousOpen->getKey(),
                ),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | The rules behind the attempt
    |--------------------------------------------------------------------------
    */

    /**
     * Create the instance, through the one door tasks come through.
     *
     * The task is born in TO DO rather than BACKLOG — §6: "New task lands in assignee's TO DO
     * with due date per rule" — and TO DO is one of TaskService::BIRTH_STATUSES, so no
     * transition is involved and the status guard is never touched.
     *
     * The checklist is replayed through addChecklistItem() for the same reason the task goes
     * through create(): it is the only thing that writes a checklist item, it records the
     * activity line, and a template that produced items some other way would produce items that
     * behave differently from hand-made ones.
     */
    private function createInstance(
        User $actor,
        RecurringTask $template,
        RecurrenceRule $rule,
        Carbon $asOf,
        string $period,
    ): Task {
        $periodStart = $rule->periodStart($asOf);

        $task = $this->tasks->create($actor, [
            'project_id' => $template->project_id,
            'title' => $this->renderTitle($template, $period, $asOf),
            'status' => TaskStatus::Todo->value,
            'start_date' => $periodStart->toDateString(),
            'due_date' => $rule->dueDate($periodStart)->toDateString(),
            'recurring_task_id' => $template->getKey(),
            'recurring_period' => $period,
        ], $template->default_assignee_id === null ? [] : [(int) $template->default_assignee_id]);

        foreach ($template->checklistItems() as $item) {
            $this->tasks->addChecklistItem($actor, $task, $item);
        }

        return $task->refresh();
    }

    /**
     * The generated task's title.
     *
     * Three placeholders and no cleverness. A template with none of them produces the same title
     * every period, which is allowed and is usually a mistake — but it is the template author's
     * mistake to make, and silently appending a period to a title that did not ask for one would
     * be worse.
     */
    private function renderTitle(RecurringTask $template, string $period, Carbon $asOf): string
    {
        $title = strtr((string) $template->title_template, [
            '{period}' => RecurrenceRule::labelForPeriod($period) ?? $period,
            '{project}' => (string) ($template->project?->name ?? ''),
            '{date}' => $asOf->toDateString(),
        ]);

        $title = trim($title);

        // A template whose whole title was a placeholder that resolved to nothing still has to
        // produce a task somebody can find.
        return $title === '' ? 'Recurring task — '.$period : $title;
    }

    /**
     * Why this project may not generate, or null when it may.
     *
     * **What "ended" means here.** The master prompt says "stops immediately when the project is
     * cancelled/ended" (§6) and, separately, "Recurring project ends → rule deactivated" (§21),
     * without ever defining ended. The definition chosen is *the project's status is no longer
     * open* — `ProjectStatus::isOpen()`, which is Active or On Hold — plus `archived_at`, which
     * is archived by another name. So Completed, Cancelled and Archived all stop.
     *
     * Two deliberate exclusions:
     *
     *   - **On Hold keeps generating.** It is the one status that means "paused, and coming
     *     back"; the spec's stop list never names it, and a retainer that silently stopped
     *     producing work while a client thought about a budget would be discovered a month late.
     *   - **A passed `deadline` is NOT ended.** Every retainer in the seed data carries a rolling
     *     deadline a few days out. Treating a date that has slipped as the end of the engagement
     *     would stop every retainer in the agency within a week, silently, which is the exact
     *     failure this module exists to prevent. The end of a retainer is a decision somebody
     *     makes, and it is spelled Completed, Cancelled or Archived.
     *
     * **Public because the templates screen asks it too** (Phase 3's screens: "a stopped template
     * says why"). A stop is derived, never stored, so a row whose project was cancelled this
     * morning looks perfectly active in the list until the next run writes the refusal down — and
     * a screen that worked the answer out for itself would be the second copy of §21 this module
     * exists to avoid. It asks the same method the run asks, so the sentence a reader sees before
     * the run is the sentence the log will carry after it. Nothing about the method changed but
     * its visibility.
     */
    public function stopReason(?Project $project): ?string
    {
        if ($project === null) {
            return 'The project no longer exists.';
        }

        if ($project->isArchived()) {
            return 'The project is archived; an archived project is read-only.';
        }

        if ($project->status?->isOpen() !== true) {
            return sprintf(
                'The project is %s; recurring generation stops when a project is cancelled, completed or archived.',
                $project->status?->label() ?? 'in an unknown state',
            );
        }

        return null;
    }

    /**
     * This template's instance for this period, if there is one — including soft-deleted ones.
     *
     * `withTrashed()` mirrors the unique index exactly, which is the point: the index counts
     * deleted rows, so a check that did not would report "no instance" and then be refused by
     * the database, turning every deleted period into a race that is not a race. Counting them
     * is also the behaviour somebody wants — a task deleted on purpose does not come back at
     * five past midnight tomorrow.
     */
    private function instanceFor(RecurringTask $template, string $period): ?Task
    {
        return Task::withTrashed()
            ->where('recurring_task_id', $template->getKey())
            ->where('recurring_period', $period)
            ->first();
    }

    /**
     * The most recent EARLIER instance that is still open, archived tasks aside.
     *
     * The string comparison is chronological because the period keys of one template all share a
     * shape and every shape sorts lexicographically into date order — see RecurrenceRule. That
     * is what saves a second date column whose only job would be to agree with this one.
     */
    private function previousOpenInstance(RecurringTask $template, string $period): ?Task
    {
        return Task::query()
            ->where('recurring_task_id', $template->getKey())
            ->where('recurring_period', '<', $period)
            ->open()
            ->notArchived()
            ->orderByDesc('recurring_period')
            ->first();
    }

    /**
     * Who the engine creates the task AS.
     *
     * A task needs an actor: TaskService asks the gate, writes `created_by`, and that column is
     * the "original assigner" who hears about the completion. The scheduler is nobody, so it
     * borrows somebody, in the order of who would have created this task by hand:
     *
     *   1. the person who set the template up,
     *   2. the project's PM,
     *   3. any active Admin.
     *
     * Each candidate is checked against the same gate a controller would check, so this cannot
     * become a way to create a task somebody was not allowed to create. If none of them passes,
     * the engine refuses and says so rather than inventing a system user with permissions nobody
     * granted.
     */
    private function actorFor(RecurringTask $template, Project $project): ?User
    {
        $candidates = new Collection([
            $template->author()->first(),
            $project->pm?->user,
        ]);

        $candidates = $candidates->merge(User::query()
            ->where('status', UserStatus::Active->value)
            ->whereHas('employee.role', fn (Builder $role) => $role->where('name', RoleName::ADMIN->value))
            ->orderBy('id')
            ->get());

        return $candidates
            ->filter(fn (mixed $user): bool => $user instanceof User && $user->isActive())
            ->first(fn (User $user): bool => Gate::forUser($user)->allows('create', Task::class));
    }

    private function isDuplicate(QueryException $exception): bool
    {
        return (string) ($exception->errorInfo[0] ?? $exception->getCode()) === self::UNIQUE_VIOLATION;
    }

    /**
     * Write the one row this attempt is entitled to.
     *
     * @param  array{task_id?: int|string|null, previous_open_task_id?: int|string|null, message?: string|null}  $extra
     */
    private function record(
        RecurringTask $template,
        string $period,
        GenerationOutcome $outcome,
        array $extra = [],
    ): RecurringGenerationLog {
        return RecurringGenerationLog::create([
            'recurring_task_id' => $template->getKey(),
            'period' => $period,
            'outcome' => $outcome,
            'task_id' => $extra['task_id'] ?? null,
            'previous_open_task_id' => $extra['previous_open_task_id'] ?? null,
            'message' => $extra['message'] ?? null,
        ]);
    }

    /**
     * The date every rule is measured against: a parameter, never a call to today() buried in a
     * query. It is what makes a two-month time-travel test possible without a branch that only
     * runs under test.
     */
    private function asOf(?Carbon $asOf): Carbon
    {
        return ($asOf ?? Carbon::today())->copy()->startOfDay();
    }
}
