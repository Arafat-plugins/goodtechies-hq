<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\TwoFactorService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends users whose role requires 2FA to enrolment until they have confirmed it.
 *
 * Enforcement is skipped only when TwoFactorService::isEnforced() says so — the same
 * single predicate the login controller consults, true in production whatever
 * AUTH_TWO_FACTOR_ENFORCED is set to. The condition is not restated here.
 */
class EnsureTwoFactorEnrolled
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (self::mustEnrol($user) && ! $request->is('two-factor/*', 'logout')) {
            if ($request->expectsJson()) {
                abort(403, 'Two-factor enrolment required.');
            }

            return redirect('/two-factor/enrol');
        }

        return $next($request);
    }

    /**
     * Is this person held at the enrolment page? Also asked by `App\Http\ErrorResponses`, which
     * must not hand the shell to somebody this middleware would have stopped.
     */
    public static function mustEnrol(mixed $user): bool
    {
        return TwoFactorService::isEnforced()
            && $user instanceof User
            && $user->requiresTwoFactor()
            && ! $user->hasConfirmedTwoFactor();
    }
}
