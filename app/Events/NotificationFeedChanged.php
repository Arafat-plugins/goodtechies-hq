<?php

namespace App\Events;

use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * One person's bell has something new to say (master prompt Part D §10, §11's last arrow).
 *
 * ## It carries the feed, not the event
 *
 * The payload is **exactly** what `GET /notifications/recent` answers — `unread_count` and the
 * newest ten — because the bell has two supported transports and they must not be two
 * contracts. `NotificationService::feed()` builds it, the controller returns it and this
 * broadcasts it, so there is one shape and one query behind both.
 * `tests/Feature/Realtime/PollingFallbackTest.php` asserts the two are identical rather than
 * trusting this paragraph.
 *
 * A "a notification arrived, here it is" event would have been smaller and would have made the
 * client keep its own count — and a client-side count is wrong the moment the same person has
 * the app open on their phone, which is the reason `notifications.ts` already refuses to
 * decrement one. Sending the whole feed means the socket and the poll deliver the same truth
 * and the reader never has to add up.
 *
 * ## Why it is queued
 *
 * `ShouldBroadcast`, not `ShouldBroadcastNow`. Broadcasting is an HTTP call to Reverb, and on
 * a box where Reverb is stopped that call is refused — inline, that turns "approve this leave
 * request" into a 500 on an action that already succeeded. Queued, a dead socket costs a
 * retried job and nothing the person can see. The price is that **realtime needs the queue
 * worker**, which `deploy/supervisor/hq-queue.conf` starts and `docs/runbooks/realtime.md`
 * says out loud.
 *
 * ## Nothing new becomes visible because it arrived live
 *
 * The channel is `private-notifications.{user}` and its callback asks the same
 * `NotificationPolicy::viewAny` the HTTP route does (App\Broadcasting\NotificationChannel).
 * `feed()` is scoped to the one user this event names. There is no path here to somebody
 * else's mail.
 */
class NotificationFeedChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly User $user) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('notifications.'.$this->user->getKey())];
    }

    /**
     * The name the client listens for. Spelled out rather than left as the class name, because
     * `resources/js/echo.ts` has to type it and a namespaced PHP class is not a name a front
     * end should have to know.
     */
    public function broadcastAs(): string
    {
        return 'feed.changed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return app(NotificationService::class)->feed($this->user);
    }
}
