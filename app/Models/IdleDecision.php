<?php

namespace App\Models;

use App\Support\IdleDecisionKind;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What was decided about one idle stretch of a time entry (docs/extension-api.md §6).
 */
#[Fillable(['time_entry_id', 'idle_from', 'idle_to', 'decision', 'discarded_seconds', 'source', 'decided_at'])]
class IdleDecision extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'idle_from' => 'datetime',
            'idle_to' => 'datetime',
            'decided_at' => 'datetime',
            'decision' => IdleDecisionKind::class,
            'discarded_seconds' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<TimeEntry, $this>
     */
    public function timeEntry(): BelongsTo
    {
        return $this->belongsTo(TimeEntry::class);
    }
}
