<?php

use App\Http\Middleware\ContentSecurityPolicy;
use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\EnsureSurface;
use App\Http\Middleware\EnsureTwoFactorEnrolled;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // Realtime (Phase 6). `routes/channels.php` registers the three channels Part D §10 names;
    // this registers the one route that authorises a subscription to them.
    //
    // The middleware stack is the same one every signed-in surface route carries, and it is
    // spelled out rather than left at Laravel's default `['web']`: a socket is a second door
    // into the same rooms, so a guest, a deactivated user and an Admin who has not enrolled
    // 2FA have to be turned away at it exactly as they are at the first. `active` is also
    // asserted inside every policy the channel callbacks ask, which is belt and braces on the
    // one rule that must not have a gap.
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['middleware' => ['web', 'auth', 'active', 'two-factor']],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        // Phase 12's security pass. The Content-Security-Policy is in the application rather
        // than in deploy/nginx.conf because it is coupled to the hash of one inline script
        // this application renders — see the middleware's docblock. The transport headers
        // (X-Frame-Options, nosniff, Referrer-Policy, HSTS) stay in nginx, which also covers
        // the responses PHP never sees at all.
        //
        // GLOBAL rather than appended to `web`, and that is the point of the line: a URL that
        // matches no route throws before the `web` group is entered, so a group-scoped policy
        // left every 404 — the one page an attacker can reach on any path they like — as the
        // only HTML this application serves with no CSP on it. Measured in Chromium against
        // /admin/recurring-tasks, which does not exist.
        $middleware->append(ContentSecurityPolicy::class);

        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('home'));

        $middleware->alias([
            'active' => EnsureActiveUser::class,
            'surface' => EnsureSurface::class,
            'two-factor' => EnsureTwoFactorEnrolled::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
