<?php

namespace App\Http\Controllers\Shared;

use App\Http\Controllers\Controller;
use App\Models\PushSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Push notifications on this device (Profile): the browser's subscription, and the person's
 * two switches (Messages, Alerts).
 */
class PushSubscriptionController extends Controller
{
    private const MAX_DEVICES = 10;

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            // The server POSTs to this URL later, so only the browsers' own push services are
            // accepted — never an address of the poster's choosing (no request forgery).
            'endpoint' => ['required', 'string', 'url', 'starts_with:https://', 'max:2048', function (string $attribute, mixed $value, \Closure $fail): void {
                $host = strtolower((string) parse_url((string) $value, PHP_URL_HOST));

                if (! self::isPushService($host)) {
                    $fail('This is not a browser push service address.');
                }
            }],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
            'content_encoding' => ['nullable', 'string', 'in:aesgcm,aes128gcm'],
        ]);

        $existing = PushSubscription::query()->where('endpoint', $data['endpoint'])->first();

        // A browser that re-subscribes sends the same keys, so a device moves to whoever is now
        // signed in on it only when the caller proves it holds that device's auth secret.
        if ($existing !== null && (int) $existing->user_id !== (int) $request->user()->getKey()
            && ! hash_equals((string) $existing->auth_token, (string) $data['keys']['auth'])) {
            throw ValidationException::withMessages(['endpoint' => 'This device is registered to somebody else.']);
        }

        PushSubscription::query()->updateOrCreate(['endpoint' => $data['endpoint']], [
            'user_id' => $request->user()->getKey(),
            'public_key' => $data['keys']['p256dh'],
            'auth_token' => $data['keys']['auth'],
            'content_encoding' => $data['content_encoding'] ?? 'aes128gcm',
            'user_agent' => Str::limit((string) $request->userAgent(), 250, ''),
        ]);

        // At most MAX_DEVICES per person: the oldest beyond that are forgotten.
        $keep = PushSubscription::query()
            ->where('user_id', $request->user()->getKey())
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit(self::MAX_DEVICES)
            ->pluck('id');

        PushSubscription::query()
            ->where('user_id', $request->user()->getKey())
            ->whereNotIn('id', $keep)
            ->delete();

        return response()->json(['subscribed' => true]);
    }

    /**
     * Chrome / Android / Samsung / Opera (FCM), Firefox, Edge and Safari.
     */
    private static function isPushService(string $host): bool
    {
        // Google: FCM, plus the jmtNN.google.com hosts Chromium hands out — never all of google.com, whose other hosts can serve anybody's content.
        return in_array($host, ['fcm.googleapis.com', 'android.googleapis.com', 'web.push.apple.com'], true)
            || preg_match('/^jmt\d+\.google\.com$/', $host) === 1
            || str_ends_with($host, '.push.services.mozilla.com')
            || str_ends_with($host, '.notify.windows.com')
            || str_ends_with($host, '.push.apple.com');
    }

    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:2048'],
        ]);

        PushSubscription::query()
            ->where('user_id', $request->user()->getKey())
            ->where('endpoint', $data['endpoint'])
            ->delete();

        return response()->json(['subscribed' => false]);
    }

    public function preferences(Request $request): RedirectResponse
    {
        $request->validate([
            'messages' => ['required', 'boolean'],
            'alerts' => ['required', 'boolean'],
        ]);

        $request->user()->forceFill([
            'push_messages' => $request->boolean('messages'),
            'push_alerts' => $request->boolean('alerts'),
        ])->save();

        return back();
    }
}
