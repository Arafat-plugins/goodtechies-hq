<?php

namespace App\Models;

use App\Support\SiteKind;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Seconds spent on one host (or one non-site kind) within one minute of a time entry
 * (docs/extension-api.md §5). A host only — never a URL, a path or a title.
 */
#[Fillable(['time_entry_id', 'minute_at', 'kind', 'host', 'seconds'])]
class ActivitySite extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'minute_at' => 'datetime',
            'kind' => SiteKind::class,
            'seconds' => 'integer',
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
