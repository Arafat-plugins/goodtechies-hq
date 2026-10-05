<?php

namespace App\Http\Middleware;

use App\Models\AppPushToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ties the goodERP Android app on this phone to whoever is signed in on it (2026-10-05).
 *
 * The app gets a Firebase token and stores it as the `__Host-gerp_fcm` cookie in its own WebView
 * — the only place that cookie can come from: a link cannot carry a cookie, another site cannot
 * set one for this host, and the `__Host-` prefix means not even a sibling `*.goodtechies.com`
 * site can (browsers refuse a `__Host-` cookie that names a Domain). So "this request carries `__Host-gerp_fcm` and
 * a signed-in user" means exactly "this person is signed in inside the app on this phone", and
 * the token is filed under them — moving it if somebody else signed in on the phone before.
 *
 * Written once per session (the session remembers the token it filed), not on every request.
 * The cookie is not encrypted (`bootstrap/app.php`): the app writes it, not Laravel.
 */
class RememberAppPushToken
{
    public const COOKIE = '__Host-gerp_fcm';

    public const SESSION_KEY = 'app_push_token';

    /** At most this many phones per person; the longest unused are forgotten. */
    private const MAX_DEVICES = 10;

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->cookies->get(self::COOKIE);

        if (is_string($token) && self::looksLikeAToken($token) && $request->hasSession()) {
            $user = $request->user();

            if ($user !== null && $request->session()->get(self::SESSION_KEY) !== $user->getKey().'|'.md5($token)) {
                $row = AppPushToken::query()->where('token', $token)->first() ?? new AppPushToken(['token' => $token]);
                $row->user_id = $user->getKey();
                $row->touch();

                $keep = AppPushToken::query()
                    ->where('user_id', $user->getKey())
                    ->orderByDesc('updated_at')
                    ->orderByDesc('id')
                    ->limit(self::MAX_DEVICES)
                    ->pluck('id');

                AppPushToken::query()->where('user_id', $user->getKey())->whereNotIn('id', $keep)->delete();

                $request->session()->put(self::SESSION_KEY, $user->getKey().'|'.md5($token));
            }
        }

        return $next($request);
    }

    /** Firebase registration tokens: letters, digits, `-`, `_` and `:`; ~160 characters. */
    public static function looksLikeAToken(string $token): bool
    {
        return preg_match('/\A[A-Za-z0-9_\-:]{32,4096}\z/', $token) === 1;
    }
}
