<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One billed period of a project's automatic income: what came of it (recorded, skipped because
 * the month was locked, skipped because the amount was zero) and the income it recorded, if any.
 *
 * (project, kind, period) is unique, so a period is billed once. The row outlives its income
 * (`income_id` goes NULL on delete), which is what stops a deleted auto income being recreated.
 * See `ProjectIncomeService`.
 *
 * @property int $id
 * @property int $project_id
 * @property string $kind
 * @property Carbon $period
 * @property int|null $income_id
 * @property string $outcome
 */
#[Fillable([
    'project_id',
    'kind',
    'period',
    'income_id',
    'outcome',
])]
class ProjectIncomeRun extends Model
{
    protected $table = 'project_income_runs';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period' => 'date',
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
     * @return BelongsTo<Income, $this>
     */
    public function income(): BelongsTo
    {
        return $this->belongsTo(Income::class);
    }
}
