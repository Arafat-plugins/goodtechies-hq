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
 * A conversation: a task discussion, a project channel, the team channel, a DM or the
 * announcements channel (master prompt Part D §10).
 *
 * ## Who may see one is never stored on the membership table
 *
 * For every one of the five types the audience is COMPUTED — see ConversationType, which holds
 * the table of what each type computes from. `members()` below is read state, how far somebody
 * has read, and grants nothing to anybody in any type. A DM's two people are `dmOne` and
 * `dmTwo`, columns on this row, precisely so that stays true of a DM as well.
 *
 * The one-store decision still holds: the spec's `task_comments` table does not exist and must
 * not be added.
 */
#[Fillable(['type', 'linked_project_id', 'linked_task_id', 'dm_one_id', 'dm_two_id', 'title'])]
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
     * The lower of a DM's two user ids. "One" and "two" are an ORDER, not roles: the CHECK on
     * the table insists `dm_one_id < dm_two_id`, so neither column means "the person who
     * started it" and (A,B) and (B,A) are the same row by construction.
     *
     * @return BelongsTo<User, $this>
     */
    public function dmOne(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dm_one_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function dmTwo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dm_two_id');
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
     * **Not an access list, for any type.** Nothing in ConversationPolicy reads this relation,
     * and a row that outlives somebody's access carries a timestamp and no content. A DM's two
     * people are `dmOne` and `dmTwo` above, on the row itself, so there is no type for which
     * this table means something different.
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
     * The record this conversation is about, whichever kind it is. Null for the three types
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
     * True for every type; see ConversationType.
     */
    public function membershipIsComputed(): bool
    {
        return $this->type?->membershipIsComputed() ?? false;
    }

    /**
     * The two people in a DM, as ids, in the order the row stores them.
     *
     * @return list<int>
     */
    public function dmParticipantIds(): array
    {
        if ($this->type !== ConversationType::Dm) {
            return [];
        }

        return array_values(array_filter([
            $this->dm_one_id === null ? null : (int) $this->dm_one_id,
            $this->dm_two_id === null ? null : (int) $this->dm_two_id,
        ]));
    }

    /**
     * Is this person one of a DM's two? The whole of a DM's access rule, asked once here so
     * ConversationPolicy and the DM label read the same comparison.
     */
    public function isDmParticipant(User $user): bool
    {
        return in_array((int) $user->getKey(), $this->dmParticipantIds(), true);
    }

    /**
     * The OTHER person in a DM, from this reader's side — what a DM is called.
     */
    public function dmCounterpartFor(User $user): ?User
    {
        if ($this->type !== ConversationType::Dm) {
            return null;
        }

        return (int) $this->dm_one_id === (int) $user->getKey() ? $this->dmTwo : $this->dmOne;
    }

    /**
     * What this conversation is CALLED to this reader.
     *
     * Resolved per reader rather than stored, for the reason a task conversation has no title:
     * a stored copy is a second thing to keep in step with every rename — and a DM has no one
     * name at all, being "Tapu" to Shahadat and "Shahadat" to Tapu.
     */
    public function labelFor(User $user): string
    {
        return match ($this->type) {
            ConversationType::Dm => $this->dmCounterpartFor($user)?->name ?? 'Somebody who has since left',
            ConversationType::Project => (string) ($this->project?->name ?? 'A project'),
            ConversationType::Task => (string) ($this->task?->title ?? 'A task'),
            default => (string) ($this->title ?? $this->type?->group() ?? 'Conversation'),
        };
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

    /**
     * @param  Builder<Conversation>  $query
     * @return Builder<Conversation>
     */
    public function scopeForProject(Builder $query, Project $project): Builder
    {
        return $query->ofType(ConversationType::Project)->where('linked_project_id', $project->getKey());
    }

    /**
     * Every DM this person is in.
     *
     * Both columns, because the pair is ordered by id and not by who started it — which is also
     * why there are two indexes over them.
     *
     * @param  Builder<Conversation>  $query
     * @return Builder<Conversation>
     */
    public function scopeDmsFor(Builder $query, User $user): Builder
    {
        return $query->ofType(ConversationType::Dm)->where(
            fn (Builder $inner) => $inner
                ->where('dm_one_id', $user->getKey())
                ->orWhere('dm_two_id', $user->getKey()),
        );
    }
}
