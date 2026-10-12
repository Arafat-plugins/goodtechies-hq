<?php

namespace App\Support;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Polish 044: the app-wide announcement banner is the announcements channel's PINNED message —
 * one at a time, chosen by an Admin. With nothing pinned (or the pinned message deleted) there
 * is no banner. One builder for both places that send it (the shell's live state and the
 * Messages page), so the two cannot drift.
 */
final class AnnouncementBanner
{
    /**
     * @return array{conversation_id: int, message_id: int, body: string, author: string|null, created_at: string|null, is_unread: bool, can_unpin: bool}|null
     */
    public static function for(Conversation $channel, User $viewer, bool $isUnread): ?array
    {
        if ($channel->pinned_message_id === null) {
            return null;
        }

        /** @var Message|null $pinned */
        $pinned = Message::query()
            ->with('author')
            ->whereKey($channel->pinned_message_id)
            ->where('conversation_id', $channel->getKey())
            ->whereNull('deleted_at')
            ->first();

        if ($pinned === null) {
            return null;
        }

        $pinned->setRelation('conversation', $channel);

        return [
            'conversation_id' => (int) $channel->getKey(),
            'message_id' => (int) $pinned->getKey(),
            'body' => MessageText::plain((string) $pinned->body),
            'author' => $pinned->author?->name,
            'created_at' => $pinned->created_at?->toIso8601String(),
            // Whether this reader has unread announcements — the banner's border.
            'is_unread' => $isUnread,
            'can_unpin' => Gate::forUser($viewer)->allows('pin', $pinned),
        ];
    }
}
