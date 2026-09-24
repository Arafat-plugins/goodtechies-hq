<?php

namespace App\Http\Middleware;

use App\Models\TimeEntry;
use App\Services\ConversationService;
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
            // Phase 6. The sidebar's Messages badge is on every page, so its number has to be
            // too — `conversations` is a prop of the Messages page and nothing else can see it.
            // The sidebar refreshes it with `usePagePoll(['messagesUnread'])` (Part 0.5), so it
            // moves without a navigation and without a second endpoint to keep in step.
            'messagesUnread' => fn (): int => $this->messagesUnread($request),
        ];
    }

    /**
     * How many messages are waiting for this person, across every conversation in their inbox.
     *
     * Both halves are `ConversationService`'s, deliberately: `inboxFor()` already answers "which
     * conversations may this person see" through `ConversationPolicy` and already returns an
     * empty collection for somebody without `messages.use` — so the Accountant gets 0 here
     * without this file naming a role, and a change to who may message whom changes this number
     * by itself. Restating either rule here would be the second copy nobody updates.
     */
    private function messagesUnread(Request $request): int
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return 0;
        }

        $conversations = app(ConversationService::class);

        return array_sum($conversations->unreadCounts($user, $conversations->inboxFor($user)));
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
