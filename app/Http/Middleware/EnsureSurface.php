<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Surface;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Usage: `surface:admin|employee|accountant`, after `auth` and `active`. Each role reaches only
 * its own shell. Deactivated users are `EnsureActiveUser`'s business, not this middleware's.
 */
class EnsureSurface
{
    public function handle(Request $request, Closure $next, string $surface): Response
    {
        $expected = Surface::from($surface);
        $user = $request->user();

        // `auth` runs first; a guest here means it was left out, so hand over to the auth handler.
        if (! $user instanceof User) {
            throw new AuthenticationException('Unauthenticated.');
        }

        if ($user->surface() !== $expected) {
            abort(403);
        }

        return $next($request);
    }
}
