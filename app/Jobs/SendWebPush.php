<?php

namespace App\Jobs;

use App\Models\Message;
use App\Models\User;
use App\Services\PushService;
use App\Support\PushCategory;
use App\Support\UserStatus;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One push to one person's devices, off the request (the push services can take seconds).
 * Dispatched after the commit, so a rolled-back message or notification never reaches a phone.
 */
class SendWebPush implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /**
     * @param  array{title: string, body: string, url: string, tag: string}  $message
     * @param  int|null  $messageId  The chat message this push announces — skipped if it was deleted before the worker ran.
     */
    public function __construct(
        public readonly int $userId,
        public readonly PushCategory $category,
        public readonly array $message,
        public readonly ?int $messageId = null,
    ) {
        $this->afterCommit();
    }

    public function handle(PushService $push): void
    {
        $user = User::query()->find($this->userId);

        if ($user === null || $user->status !== UserStatus::Active) {
            return;
        }

        if ($this->messageId !== null && ! Message::query()->whereKey($this->messageId)->exists()) {
            return;
        }

        $push->send($user, $this->category, $this->message);
    }
}
