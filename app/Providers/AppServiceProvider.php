<?php

namespace App\Providers;

use App\Listeners\NotificationDispatcher;
use App\Models\User;
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

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Settings are cached for the lifetime of one request or job.
        $this->app->scoped(SettingsService::class);
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

        RateLimiter::for('two-factor', fn (Request $request): Limit => Limit::perMinute(5)->by(
            ($request->hasSession() ? (string) $request->session()->get(TwoFactorService::PENDING_LOGIN_SESSION_KEY) : '').'|'.$request->ip(),
        ));
    }
}
