<?php

namespace App\Models;

use App\Support\GenerationOutcome;
use App\Support\RecurrenceRule;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attempt at one template for one period.
 *
 * Append-only by convention rather than by grant (unlike `audit_logs`, which the migration
 * really does revoke UPDATE on): nothing in the application updates a row here, because an
 * attempt is a thing that happened and happened once.
 */
#[Fillable([
    'recurring_task_id',
    'period',
    'outcome',
    'task_id',
    'previous_open_task_id',
    'message',
])]
class RecurringGenerationLog extends Model
{
    /** The master prompt's spelling; singular, unlike every other table here. */
    protected $table = 'recurring_generation_log';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'outcome' => GenerationOutcome::class,
        ];
    }

    /**
     * @return BelongsTo<RecurringTask, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(RecurringTask::class, 'recurring_task_id');
    }

    /**
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'task_id');
    }

    /**
     * Last period's instance, still open at the moment this one was generated. The warning half
     * of the row — see the migration.
     *
     * @return BelongsTo<Task, $this>
     */
    public function previousOpenTask(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'previous_open_task_id');
    }

    /**
     * @param  Builder<RecurringGenerationLog>  $query
     * @return Builder<RecurringGenerationLog>
     */
    public function scopeWarnings(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->where('outcome', '!=', GenerationOutcome::Generated->value)
            ->orWhereNotNull('previous_open_task_id'));
    }

    public function periodLabel(): ?string
    {
        return RecurrenceRule::labelForPeriod($this->period);
    }

    /**
     * Whether this row is something somebody should look at: a skip, or a generation that
     * happened while the previous period was still open.
     */
    public function isWarning(): bool
    {
        return $this->outcome?->isWarning() === true || $this->previous_open_task_id !== null;
    }
}
