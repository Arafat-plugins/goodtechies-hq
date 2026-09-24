<?php

namespace App\Models;

use Database\Factories\MessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One message in a conversation — one "comment" on a task.
 *
 * There is no edit and no delete, by design: a message is what somebody said at a time, and the
 * next message is answering it. That is the same rule that stops a message ATTACHMENT being
 * replaced with a new version (FilePolicy::replace).
 *
 * A message owns its attachments twice over, and the two cannot disagree — `files.message_id`
 * is the ownership arc, `message_attachments` is how the file rides on the bubble, and a
 * composite foreign key binds them. See the `message_attachments` migration.
 *
 * "A message says something" — text, an attachment, or both — is enforced in
 * ConversationService, the only writer. It cannot be a CHECK constraint, because half of the
 * answer lives in `message_attachments`.
 */
#[Fillable(['conversation_id', 'author_id', 'body'])]
class Message extends Model
{
    /** @use HasFactory<MessageFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * The files this message owns — the ownership side of the arc, current versions only, in
     * the order they were attached.
     *
     * `attachments()` is what a panel reads; this is here because FileService and FilePolicy
     * work in terms of a file's OWNER, and the owner of a message attachment is the message.
     *
     * @return HasMany<File, $this>
     */
    public function files(): HasMany
    {
        return $this->hasMany(File::class)->whereNull('superseded_at')->orderBy('id');
    }

    /**
     * The people this message named (Phase 6).
     *
     * A mention is a record that somebody was ADDRESSED, not a permission: nothing in
     * ConversationPolicy reads this relation, and MessageService refuses to write a row for
     * anybody who cannot already see the conversation — so a mention can never widen an
     * audience. The same rule `members()` lives under, stated on a second table.
     *
     * @return BelongsToMany<User, $this>
     */
    public function mentions(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'message_mentions', 'message_id', 'user_id')
            ->withTimestamps()
            ->orderBy('users.id');
    }

    /**
     * The attachments as the bubble draws them: the file, plus how it rides on the message.
     *
     * Through `message_attachments`, so `kind` and `duration_seconds` arrive on the pivot. File
     * soft-deletes, so an attachment whose file has been removed drops out of this relation on
     * its own — the row in the join table is not the thing that decides whether there is a file.
     *
     * @return BelongsToMany<File, $this>
     */
    public function attachments(): BelongsToMany
    {
        return $this->belongsToMany(File::class, 'message_attachments', 'message_id', 'file_id')
            ->withPivot(['kind', 'duration_seconds'])
            ->withTimestamps()
            ->orderBy('files.id');
    }
}
