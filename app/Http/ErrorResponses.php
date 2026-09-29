<?php

namespace App\Http;

use App\Http\Middleware\EnsureTwoFactorEnrolled;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use App\Support\Surface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * What an Inertia visit (or a fetch) gets back when the request failed — reliability slice 2a.
 *
 * Called from `withExceptions()->respond()` in `bootstrap/app.php`, after the exception has been
 * reported and rendered. It only ever changes the BODY: the status is the one the framework chose,
 * because the permission matrix and the privacy rules are written against statuses (a record you
 * may not see is a 404, a route your role may not use is a 403) and a nicer page must not blur
 * either.
 *
 * - A **user-initiated** Inertia visit (`X-Inertia`, no `X-Inertia-Partial-Component`) gets the
 *   `Shared/Error` page with the same status and `{ status, home }`, instead of Laravel's HTML —
 *   which Inertia would otherwise draw inside its raw error modal.
 * - A **background** Inertia visit (a poll's partial reload) gets `{reason: "error", status}`. It
 *   must never swap the page the person is on; the client counts it and backs off.
 * - A request body over the server's limit, on an Inertia or JSON request, gets
 *   `{reason: "too_large"}` with its 413.
 *
 * Everything else — full-page requests (they get `resources/views/errors/*`), and the 401 / 419 /
 * surface-403 bodies of slice 1 — passes through untouched.
 */
final class ErrorResponses
{
    /** The statuses that get the error page. */
    public const PAGE_STATUSES = [403, 404, 429, 500, 503];

    public static function for(Response $response, Request $request): Response
    {
        $status = $response->getStatusCode();
        $inertia = $request->hasHeader('X-Inertia');

        if ($status === Response::HTTP_REQUEST_ENTITY_TOO_LARGE && ($inertia || $request->expectsJson())) {
            return self::keepHeaders($response, response()->json([
                'message' => 'That upload is too large for the server.',
                'reason' => 'too_large',
            ], $status));
        }

        if (! $inertia || ! in_array($status, self::PAGE_STATUSES, true)) {
            return $response;
        }

        // Somebody already answered in JSON on purpose (slice 1's bodies are the only ones).
        if ($response instanceof JsonResponse) {
            return $response;
        }

        if ($request->hasHeader('X-Inertia-Partial-Component')) {
            return self::keepHeaders($response, response()->json([
                'reason' => 'error',
                'status' => $status,
            ], $status));
        }

        // The render must never be what fails: a 500 caused by a database outage reaches here
        // too, and the shared props (the person, the shell) read the database. First the page
        // as the person, then the page as a guest with nothing shared, then — if even that
        // throws — the framework's own response, untouched.
        try {
            $page = self::page($request, $status, self::shellUser($request));
        } catch (Throwable) {
            try {
                $page = self::page($request, $status, null);
            } catch (Throwable) {
                return $response;
            }
        }

        return self::keepHeaders($response, $page);
    }

    /**
     * The error page. With a `$user`, it is rendered in their shell; without one, as a guest:
     * nothing shared (so `auth.user` is absent and the page picks `AuthLayout`) and home is login.
     */
    private static function page(Request $request, int $status, ?User $user): Response
    {
        if ($user instanceof User) {
            self::shareShellProps($request);
        } else {
            Inertia::flushShared();
        }

        $page = Inertia::render('Shared/Error', [
            'status' => $status,
            'home' => self::homeFor($user),
        ])->toResponse($request);

        $page->setStatusCode($status);

        return $page;
    }

    /**
     * The person the page may be drawn for — only somebody every signed-in route would let
     * through. `HandleInertiaRequests` is the LAST middleware of the `web` group, and a binding's
     * 404 is thrown before `active` / `two-factor` have run, so their own predicates are asked
     * here: a deactivated account, or one held at 2FA enrolment, is drawn as a guest.
     */
    private static function shellUser(Request $request): ?User
    {
        if (! $request->hasSession()) {
            return null;
        }

        $user = $request->user();

        if (! $user instanceof User || ! $user->isActive() || EnsureTwoFactorEnrolled::mustEnrol($user)) {
            return null;
        }

        return $user;
    }

    /**
     * The shared props (`auth`, `app`, `flash`, the optional `shell`) that let the page keep the
     * person's own shell — shared the way `HandleInertiaRequests` shares them, because an error
     * thrown before it (a route-model binding's 404, the commonest error there is) arrives here
     * with nothing shared. Only called for a `shellUser()`, so the session exists.
     */
    private static function shareShellProps(Request $request): void
    {
        $middleware = app(HandleInertiaRequests::class);

        Inertia::version(fn () => $middleware->version($request));
        Inertia::share($middleware->share($request));
    }

    /** The person's own dashboard, or the login page for a guest. */
    private static function homeFor(?User $user): string
    {
        if (! $user instanceof User) {
            return route('login');
        }

        $surface = $user->surface();

        return $surface instanceof Surface ? route($surface->homeRoute()) : route('home');
    }

    /**
     * `Retry-After` on a 429 / 503 and the rate-limit headers stay with the new body, and every
     * body built here carries `Vary: X-Inertia` (merged with whatever Vary is already there). An
     * error thrown before `HandleInertiaRequests` never got Inertia's own Vary, so the browser
     * could replay this JSON on back/forward in place of the HTML page at the same URL — and a
     * missing header would be one more difference between a 404 that does not exist and one the
     * person may not see.
     */
    private static function keepHeaders(Response $from, Response $to): Response
    {
        foreach (['Retry-After', 'X-RateLimit-Limit', 'X-RateLimit-Remaining', 'X-RateLimit-Reset'] as $name) {
            if ($from->headers->has($name)) {
                $to->headers->set($name, $from->headers->get($name));
            }
        }

        $vary = [];

        foreach ([...$from->getVary(), ...$to->getVary(), 'X-Inertia'] as $header) {
            $vary[strtolower($header)] ??= $header;
        }

        $to->setVary(array_values($vary));

        return $to;
    }
}
