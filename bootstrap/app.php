<?php

use App\Http\ErrorResponses;
use App\Http\Middleware\ContentSecurityPolicy;
use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\EnsureSurface;
use App\Http\Middleware\EnsureTwoFactorEnrolled;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SweepAbandonedTimers;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

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
            // Brief 028: the timer watchdog's rules, at most once a minute, with or without a
            // scheduler. After the session (it needs the user), before Inertia's shared props
            // and the controller, so the page that triggers it already reads the result.
            SweepAbandonedTimers::class,
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

        // Session expiry (reliability slice 1). A background Inertia visit or a fetch() that
        // finds the session gone must NOT be redirected to /login: Inertia would follow the
        // redirect and swap the page the person is typing into for the login screen. They get
        // a 401 the client turns into a "your session has ended" dialog over the page instead.
        // A full-page GET keeps the redirect (with the intended URL) it always had.
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if (! $request->hasHeader('X-Inertia') && ! $request->expectsJson()) {
                return null;
            }

            return response()->json([
                'message' => 'Your session has ended.',
                'reason' => 'session',
            ], 401);
        });

        // The framework has already mapped a TokenMismatchException to an HttpException(419)
        // by the time render callbacks run, so it is recognised by what it wraps.
        $exceptions->render(function (HttpException $e, Request $request) {
            if (! $e->getPrevious() instanceof TokenMismatchException) {
                return null;
            }

            if (! $request->hasHeader('X-Inertia') && ! $request->expectsJson()) {
                return null;
            }

            return response()->json([
                'message' => 'Your session has ended.',
                'reason' => 'csrf',
            ], 419);
        });

        // Reliability slice 2a. An Inertia visit that meets an error used to get Laravel's HTML
        // page, which Inertia draws in its raw modal. The STATUS never changes here (a 404
        // stays a 404, a 403 a 403 — the permission matrix and the privacy rules depend on
        // it); only the body does. Rendering and logging already happened by the time this
        // runs, so a 500 is reported exactly as before.
        $exceptions->respond(function (SymfonyResponse $response, Throwable $e, Request $request) {
            return ErrorResponses::for($response, $request);
        });
    })->create();
