<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One tickable line on a task's checklist.
 *
 * Not a task: no assignee, no status machine, no dates, no timer. See the table's migration
 * for why that is a decision rather than an omission.
 */
#[Fillable(['task_id', 'title', 'is_done', 'position'])]
class TaskChecklistItem extends Model
{
    protected $table = 'task_checklists';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_done' => 'boolean',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }
}
