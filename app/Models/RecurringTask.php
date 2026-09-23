<?php

namespace App\Models;

use App\Support\GenerationOutcome;
use App\Support\RecurrenceFrequency;
use App\Support\RecurrenceRule;
use Database\Factories\RecurringTaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A recurring task template: "this project generates this task every period".
 *
 * It holds no state about what it has produced. `instances()` are the tasks, `log()` is the
 * attempts, and both are queries — so "has October happened" has exactly one answer however it
 * is asked.
 */
#[Fillable([
    'project_id',
    'title_template',
    'checklist_template',
    'frequency',
    'recurrence_rule',
    'default_assignee_id',
    'created_by',
    'active',
])]
class RecurringTask extends Model
{
    /** @use HasFactory<RecurringTaskFactory> */
    use HasFactory;

    /**
     * How many items a checklist template may carry. The spec's own example is eight; this is a
     * bound on the field, not a business rule.
     */
    public const MAX_CHECKLIST_ITEMS = 30;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'checklist_template' => 'array',
            'recurrence_rule' => 'array',
            'frequency' => RecurrenceFrequency::class,
            'next_run_at' => 'datetime',
            'active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function defaultAssignee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'default_assignee_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Every task this template has produced, newest period first.
     *
     * Unscoped by requester on purpose, exactly like Project::tasks(): it is the relation, not a
     * view. The period keys of one template all share a shape, so ordering by the string is
     * ordering by time — see RecurrenceRule.
     *
     * @return HasMany<Task, $this>
     */
    public function instances(): HasMany
    {
        return $this->hasMany(Task::class, 'recurring_task_id')->orderByDesc('recurring_period');
    }

    /**
     * Every attempt, newest first.
     *
     * @return HasMany<RecurringGenerationLog, $this>
     */
    public function log(): HasMany
    {
        return $this->hasMany(RecurringGenerationLog::class, 'recurring_task_id')->orderByDesc('id');
    }

    /**
     * The rule, as the object that answers every date question about it.
     *
     * Rebuilt from the stored JSON each time rather than cached on the instance: a template
     * edited mid-request must not go on answering with the rule it had when it was loaded.
     */
    public function rule(): RecurrenceRule
    {
        $stored = is_array($this->recurrence_rule) ? $this->recurrence_rule : [];

        // The column is the authority on WHICH case this is; the JSON carries the parameters and
        // a copy of the case for readability. If the two ever disagree, the column wins, because
        // that is the one a query filtered on.
        return RecurrenceRule::fromArray(
            ['frequency' => $this->frequency?->value ?? RecurrenceFrequency::Monthly->value] + $stored,
        );
    }

    /**
     * Store a rule: the case in its column, the parameters in the JSON, from one object so the
     * two can never be written out of step.
     */
    public function setRule(RecurrenceRule $rule): static
    {
        $this->frequency = $rule->frequency;
        $this->recurrence_rule = $rule->toArray();

        return $this;
    }

    /**
     * The checklist template as a clean ordered list of titles.
     *
     * @return list<string>
     */
    public function checklistItems(): array
    {
        $items = is_array($this->checklist_template) ? $this->checklist_template : [];

        return array_values(array_filter(
            array_map(fn (mixed $item): string => trim((string) $item), $items),
            fn (string $item): bool => $item !== '',
        ));
    }

    /**
     * Has this template been ATTEMPTED for this period — whatever the outcome was?
     *
     * The narrowest question the engine can ask of its own history, and the only one it asks.
     * "Attempted", not "generated": a period that was refused because the project is cancelled
     * has been dealt with, and re-refusing it every morning for a year would bury the one row
     * that said why in three hundred identical ones.
     */
    public function hasAttempted(string $period): bool
    {
        return $this->log()->where('period', $period)->exists();
    }

    /**
     * Has this template SUCCESSFULLY generated this period?
     */
    public function hasGenerated(string $period): bool
    {
        return $this->log()
            ->where('period', $period)
            ->where('outcome', GenerationOutcome::Generated->value)
            ->exists();
    }

    /**
     * @param  Builder<RecurringTask>  $query
     * @return Builder<RecurringTask>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    /**
     * Keep `next_run_at` in step with the rule. Called by the engine after every attempt, so the
     * preview a screen shows is never older than the last run.
     */
    public function refreshNextRunAt(Carbon $after): static
    {
        $this->forceFill(['next_run_at' => $this->rule()->nextRunAt($after)])->save();

        return $this;
    }
}
