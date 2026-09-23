<?php

namespace App\Http\Middleware;

use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'user' => fn (): ?array => $this->sharedUser($request),
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
            'app' => [
                'name' => config('app.name'),
            ],
        ];
    }

    /**
     * The only user fields every page receives. Secrets and hashes never go here.
     *
     * @return array{id: int, name: string, email: string, role: string|null, surface: string|null, trackingMode: string|null, twoFactorEnabled: bool, canTrackTime: bool}|null
     */
    private function sharedUser(Request $request): ?array
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return null;
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role()?->value,
            'surface' => $user->surface()?->value,
            'trackingMode' => $user->employee?->tracking_mode?->value,
            'twoFactorEnabled' => $user->hasConfirmedTwoFactor(),
            // Phase 4. The timer bar and the task-detail timer are mounted on THIS and on
            // nothing else — it is `TimeEntryPolicy::track`, the same gate the endpoints run,
            // resolved on the server per user.
            //
            // It is here rather than derived in Vue from `role` or `trackingMode` for the
            // reason decisions 2-28 and 2-31 were both recorded: a policy restated in a
            // component is a second copy of the rule, and the copy is the one nobody updates.
            // An office employee therefore gets no timer UI at all, not a disabled one.
            'canTrackTime' => Gate::forUser($user)->allows('track', TimeEntry::class),
        ];
    }
}
