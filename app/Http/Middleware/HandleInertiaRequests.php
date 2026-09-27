<?php

namespace App\Http\Middleware;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\ConversationService;
use App\Support\ConversationType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    public function __construct(private readonly ConversationService $conversations) {}

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
            // The chrome's own live state — the announcement banner and the Messages row's
            // unread indicator, app-wide (decision 6-18, POLISH-BACKLOG §A.3 and §C.1).
            //
            // `Inertia::optional()` and not a plain closure, and that is the whole of what made
            // this shippable: 6-18 priced a global banner as "a prop in HandleInertiaRequests"
            // and stopped there, but a prop resolved on every response is a prop every response
            // PAYS for. Measured on the seeded database it is 8 statements — the announcements
            // channel, the policy-checked inbox with its three eager loads, one grouped unread
            // count and the newest announcement with its author — which is 8 statements added to
            // a catalogue page that runs 3, on every request in the application, for two facts
            // that are then re-read on a timer anyway. `tests/Feature/Performance` has the
            // ceilings that say so.
            //
            // Optional means: absent from an ordinary page render, resolved only on a partial
            // reload that names it. `Components/Realtime/shell.ts` asks for it once per full
            // page load and then on its own interval, holds the answer in module state so an
            // Inertia navigation does not blank the banner, and subscribes to the announcements
            // channel on a socket build. That is the bell's shape (2-39, 6-8) reused rather
            // than a second pattern beside it.
            'shell' => Inertia::optional(fn (): array => $this->sharedShell($request)),
        ];
    }

    /**
     * The shell's live state: the announcement banner, and how many unread messages are waiting.
     *
     * **Both numbers are the policy's.** The inbox is `ConversationService::inboxFor()`, which
     * puts every candidate row through `ConversationPolicy::view` one at a time, and the unread
     * count is a grouped query over exactly those ids — so a project channel this reader is not
     * on is not in the count, and its existence is not inferable from the count either. The
     * banner is the announcements channel read through the same gate: somebody who may not see
     * it gets `null`, not an empty banner and not a banner that 404s when clicked.
     *
     * Nobody signed in, or somebody without `messages.use` — the Accountant — gets the zero
     * shape rather than a missing key. A payload whose SHAPE depends on the reader is a payload
     * no screen can be typed against, which is the rule `timer`/`attendance` already follow on
     * the employee dashboard.
     *
     * @return array{unreadMessages: int, announcement: array{conversation_id: int, body: string, author: string|null, created_at: string|null, is_unread: bool}|null, announcementChannelId: int|null}
     */
    private function sharedShell(Request $request): array
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return ['unreadMessages' => 0, 'announcement' => null, 'announcementChannelId' => null];
        }

        $inbox = $this->conversations->inboxFor($user);

        if ($inbox->isEmpty()) {
            return ['unreadMessages' => 0, 'announcement' => null, 'announcementChannelId' => null];
        }

        $unread = $this->conversations->unreadCounts($user, $inbox);

        $channel = $inbox->first(
            fn (Conversation $conversation): bool => $conversation->type === ConversationType::Announcement,
        );

        return [
            // The whole inbox, in one number. It is what the Messages nav row counts, and it is
            // the same arithmetic the Messages page's own description does over the same rows —
            // one sum of one policy-checked list, so the badge and the page cannot disagree.
            'unreadMessages' => array_sum($unread),
            'announcement' => $channel === null
                ? null
                : $this->banner($channel, ($unread[(int) $channel->getKey()] ?? 0) > 0),
            // What the shell subscribes to on a socket build. There is no per-user inbox
            // channel, so the unread count above is poll-only — but an ANNOUNCEMENT is a message
            // in a conversation that already has a channel and an already policy-backed callback,
            // and everybody who may see the banner is authorised on it. Null when there is no
            // announcements channel for this reader, which is also when there is no banner.
            'announcementChannelId' => $channel === null ? null : (int) $channel->getKey(),
        ];
    }

    /**
     * The newest announcement, in the shape `Components/Shell/AnnouncementBanner.vue` draws.
     *
     * The same five keys `MessageController@index` has sent the Messages page since Phase 6, so
     * the extracted component reads one shape wherever it is mounted. `is_unread` is handed in
     * rather than re-read: the grouped count above already knows, and asking `readState()` again
     * would be a second query answering a question that has been answered.
     *
     * @return array{conversation_id: int, body: string, author: string|null, created_at: string|null, is_unread: bool}|null
     */
    private function banner(Conversation $channel, bool $isUnread): ?array
    {
        /** @var Message|null $latest */
        $latest = $channel->messages()->with('author')->reorder('id', 'desc')->first();

        if ($latest === null) {
            return null;
        }

        return [
            'conversation_id' => (int) $channel->getKey(),
            'body' => (string) $latest->body,
            'author' => $latest->author?->name,
            'created_at' => $latest->created_at?->toIso8601String(),
            // Whether this reader has seen it. The banner is not dismissed by a button: it goes
            // quiet when the announcements channel is read, which is one state and not two.
            'is_unread' => $isUnread,
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
