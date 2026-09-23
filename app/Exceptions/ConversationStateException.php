<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a conversation, or the message being offered, is not in a state the write
 * accepts.
 *
 * The sibling of FileStateException and TaskStateException, and distinct from
 * AuthorizationException for the same reason they are: the user may well be allowed, the thing
 * they are offering is simply not something this application will store. Over HTTP the Form
 * Request says so first, against the field; this is the same rule at the only place that writes
 * a message, so a job or a console command is refused identically.
 */
class ConversationStateException extends RuntimeException
{
    /**
     * A message with no text and no attachment. The rule cannot be a CHECK constraint, because
     * half of its answer lives in `message_attachments`.
     */
    public static function emptyMessage(): self
    {
        return new self('A message needs something in it — text, a file, or both.');
    }

    /**
     * Asked for the discussion of something that is not a task.
     *
     * Phase 2 builds the `task` type and nothing else. This is what a caller reaching for one
     * of the four Phase 6 types gets, rather than a half-built conversation.
     */
    public static function notATaskConversation(): self
    {
        return new self('That conversation is not a task discussion.');
    }
}
