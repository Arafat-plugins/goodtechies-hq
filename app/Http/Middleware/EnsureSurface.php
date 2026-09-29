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
            // Still a 403 — the permission matrix and the privacy rules depend on the status.
            // A background Inertia visit or a fetch() gets a body the client can act on: the
            // person's role changed while the page was open, and `home` is where they now live.
            if ($request->hasHeader('X-Inertia') || $request->expectsJson()) {
                return response()->json([
                    'message' => 'Your access has changed.',
                    'reason' => 'surface',
                    'home' => $this->homeFor($user),
                ], 403);
            }

            abort(403);
        }

        return $next($request);
    }

    private function homeFor(User $user): string
    {
        $surface = $user->surface();

        return $surface instanceof Surface ? route($surface->homeRoute()) : route('home');
    }
}
