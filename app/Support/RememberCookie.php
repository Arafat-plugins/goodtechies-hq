<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Auth\SessionGuard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * "Remember me" lasts 400 days (decision 12-89), and one `remember_token` serves every device a
 * person uses. So when one device must be signed out for good — a session ended from Profile —
 * the token is cycled, which kills EVERY device's remember cookie, and then this device's
 * cookie is written again with the new token so the person keeps their own login.
 *
 * No Login event fires: this is the same person on the same session, not a new sign-in.
 */
final class RememberCookie
{
    /** Cycle the token (all devices) and keep this device remembered. */
    public static function cycleKeepingThisDevice(User $user): void
    {
        $user->forceFill(['remember_token' => Str::random(60)])->save();

        self::rememberThisDevice($user);
    }

    /** Re-issue this device's remember cookie for the user's current token. */
    public static function rememberThisDevice(User $user): void
    {
        $guard = Auth::guard('web');

        if (! $guard instanceof SessionGuard || $user->getRememberToken() === null) {
            return;
        }

        $jar = $guard->getCookieJar();

        $jar->queue($jar->make(
            $guard->getRecallerName(),
            $user->getAuthIdentifier().'|'.$user->getRememberToken().'|'.$user->getAuthPassword(),
            (int) config('auth.guards.web.remember', 576000),
        ));
    }
}
