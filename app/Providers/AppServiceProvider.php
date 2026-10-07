<?php

namespace App\Providers;

use App\Listeners\ConversationBroadcaster;
use App\Listeners\MessagePusher;
use App\Listeners\NotificationDispatcher;
use App\Models\User;
use App\Services\Calendar\CalendarApiOneWay;
use App\Services\Calendar\CalendarLink;
use App\Services\Calendar\ManualLink;
use App\Services\SettingsService;
use App\Services\TwoFactorService;
use App\Support\Permission;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // The extension API is the only `/api/*`; `sanctum/csrf-cookie` is for SPAs and is not registered — Sanctum 4.3 reads this flag in its own boot.
        config(['sanctum.routes' => false]);

        // Settings are cached for the lifetime of one request or job.
        $this->app->scoped(SettingsService::class);

        $this->bindCalendarDriver();
    }

    /**
     * Which Google Calendar driver this deployment runs (Phase 7, master prompt Part D §12).
     *
     * `.env`'s `GOOGLE_CALENDAR_DRIVER`, read through `config/services.php`, defaulting to the
     * manual one — which Part D says is *"always available"* and which needs no credentials.
     *
     * **An unknown value throws rather than falling back.** `api` is the second driver Part D
     * describes and it is not built: it is blocked on a Google Workspace decision the client
     * has not made (PROGRESS.md GATE A question 3). A silent fall back to `manual` would mean a
     * VPS configured for the API running for a week with no Meet links, no error and nobody
     * noticing — the failure mode a seam exists to prevent. Adding that driver is a class and
     * this `match` gaining one arm.
     *
     * Bound as a **singleton**: the driver is stateless, and one instance per request is what
     * lets a test swap it with `$this->app->instance(CalendarLink::class, $fake)` and have
     * `MeetingService` receive the fake.
     */
    private function bindCalendarDriver(): void
    {
        $this->app->singleton(CalendarLink::class, function (): CalendarLink {
            $driver = (string) config('services.google_calendar.driver', 'manual');

            return match ($driver) {
                'manual' => new ManualLink,
                // Polish 030: goodERP creates the event and its Meet link through the agency's
                // connected Google account (Admin → Settings → Connect Google).
                'api' => $this->app->make(CalendarApiOneWay::class),
                default => throw new InvalidArgumentException(sprintf(
                    'Unknown GOOGLE_CALENDAR_DRIVER [%s]. Use [manual] or [api].',
                    $driver,
                )),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->defineGates();

        // Length plus the k-anonymity breach check; no composition rules.
        Password::defaults(fn () => Password::min(12)->uncompromised());

        $this->defineRateLimiters();

        // The login listeners in app/Listeners are registered by event auto-discovery.
        //
        // NotificationDispatcher is not, and cannot be: auto-discovery finds a listener by the
        // event type-hinted on its handle() method, and this one answers nine events. It is a
        // SUBSCRIBER, so the event => method map lives in the class itself (see its subscribe())
        // and this line is the whole registration. That map is deliberately the only list of
        // "which events produce notifications" in the application.
        Event::subscribe(NotificationDispatcher::class);

        // The live half, registered the same way and for the same reason. ConversationBroadcaster
        // turns one MessagePosted into one ConversationActivity on `conversation.{id}` — the
        // broadcast the channel in routes/channels.php was built for and never had
        // (POLISH-BACKLOG §A.2). It is a SUBSCRIBER rather than a discovered handle() listener so
        // that this line is its only registration: a class with both would be subscribed twice
        // and would broadcast twice. Its subscribe() is the map.
        Event::subscribe(ConversationBroadcaster::class);

        // Push notifications for chat: one MessagePosted → one queued SendWebPush per reader (MessagePusher).
        // A subscriber for the same reason as the two above.
        Event::subscribe(MessagePusher::class);
    }

    /**
     * One gate per permission key, so routes can use `can:settings.manage`.
     */
    private function defineGates(): void
    {
        foreach (Permission::cases() as $permission) {
            Gate::define(
                $permission->value,
                fn (User $user): bool => $user->isActive() && $user->hasPermission($permission),
            );
        }
    }

    private function defineRateLimiters(): void
    {
        // Two limits: the per-minute one stops a burst, the hourly one by address alone stops
        // the same account being ground down from a rotating set of IPs.
        RateLimiter::for('login', function (Request $request): array {
            $email = Str::lower((string) $request->input('email'));

            return [
                Limit::perMinute(5)->by($email.'|'.$request->ip()),
                Limit::perHour(20)->by($email),
            ];
        });

        // Forgot-password and reset: a burst limit per IP, and an hourly one per address so one
        // inbox cannot be flooded with reset emails from a rotating set of IPs.
        RateLimiter::for('password-reset', fn (Request $request): array => [
            Limit::perMinute(5)->by('pr-ip|'.$request->ip()),
            Limit::perHour(5)->by('pr-email|'.Str::lower((string) $request->input('email'))),
        ]);

        RateLimiter::for('two-factor', fn (Request $request): Limit => Limit::perMinute(5)->by(
            ($request->hasSession() ? (string) $request->session()->get(TwoFactorService::PENDING_LOGIN_SESSION_KEY) : '').'|'.$request->ip(),
        ));

        $this->defineAuthenticatedRateLimiters();
    }

    /**
     * The limits an AUTHENTICATED account is held to (Phase 12, the security pass).
     *
     * Part C §3 asks for *"rate limiting on auth and API routes"*, and until this slice the only
     * two limiters in the application were the two on the sign-in path. Which leaves the case
     * those two cannot see: a **signed-in but hostile account** — a departing employee, a
     * borrowed laptop, a session lifted from an unlocked machine. Authorization decides *what*
     * they may read; nothing decided *how fast*, and the expensive surfaces are expensive by
     * measurement, not by guess:
     *
     *   - `/search` runs one tsvector query per searchable type — eight, measured — per keystroke
     *     burst from the command palette.
     *   - a report builder scans a whole table per section: `/admin/reports/completion` answers
     *     in **37** queries on a seeded database (tests/Feature/Performance/QueryCountTest.php).
     *   - `GET /files/{file}` re-runs `FilePolicy` and then streams up to 25 MB off the disk.
     *   - posting a message writes a row, resolves mentions, dispatches notifications to every
     *     member and broadcasts on a channel.
     *
     * **Every limit below is keyed on the user id, not the IP.** These routes are all behind
     * `auth`, the office shares one address, and a limit an office shares is a limit one person
     * spends for everybody. The `?: ip()` fallback exists only so the closure cannot divide by an
     * absent user if one of these names is ever put on a guest route.
     *
     * **Each number is a ceiling a human cannot reach, not a budget.** A limiter tight enough to
     * shape normal use is a limiter that fires on the client's busiest afternoon and gets turned
     * off, which is worse than none. The measurements each one is set against are written beside
     * it.
     */
    private function defineAuthenticatedRateLimiters(): void
    {
        // The backstop on every signed-in surface route. 600 a minute is ten a second sustained,
        // which no human and no legitimate client reaches: an Inertia navigation is one request,
        // the notification bell polls once every 15 seconds (`notifications.ts` POLL_MS), and the
        // heaviest single page load measured here — a conversation whose attachments are inline
        // images, each one a lazy `GET /files/{id}` — is a few dozen. It is deliberately NOT a
        // figure that stops an account reading everything it is allowed to read; only the
        // policies can do that. It stops a script.
        RateLimiter::for('authenticated', fn (Request $request): Limit => Limit::perMinute(600)
            ->by($this->limitKey($request)));

        // Global search and message search. `GlobalSearch.vue` debounces at 180 ms and aborts the
        // request before it, so a fast typist produces FEWER requests than a slow one and a
        // minute of continuous palette use is well under 20. 120 leaves six times that.
        RateLimiter::for('search', fn (Request $request): Limit => Limit::perMinute(120)
            ->by($this->limitKey($request)));

        // Downloads and inline attachment rendering. `Messages/AttachmentCard.vue` puts an
        // image attachment in an `<img src>` and a voice note in an `<audio src>`, both pointing
        // at `GET /files/{file}`, so scrolling a busy channel legitimately fetches this route
        // dozens of times a minute — `loading="lazy"` bounds it to the viewport, not to the
        // thread. 240 is four a second. It is not a bandwidth control (240 × 25 MB is not a
        // number a limiter can make safe); it bounds *enumeration and scripted fetching*, which
        // is the part a limiter can actually do. The link is signed for 15 minutes and the policy
        // re-runs on every fetch, so there is nothing to enumerate towards in the first place.
        RateLimiter::for('downloads', fn (Request $request): Limit => Limit::perMinute(240)
            ->by($this->limitKey($request)));

        // The sixteen report builders. A reader opens one report and re-filters it; 60 a minute
        // is one a second, which is faster than the screen can be read and roughly 2 000 queries
        // a minute at the `completion` builder's measured cost.
        RateLimiter::for('reports', fn (Request $request): Limit => Limit::perMinute(60)
            ->by($this->limitKey($request)));

        // Posting: a chat message, a voice note, a task comment. A person in an argument sends
        // perhaps twenty a minute. 60 is three times that and still bounds the notification and
        // broadcast fan-out this route triggers for every other member of the conversation.
        RateLimiter::for('posting', fn (Request $request): Limit => Limit::perMinute(60)
            ->by($this->limitKey($request)));
    }

    /**
     * Who a limit is spent by: the signed-in account, falling back to the address.
     *
     * The office is one IP. Keying these on the address would let one person's runaway tab lock
     * the agency out of search for a minute, which is a denial of service with extra steps.
     */
    private function limitKey(Request $request): string
    {
        return $request->user()?->getAuthIdentifier()
            ? 'user:'.$request->user()->getAuthIdentifier()
            : 'ip:'.$request->ip();
    }
}
