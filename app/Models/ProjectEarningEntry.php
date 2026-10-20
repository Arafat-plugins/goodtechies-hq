<?php

namespace App\Models;

use App\Support\ProjectEarningKind;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One hand-recorded project financial entry: a contract value, an earned amount or an amount
 * received, in its own currency. A record of the project only — it is never an income row, and
 * nothing creates one from it.
 *
 * @property int $id
 * @property int $project_id
 * @property ProjectEarningKind $kind
 * @property string $amount
 * @property string $currency
 * @property Carbon $date
 * @property string|null $description
 * @property int $recorded_by
 * @property int|null $updated_by
 */
#[Fillable([
    'project_id',
    'kind',
    'amount',
    'currency',
    'date',
    'description',
    'recorded_by',
    'updated_by',
])]
class ProjectEarningEntry extends Model
{
    protected $table = 'project_earning_entries';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => ProjectEarningKind::class,
            'amount' => 'decimal:2',
            'date' => 'date',
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
     * @return BelongsTo<User, $this>
     */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Everything needed to put this row back, for the audit trail. The amount is a string,
     * never a float.
     *
     * @return array<string, mixed>
     */
    public function auditValues(): array
    {
        return [
            'id' => (int) $this->getKey(),
            'project_id' => (int) $this->project_id,
            'kind' => $this->kind?->value,
            'amount' => (string) $this->amount,
            'currency' => $this->currency,
            'date' => $this->date?->toDateString(),
            'description' => $this->description,
        ];
    }
}
