<?php

namespace App\Models;

use Database\Factories\ProjectFinanceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A project's money, kept off the projects table itself (master prompt Part B §3 rule 7):
 * hiding it from a requester who may not see it is then a join to omit, not a field filter.
 */
#[Fillable([
    'project_id',
    'price',
    'recurring_amount',
    'billing_frequency',
    'contract_value',
    'contract_terms',
    'profitability_snapshot',
])]
class ProjectFinance extends Model
{
    /** @use HasFactory<ProjectFinanceFactory> */
    use HasFactory;

    protected $table = 'project_finance';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'recurring_amount' => 'decimal:2',
            'contract_value' => 'decimal:2',
            'profitability_snapshot' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
