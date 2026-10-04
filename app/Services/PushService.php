<?php

namespace App\Services;

use App\Models\PushSubscription;
use App\Models\User;
use App\Support\PushCategory;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * Sends a push notification to every device a person turned notifications on for.
 *
 * Nothing here decides WHO is told — MessagePusher (chat) and NotificationService (the bell)
 * do. This only honours the person's own switch for the category, sends, and forgets devices
 * the push service reports as gone (uninstalled app, revoked permission).
 */
class PushService
{
    public function isConfigured(): bool
    {
        return filled(config('webpush.vapid.public_key')) && filled(config('webpush.vapid.private_key'));
    }

    public function wants(User $user, PushCategory $category): bool
    {
        return match ($category) {
            PushCategory::Messages => (bool) $user->push_messages,
            PushCategory::Alerts => (bool) $user->push_alerts,
        };
    }

    /**
     * Forget every device this person turned push on for — after a password change, a password
     * reset or ending a session, when a lost or shared device must stop showing their
     * notifications. The device in hand re-registers itself on its next full page load
     * (resources/js/lib/push.ts → refreshSubscription()).
     */
    public function forgetAllDevices(User $user): int
    {
        return PushSubscription::query()->where('user_id', $user->getKey())->delete();
    }

    /**
     * @param  array{title: string, body: string, url: string, tag: string, sender?: string}  $message
     * @return int How many devices the push service accepted it for.
     */
    public function send(User $user, PushCategory $category, array $message): int
    {
        if (! $this->isConfigured() || ! $this->wants($user, $category)) {
            return 0;
        }

        $subscriptions = PushSubscription::query()->where('user_id', $user->getKey())->get();

        if ($subscriptions->isEmpty()) {
            return 0;
        }

        $client = $this->client();
        $payload = json_encode($message + ['category' => $category->value], JSON_THROW_ON_ERROR);

        foreach ($subscriptions as $subscription) {
            $client->queueNotification(Subscription::create([
                'endpoint' => $subscription->endpoint,
                'publicKey' => $subscription->public_key,
                'authToken' => $subscription->auth_token,
                'contentEncoding' => $subscription->content_encoding,
            ]), $payload, ['TTL' => 86400, 'urgency' => 'high']);
        }

        $sent = 0;

        foreach ($client->flush() as $report) {
            if ($report->isSuccess()) {
                $sent++;

                continue;
            }

            if ($report->isSubscriptionExpired()) {
                PushSubscription::query()->where('endpoint', $report->getEndpoint())->delete();

                continue;
            }

            Log::warning('Web push was not delivered', [
                'user_id' => $user->getKey(),
                'push_host' => parse_url($report->getEndpoint(), PHP_URL_HOST),
                'reason' => $report->getReason(),
            ]);
        }

        return $sent;
    }

    protected function client(): WebPush
    {
        return new WebPush([
            'VAPID' => [
                'subject' => (string) config('webpush.vapid.subject'),
                'publicKey' => (string) config('webpush.vapid.public_key'),
                'privateKey' => (string) config('webpush.vapid.private_key'),
            ],
        ], [], 20, ['allow_redirects' => false]);
    }
}
