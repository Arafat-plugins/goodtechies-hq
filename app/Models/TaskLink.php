<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A reference link on a task: a URL and an optional label.
 *
 * A link is a string pointing somewhere else; an attachment is a file this application stores
 * and is answerable for. They are different tables, in different phases, on purpose.
 */
#[Fillable(['task_id', 'url', 'label', 'created_by'])]
class TaskLink extends Model
{
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
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * What the UI shows when nobody typed a label: the host, which is at least recognisable,
     * rather than 2 kB of query string.
     */
    public function displayLabel(): string
    {
        $label = trim((string) $this->label);

        if ($label !== '') {
            return $label;
        }

        return parse_url((string) $this->url, PHP_URL_HOST) ?: (string) $this->url;
    }
}
