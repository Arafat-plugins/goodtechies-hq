<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person's emoji on one message (12-79). Toggled by MessageService::toggleReaction; at most
 * one row per (message, user, emoji).
 */
#[Fillable(['message_id', 'user_id', 'emoji'])]
class MessageReaction extends Model
{
    const UPDATED_AT = null;

    /**
     * @return BelongsTo<Message, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
