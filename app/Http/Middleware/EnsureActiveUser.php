<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * Usage: `active`, after `auth`. A deactivated user reaches no authenticated route, whether they
 * arrive with a session or with a remember-me cookie. Guests belong to `auth`, so they pass.
 */
class EnsureActiveUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || $user->isActive()) {
            return $next($request);
        }

        // The guard's logout queues a forget for the recaller cookie and cycles the remember
        // token, so a remember-me cookie on this request cannot sign them back in.
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->expectsJson()) {
            abort(403, 'Your account is inactive.');
        }

        return redirect(Route::has('login') ? route('login') : '/login')
            ->with('error', 'Your account is inactive. Contact an administrator.');
    }
}
