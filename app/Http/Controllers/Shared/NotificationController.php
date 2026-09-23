<?php

namespace App\Http\Controllers\Shared;

use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use App\Models\Notification;
use App\Models\User;
use App\Services\NotificationService;
use App\Support\NotificationTab;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The bell and the Notification Center.
 *
 * Shared rather than one controller per surface, for the reason FileDownloadController is: a
 * person's own mail is a fact about the person, not about the shell they happen to be looking
 * at. An Admin and an employee ask the same question and get the same answer in the same shape;
 * only the deep links differ, and those are resolved per reader in NotificationResource.
 *
 * ## 404, never 403
 *
 * A notification belongs to exactly one user. Every lookup here starts at `forUser()` and ends
 * at `firstOrFail()`, so somebody else's id answers **404** — a record you may not see is
 * absent, not refused (Part C §1). There is no policy check on a single row and there must not
 * be one: an ability that answered 403 would confirm the row exists.
 *
 * The one gate is on the group — `can:viewAny` — and it asks whether this person could receive
 * any kind of notification at all. See NotificationPolicy.
 *
 * ## GET is JSON, writes redirect back
 *
 * The bell polls, so every read is JSON. The two writes are Inertia `back()`, like every other
 * write in Phase 2.
 *
 * The one exception is `index`, which answers the same payload as an Inertia page when a browser
 * asks for one — see that method. There is no second route and no second controller: the Center
 * IS this list, and the only difference between the two callers is what they can render.
 */
class NotificationController extends Controller
{
    public function __construct(private readonly NotificationService $notifications) {}

    /**
     * The bell: the badge and the newest few.
     *
     * This is the endpoint that is polled every 15 seconds by every signed-in user until Phase
     * 6 replaces the poll with Reverb, so it is deliberately two queries and no joins — a
     * count off the partial index over unread rows, and ten rows off `(user_id, created_at)`.
     * The payload carries everything the dropdown draws, so there is no second request per row
     * and no eager loading to get wrong.
     */
    public function recent(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'unread_count' => $this->notifications->unreadCount($user),
            'notifications' => NotificationResource::collection(
                $this->notifications->recent($user),
            )->resolve($request),
        ]);
    }

    /**
     * The Notification Center's list, one tab at a time.
     *
     * Every tab is described in the response, including the four that cannot hold anything
     * until their phase — `is_built` is what lets the Center say "arrives in Phase 7" against
     * Meetings instead of showing an empty list that reads as a bug. Nothing is faked: an
     * unbuilt tab has no types, so its query returns nothing and its count is zero.
     *
     * ## One payload, two renderers
     *
     * A caller that asked for JSON gets JSON — the contract the bell, the tests and the matrix
     * were written against. Anybody else gets the Notification Center, which is this same array
     * as Inertia props and not one field more. The alternative was a second route rendering a
     * page that then fetched this one, which would have been another controller, another matrix
     * row and a second answer to "which tab am I on" — the tab is a query parameter on a URL
     * somebody can bookmark, and a page that re-read it client-side would own it twice.
     *
     * Because the Center is a page, both writes' `back()` lands on it and re-renders it from the
     * server. Nothing on the screen decrements a count of its own.
     */
    public function index(Request $request): JsonResponse|Response
    {
        /** @var User $user */
        $user = $request->user();

        // An unrecognised tab falls back to All rather than erroring — a stale link asks a
        // question that no longer exists, and the honest answer is the unnarrowed list.
        $tab = NotificationTab::tryFrom((string) $request->query('tab', '')) ?? NotificationTab::All;

        $page = $this->notifications->page($user, $tab);
        $unreadByTab = $this->notifications->unreadByTab($user);

        $payload = [
            'tab' => $tab->value,
            'tabs' => array_map(fn (NotificationTab $one): array => [
                'key' => $one->value,
                'label' => $one->label(),
                'is_built' => $one->isBuilt(),
                'unread_count' => $unreadByTab[$one->value] ?? 0,
            ], NotificationTab::cases()),
            'unread_count' => $this->notifications->unreadCount($user),
            'notifications' => NotificationResource::collection($page->items())->resolve($request),
            // The two blocks `Pagination.vue` takes, in the shape every other list here hands
            // it: `Resource::collection($paginator)` produces exactly this, and the Center
            // turns its pages through the shared component rather than through arithmetic done
            // in Vue. The four keys this method has always sent are still among them.
            'links' => [
                'first' => $page->url(1),
                'last' => $page->url($page->lastPage()),
                'prev' => $page->previousPageUrl(),
                'next' => $page->nextPageUrl(),
            ],
            'meta' => [
                'current_page' => $page->currentPage(),
                'from' => $page->firstItem(),
                'last_page' => $page->lastPage(),
                'links' => $page->linkCollection()->toArray(),
                'path' => $page->path(),
                'per_page' => $page->perPage(),
                'to' => $page->lastItem(),
                'total' => $page->total(),
            ],
        ];

        return $request->expectsJson()
            ? response()->json($payload)
            : Inertia::render('Shared/Notifications', $payload);
    }

    /**
     * Mark one read. A row that is not this person's is absent — see the class docblock.
     */
    public function read(Request $request, Notification $notification): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $notification = Notification::query()
            ->forUser($user)
            ->whereKey($notification->getKey())
            ->firstOrFail();

        $this->notifications->markRead($user, $notification);

        return back();
    }

    /**
     * Mark everything read. Silent about how many, because the bell's own number is the answer
     * and it is one poll away.
     */
    public function readAll(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->notifications->markAllRead($user);

        return back();
    }
}
