<?php

namespace App\Http\Middleware;

use App\Models\TimeEntry;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Usage: `remote-timer`, after `auth:sanctum` and `active`. The timer extension's API is for
 * remote timer users only (docs/extension-api.md §1): anyone else — an office employee, an Admin,
 * an Accountant — is refused with the contract's envelope, whatever abilities their token holds.
 */
class EnsureRemoteTimerUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // The API carries no session, so the web `active` middleware is not on it: refused here.
        if ($user instanceof User && ! $user->isActive()) {
            return response()->json([
                'error' => 'account_inactive',
                'message' => 'Your account is inactive.',
            ], 403);
        }

        if (! $user || Gate::forUser($user)->denies('track', TimeEntry::class)) {
            return response()->json([
                'error' => 'role_not_allowed',
                'message' => 'Only remote timer users can use the timer extension.',
            ], 403);
        }

        return $next($request);
    }
}
