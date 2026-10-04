<?php

namespace App\Listeners;

use App\Events\MessagePosted;
use App\Jobs\SendWebPush;
use App\Models\Message;
use App\Models\User;
use App\Support\AttachmentKind;
use App\Support\ConversationType;
use App\Support\Permission;
use App\Support\PushCategory;
use App\Support\UserStatus;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * The phone half of a post: one `MessagePosted` in, one queued SendWebPush per reader out.
 *
 * Every chat type except task discussions. The bell's own DM / announcement rows carry no
 * second push (NotificationType::channels()), so a DM reaches the phone exactly once.
 *
 * A subscriber (registered in AppServiceProvider) for the same reason as
 * ConversationBroadcaster: a discovered `handle()` plus an explicit registration would push twice.
 */
class MessagePusher
{
    /**
     * @return array<class-string, string>
     */
    public function subscribe(): array
    {
        return [
            MessagePosted::class => 'onMessagePosted',
        ];
    }

    public function onMessagePosted(MessagePosted $event): void
    {
        // Task discussions reach the people working on the task as bell alerts (TaskCommented).
        if ($event->conversation->type === ConversationType::Task) {
            return;
        }

        $preview = $this->preview($event->message);
        $url = route('messages.index', ['conversation' => $event->conversation->getKey()], false);
        $conversation = $event->conversation;

        $readers = User::query()
            ->with('employee')
            ->where('status', UserStatus::Active)
            ->whereKeyNot($event->actor->getKey())
            ->orderBy('id')
            ->get()
            ->filter(fn (User $user): bool => $user->hasPermission(Permission::MessagesUse))
            ->filter(fn (User $user): bool => Gate::forUser($user)->allows('view', $conversation));

        $sender = (string) $event->actor->name;
        $isDm = $conversation->type === ConversationType::Dm;

        foreach ($readers as $reader) {
            // Telegram's notification (client reference, 2026-10-04): a DM is titled with the
            // sender and shows the text; any other chat is titled with the chat and shows
            // "Sender: text". `sender` lets the service worker draw the sender's avatar.
            SendWebPush::dispatch((int) $reader->getKey(), PushCategory::Messages, [
                'title' => $isDm ? $sender : $conversation->labelFor($reader),
                'body' => $isDm ? $preview : $sender.': '.$preview,
                'url' => $url,
                'tag' => 'conversation-'.$conversation->getKey(),
                'sender' => $sender,
            ], (int) $event->message->getKey());
        }
    }

    private function preview(Message $message): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', (string) $message->body));

        if ($text !== '') {
            return Str::limit($text, 120);
        }

        $attachments = $message->attachments()->get();

        $voice = $attachments->contains(function ($file): bool {
            $kind = $file->pivot->kind;

            return ($kind instanceof \BackedEnum ? $kind->value : $kind) === AttachmentKind::Voice->value;
        });

        if ($voice) {
            return 'Voice message';
        }

        if ($attachments->isNotEmpty()) {
            return 'Sent a file';
        }

        return 'New message';
    }
}
