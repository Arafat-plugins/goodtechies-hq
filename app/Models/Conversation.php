<?php

namespace App\Models;

use App\Support\ConversationType;
use Database\Factories\ConversationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A conversation. In Phase 2 there is exactly one kind: a task's discussion.
 *
 * The recorded decision is that the spec's `task_comments` table is not created — every task
 * gets a `conversations` row of type `task` when it is created, and its "comments" are that
 * conversation's `messages`. One store, no later migration.
 *
 * ## Who may see one is not stored on it
 *
 * For a `task` conversation the audience is computed from the linked task, every time, by
 * TaskPolicy. `members()` below is read state — how far somebody has read — and grants
 * nothing. See the `conversation_members` migration for the full reasoning, and
 * ConversationType::membershipIsComputed() for where the two meanings of that table are named.
 */
#[Fillable(['type', 'linked_project_id', 'linked_task_id', 'title'])]
class Conversation extends Model
{
    /** @use HasFactory<ConversationFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ConversationType::class,
        ];
    }

    /**
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'linked_task_id');
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'linked_project_id');
    }

    /**
     * The messages, oldest first — a discussion is read downwards.
     *
     * Ordered by `id` rather than `created_at` so two messages posted in the same second have
     * a stable order, which is the same reason the index is on `(conversation_id, id)`.
     *
     * @return HasMany<Message, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class)->orderBy('id');
    }

    /**
     * The people who have been in this conversation, with their unread line on the pivot.
     *
     * NOT an access list for a `task` conversation. Nothing in ConversationPolicy reads this
     * relation, and a row that outlives somebody's access to the task carries a timestamp and
     * no content. Phase 6's other types will use it as the grant as well; for now it is read
     * state, and that is stated on the table itself.
     *
     * @return BelongsToMany<User, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'conversation_members')
            ->withPivot('last_read_at')
            ->withTimestamps();
    }

    /**
     * The record this conversation is about, whichever kind it is. Null for the Phase 6 types
     * that are about nothing but themselves.
     */
    public function subject(): ?Model
    {
        return match ($this->type) {
            ConversationType::Task => $this->task,
            ConversationType::Project => $this->project,
            default => null,
        };
    }

    /**
     * Is this conversation's audience worked out from something else rather than stored?
     * True for a task discussion; see ConversationType.
     */
    public function membershipIsComputed(): bool
    {
        return $this->type?->membershipIsComputed() ?? false;
    }

    /**
     * @param  Builder<Conversation>  $query
     * @return Builder<Conversation>
     */
    public function scopeOfType(Builder $query, ConversationType $type): Builder
    {
        return $query->where('type', $type->value);
    }

    /**
     * The one conversation belonging to this task. `conversations_one_per_task` makes "the"
     * literal.
     *
     * @param  Builder<Conversation>  $query
     * @return Builder<Conversation>
     */
    public function scopeForTask(Builder $query, Task $task): Builder
    {
        return $query->ofType(ConversationType::Task)->where('linked_task_id', $task->getKey());
    }
}
