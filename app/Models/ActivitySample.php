<?php

namespace App\Models;

use App\Support\ActivityState;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One minute of activity on a time entry (docs/extension-api.md §5).
 */
#[Fillable(['time_entry_id', 'minute_at', 'state', 'source', 'call_source'])]
class ActivitySample extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'minute_at' => 'datetime',
            'state' => ActivityState::class,
            'call_source' => 'string',
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
