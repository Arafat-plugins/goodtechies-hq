<?php

namespace App\Http\Resources;

use App\Models\RecurringTask;
use App\Services\RecurringTaskEngine;
use App\Support\RecurrenceRule;
use App\Support\RecurrenceSummary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * The only way a retainer template leaves the server.
 *
 * ## Every date on this payload was computed by RecurrenceRule
 *
 * The templates screen shows when a template fires next, which period that run belongs to, and
 * when the instance it makes would be due. All three are `RecurrenceRule`'s answers, asked here
 * and sent as strings. The form in Vue produces a rule and reads a preview; it never counts a
 * day. That is the whole reason this resource carries `next_run` as a block rather than leaving
 * the screen to work it out from `frequency` and `day_of_month`.
 *
 * `next_run.at` is deliberately **not** `recurring_tasks.next_run_at`. That column is a cache the
 * engine refreshes after every attempt — useful, and stale by definition between runs, because a
 * template edited this morning has a new rule and an old column. The engine says so itself: the
 * column "is DERIVED, never the source of truth". So the screen is sent the live answer and the
 * column rides along beside it as `next_run_at_cached` for anybody comparing the two.
 *
 * ## A stopped template says why
 *
 * `stop_reason` is `RecurringTaskEngine::stopReason()` — the same method the 00:05 run calls,
 * not a second reading of §21. A cancelled project's template therefore explains itself on the
 * screen *before* the next run writes the refusal into the log, which is the point: a row that
 * looks active and produces nothing is the failure this phase exists to prevent.
 *
 * @mixin RecurringTask
 */
class RecurringTaskResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $rule = $this->resource->rule();
        $asOf = $this->asOf($request);

        return [
            'id' => $this->id,
            'project_id' => (int) $this->project_id,

            'title_template' => $this->title_template,
            // The cleaned list, not the raw column: blank rows a hand-edit left behind are not
            // checklist items, and the engine does not replay them either.
            'checklist_template' => $this->resource->checklistItems(),

            'frequency' => $rule->frequency->value,
            'frequency_label' => $rule->frequency->label(),
            // Canonical, so the editor repopulates with what the server actually stored — a rule
            // asking for the 31st comes back as the 28th, because that is the rule now.
            'recurrence_rule' => $rule->toArray(),
            'recurrence_summary' => RecurrenceSummary::for($rule),

            'default_assignee' => $this->defaultAssignee(),

            'active' => (bool) $this->active,

            'next_run' => $this->nextRun($rule, $asOf),
            // The engine's cache of the same question, for comparison only. See the docblock.
            'next_run_at_cached' => $this->next_run_at?->toIso8601String(),

            // Null when the template may run. A sentence when it may not.
            'stop_reason' => app(RecurringTaskEngine::class)->stopReason($this->resource->project),

            // The last thing that happened, which is the column the list calls "Last outcome".
            // The whole log is a second endpoint: three templates on a screen must not drag a
            // year of history along to print one row each.
            'last_run' => $this->lastRun($request),

            'created_at' => $this->created_at?->toIso8601String(),

            'permissions' => $this->permissions($request),
        ];
    }

    /**
     * When this template fires next, and what that run would produce.
     *
     * Four values, none of them arithmetic done here: `nextRunAt()` gives the date, the period
     * that date falls in gives the key and its label, and `dueDate()` gives the due date of the
     * instance. An inactive or stopped template still reports it — "it would have been the 1st"
     * is exactly what somebody switching it back on wants to know.
     *
     * @return array{at: string, period: string, period_label: string|null, due_date: string}
     */
    private function nextRun(RecurrenceRule $rule, Carbon $asOf): array
    {
        $at = $rule->nextRunAt($asOf);
        $periodStart = $rule->periodStart($at);
        $period = $rule->periodKey($at);

        return [
            'at' => $at->toDateString(),
            'period' => $period,
            'period_label' => RecurrenceRule::labelForPeriod($period),
            'due_date' => $rule->dueDate($periodStart)->toDateString(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function lastRun(Request $request): ?array
    {
        $last = $this->resource->relationLoaded('log')
            ? $this->resource->log->first()
            : $this->resource->log()->first();

        return $last === null
            ? null
            : (new RecurringGenerationLogResource($last))->resolve($request);
    }

    /**
     * Who the generated task lands on. An employee id with the user's name beside it, the
     * convention `ProjectResource` sets for `pm` and `TaskResource` for an assignee.
     *
     * @return array{id: int, name: string|null}|null
     */
    private function defaultAssignee(): ?array
    {
        $assignee = $this->resource->defaultAssignee;

        return $assignee === null ? null : ['id' => $assignee->id, 'name' => $assignee->user?->name];
    }

    /**
     * What this requester may do with this template. `RecurringTaskPolicy`, resolved per record
     * on the server — the screen renders this and never derives one from a role.
     *
     * @return array<string, bool>
     */
    private function permissions(Request $request): array
    {
        $user = $request->user();

        if ($user === null) {
            return ['can_update' => false, 'can_generate' => false];
        }

        $gate = Gate::forUser($user);

        return [
            'can_update' => $gate->allows('update', $this->resource),
            'can_generate' => $gate->allows('generate', $this->resource),
        ];
    }

    /**
     * The date the preview is measured against: today, unless a caller pinned one on the
     * request. Same escape hatch `TaskResource` uses for "overdue", and for the same reason —
     * a test must be able to say what day it is without the payload asking the clock.
     */
    private function asOf(Request $request): Carbon
    {
        $asOf = $request->attributes->get('recurring_as_of');

        return $asOf instanceof Carbon ? $asOf->copy() : Carbon::today();
    }
}
