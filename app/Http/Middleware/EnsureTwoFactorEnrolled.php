<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends users whose role requires 2FA to enrolment until they have confirmed it.
 */
class EnsureTwoFactorEnrolled
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (
            $user instanceof User
            && $user->requiresTwoFactor()
            && ! $user->hasConfirmedTwoFactor()
            && ! $request->is('two-factor/*', 'logout')
        ) {
            if ($request->expectsJson()) {
                abort(403, 'Two-factor enrolment required.');
            }

            return redirect('/two-factor/enrol');
        }

        return $next($request);
    }
}
