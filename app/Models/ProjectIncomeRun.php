<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Historic record of income that was generated automatically from projects before 2026-11: one
 * billed period, what came of it (recorded, skipped because the month was locked, skipped because
 * the amount was zero) and the income it recorded, if any.
 *
 * Income is now entered by hand only. Nothing writes to this table any more; the model and its
 * rows are kept so that existing financial data is preserved and can still be read.
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
