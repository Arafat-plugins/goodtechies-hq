<?php

namespace App\Http\Controllers\Shared;

use App\Exceptions\ConversationStateException;
use App\Exceptions\FileStateException;
use App\Http\Controllers\Concerns\BuildsDiscussionPayload;
use App\Http\Controllers\Controller;
use App\Http\Requests\Conversation\StoreMessageRequest;
use App\Http\Resources\FileResource;
use App\Models\Conversation;
use App\Models\File;
use App\Models\Message;
use App\Models\Task;
use App\Models\User;
use App\Services\ConversationService;
use App\Services\MessageService;
use App\Support\ConversationType;
use App\Support\Permission;
use App\Support\Surface;
use App\Support\UserStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Messages page: the team channel, the project channels, the announcements channel and
 * this person's DMs, with one thread open beside them (master prompt Part D §10, Phase 6).
 *
 * ## Shared, not one copy per surface
 *
 * Whose mail a thread is belongs to the PERSON, not to the shell they are looking at — the same
 * reasoning that put `/notifications`, `/attendance` and `/leave` in `routes/shared.php`
 * (decision 4-15's shape). Three copies of these five routes would have been three places for
 * "may this person read this conversation" to be answered differently, and the Accountant's
 * copy would have been the one nobody tested.
 *
 * `Pages/Shared/Messages.vue` picks its layout from `auth.user.surface`, exactly as Profile,
 * Attendance and Leave do, so an Accountant would get `AccountantLayout` — except that they
 * never arrive, because the whole group is gated on `messages.use` and they hold none. That is
 * "the Accountant has no messaging routes", said as a permission rather than as a role.
 *
 * ## Refusals: 403 for the capability, 404 for the record
 *
 *   - **403** on every route in the group for somebody who may not use messaging at all. The
 *     route exists and their answer to it is no; that is a capability, not a secret.
 *   - **404** for a conversation this person may not open — a project channel of a project they
 *     are not on, somebody else's DM. `visibleConversation()` is the one place that says so, and
 *     it never distinguishes "does not exist" from "not yours" (Part C).
 *   - **403** for posting where they may read but not write, which is the announcements channel
 *     and only it. Nothing is hidden there; the act is refused.
 */
class MessageController extends Controller
{
    use BuildsDiscussionPayload;

    public function __construct(
        private readonly ConversationService $conversations,
        private readonly MessageService $messages,
    ) {}

    /**
     * The page: every conversation this person may open, and optionally one of them opened.
     *
     * `?conversation=` rather than a route per thread, because the list and the thread are one
     * screen — the plan draws them side by side — and the id in the query string is what makes
     * a thread bookmarkable and linkable from a notification without the page being two routes
     * that each have to decide what the other is showing (§5.10, and decision 2-41's shape).
     */
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $conversations = $this->conversations->inboxFor($user);
        $latest = $this->conversations->latestMessages($conversations);
        $unread = $this->conversations->unreadCounts($user, $conversations);

        $active = $this->requestedConversation($request, $conversations);

        if ($active !== null) {
            // Opening a thread is what marks it read — the same event `last_read_at` records
            // for a task discussion. The unread counts above were taken BEFORE this, so the
            // list still shows the reader where they were when they arrived.
            $this->conversations->markRead($user, $active);
        }

        return Inertia::render('Shared/Messages', [
            'conversations' => $conversations
                ->map(fn (Conversation $conversation): array => $this->summary(
                    $conversation,
                    $user,
                    $latest->get($conversation->getKey()),
                    $unread[(int) $conversation->getKey()] ?? 0,
                ))
                ->all(),
            'active' => $active === null ? null : $this->threadPayload($request, $active),
            'announcement' => $this->banner($user),
            'people' => $this->messageablePeople($user),
        ]);
    }

    /**
     * Search this person's messages, as JSON.
     *
     * **Scoped before it is run, never filtered after.** ConversationService::search() builds the
     * candidate ids from the inbox — already policy-checked row by row — and the `ILIKE` runs
     * only inside them, so a term that appears in a project channel the requester is not on is
     * not discoverable here, not as a row and not as a count (Part C).
     *
     * A term shorter than two characters, or none at all, is **200 with nothing in it**. A
     * half-typed search box is not a malformed request, and a 422 there would make a screen that
     * searches as you type flash an error on every first keystroke. Over a hundred characters
     * IS a 422: that is not somebody typing.
     *
     * The excerpt is cut HERE rather than in the service, because how much of a message a row
     * shows is presentation — the same split `summary()` below draws for the inbox list.
     */
    public function search(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $term = trim((string) ($validated['q'] ?? ''));

        if (mb_strlen($term) < ConversationService::SEARCH_MINIMUM) {
            return response()->json(['query' => $term, 'results' => [], 'has_more' => false]);
        }

        $found = $this->conversations->search($user, $term);

        return response()->json([
            'query' => $term,
            'results' => $found
                ->take(ConversationService::SEARCH_LIMIT)
                ->map(fn (Message $message): array => [
                    'message_id' => (int) $message->getKey(),
                    'conversation_id' => (int) $message->conversation_id,
                    'conversation_label' => $message->conversation?->labelFor($user) ?? 'Conversation',
                    'conversation_type' => $message->conversation?->type?->value,
                    'author' => $message->author?->name,
                    'excerpt' => self::matchExcerpt((string) $message->body, $term),
                    'created_at' => $message->created_at?->toIso8601String(),
                ])
                ->values()
                ->all(),
            // The service read one row past the window precisely so this is not a second count
            // over the same scope.
            'has_more' => $found->count() > ConversationService::SEARCH_LIMIT,
        ]);
    }

    /**
     * One thread, as JSON.
     *
     * What the screen re-reads after posting, after being told a message arrived, and when its
     * signed attachment links lapse — the same three jobs `GET …/tasks/{task}/discussion` does
     * for the task panel. **It is also the seam realtime lands on:** a `conversation.{id}`
     * broadcast tells the screen that something happened, and the screen answers by asking this
     * endpoint for the truth, so the payload a live update paints is the payload the policy
     * built and never one assembled from a broadcast frame.
     *
     * `?before=` walks backwards through the history a window at a time.
     */
    public function show(Request $request, Conversation $conversation): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $conversation = $this->visibleConversation($request, $conversation);

        $this->conversations->markRead($user, $conversation);

        $before = $request->integer('before');

        return response()->json($this->threadPayload($request, $conversation, $before > 0 ? $before : null));
    }

    /**
     * What is beside a thread: who is in it, what has been posted into it, and the work it is
     * about. The right-hand panel of the Messages page.
     *
     * Resolved through the same `visibleConversation()` `show()` uses, so a conversation this
     * person may not open is **404** here exactly as it is there — one answer to "may you see
     * this room", not two.
     *
     * **It does not mark anything read.** A side panel is not a visit: the unread line moves
     * when somebody OPENS a thread, and a panel that quietly cleared it would make the count on
     * the list row disagree with what the reader has actually seen.
     *
     * `members` carries an id and a name and **nothing else** — no role, no availability, no
     * email. A members list is not a second, unpoliced copy of the Team directory's payload;
     * this panel answers "who is in this room", and the Team page answers the other question.
     * The requester is in it, because they are in the room.
     *
     * `project` and `tasks` are **absent** when there is no linked project or when this reader
     * may not see it — the key is gone, not null (Part C, decisions C-1…C-7). A null would say
     * "there is a project here and you may not have it", which is the sentence the whole rule
     * exists to avoid.
     */
    public function context(Request $request, Conversation $conversation): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $conversation = $this->visibleConversation($request, $conversation);

        $payload = [
            'conversation_id' => (int) $conversation->getKey(),
            'type' => $conversation->type?->value,
            'members' => $this->conversations->mentionableIn($conversation)
                ->map(fn (User $person): array => [
                    'id' => (int) $person->getKey(),
                    'name' => (string) $person->name,
                ])
                ->values()
                ->all(),
            // Through FileResource unchanged — the same signed expiring URL and the same
            // `permissions` block the bubble and the Files tab get — with the pivot's `kind`
            // laid beside it, the way MessageResource already does it.
            'files' => $this->conversations->attachmentsIn($conversation)
                ->map(fn (File $file): array => (new FileResource($file))->resolve($request) + [
                    'kind' => $file->pivot?->kind,
                ])
                ->values()
                ->all(),
        ];

        $project = $conversation->project;

        if ($project === null || ! Gate::forUser($user)->allows('view', $project)) {
            return response()->json($payload);
        }

        $base = $this->surfaceBase($user);

        return response()->json($payload + [
            'project' => [
                'id' => (int) $project->getKey(),
                'name' => (string) $project->name,
                'status' => $project->status?->value,
                'href' => $base.'/projects/'.$project->getKey(),
            ],
            'tasks' => $this->conversations->projectTasksFor($user, $project)
                ->map(fn (Task $task): array => [
                    'id' => (int) $task->getKey(),
                    'title' => (string) $task->title,
                    'status' => $task->status?->value,
                    'href' => $base.'/tasks/'.$task->getKey(),
                ])
                ->values()
                ->all(),
        ]);
    }

    /**
     * Post into a conversation.
     *
     * Two gates, in this order and for two different reasons: the conversation is resolved
     * through `visibleConversation()` first, so one this person may not see is 404 before the
     * question of posting arises; then MessageService asks `ConversationPolicy::post`, which is
     * what makes the announcements channel read-only for everybody but a holder of
     * `announcements.send` — a 403, because nothing about it is hidden.
     */
    public function store(StoreMessageRequest $request, Conversation $conversation): RedirectResponse
    {
        $conversation = $this->visibleConversation($request, $conversation);

        try {
            $this->messages->post(
                $request->user(),
                $conversation,
                $request->body(),
                $request->upload(),
                $request->mentionIds(),
            );
        } catch (ConversationStateException|FileStateException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Message sent.');
    }

    /**
     * Move this person's unread line to now.
     *
     * Its own endpoint because reading is its own act: the page marks a thread read when it is
     * opened, and a screen that has been sitting open while somebody else typed needs a way to
     * say "I have seen that" that is not posting a reply.
     */
    public function read(Request $request, Conversation $conversation): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->conversations->markRead($user, $this->visibleConversation($request, $conversation));

        // Nothing is announced. Marking something read is not news, and the count going quiet
        // is the feedback — decision 2-40, applied to the other kind of unread line.
        return back();
    }

    /**
     * Open the DM with this person, creating it the first time.
     *
     * The target is resolved through `messageable()`, so somebody who is deactivated or who may
     * not use messaging is **404** — not a greyed-out button, and not a 403 that would confirm
     * the account exists. That is where *"DMs are 1:1 between any two non-Accountant active
     * users"* is enforced, and it names no role: the Accountant falls out by holding no
     * `messages.use`.
     */
    public function direct(Request $request, User $user): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        abort_unless($this->messageable($user) && (int) $user->getKey() !== (int) $actor->getKey(), 404);

        $conversation = $this->conversations->dmBetween($actor, $user);

        abort_if($conversation === null, 404);

        return redirect()->route('messages.index', ['conversation' => $conversation->getKey()]);
    }

    /*
    |--------------------------------------------------------------------------
    | The pieces
    |--------------------------------------------------------------------------
    */

    /**
     * One row of the conversation list.
     *
     * The last message is an EXCERPT, cut on the server, because the row has one line to spend
     * and a four-thousand-character message would otherwise arrive in full for every row in the
     * inbox. Its author is named, because "who spoke last" is the thing that makes a channel
     * list readable — and that is the only count-shaped fact in this payload. There is no
     * message count per person anywhere in this application (Part H).
     *
     * @return array<string, mixed>
     */
    private function summary(Conversation $conversation, User $user, ?Message $latest, int $unread): array
    {
        return [
            'id' => (int) $conversation->getKey(),
            'type' => $conversation->type?->value,
            'group' => $conversation->type?->group(),
            'label' => $conversation->labelFor($user),
            'unread_count' => $unread,
            'last_message' => $latest === null ? null : [
                'author' => $latest->author?->name,
                'is_mine' => (int) $latest->author_id === (int) $user->getKey(),
                'excerpt' => self::excerpt($latest),
                'created_at' => $latest->created_at?->toIso8601String(),
            ],
        ];
    }

    /**
     * The one line a list row shows of the last thing said.
     *
     * A message with only a file on it has no body, and printing nothing would make the row
     * look like an empty conversation — so it says what it is instead.
     */
    private static function excerpt(Message $message): string
    {
        $body = trim((string) preg_replace('/\s+/u', ' ', (string) $message->body));

        if ($body === '') {
            return 'Sent an attachment';
        }

        return mb_strlen($body) > 120 ? rtrim(mb_substr($body, 0, 120)).'…' : $body;
    }

    /**
     * The window of a message a search hit shows: plain text, cut on the server, centred on the
     * first match.
     *
     * Centred rather than taken from the front, because a hit four thousand characters in would
     * otherwise be reported as an excerpt that does not contain the word the reader typed — a
     * result that looks like a bug. The `…` are only added on the side that was actually cut, so
     * the marks mean something.
     *
     * It carries no markup. Highlighting is the screen's job and a server that emitted `<mark>`
     * would be a server emitting HTML into a JSON payload for a Vue template to trust.
     */
    private static function matchExcerpt(string $body, string $term): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $body));
        $length = 160;
        $total = mb_strlen($text);

        if ($total <= $length) {
            return $text;
        }

        $position = mb_stripos($text, $term);
        $lead = max(0, (int) (($length - mb_strlen($term)) / 2));

        $start = max(0, ($position === false ? 0 : $position) - $lead);
        $start = min($start, $total - $length);

        $cut = mb_substr($text, $start, $length);

        return ($start > 0 ? '…' : '').trim($cut).($start + $length < $total ? '…' : '');
    }

    /**
     * Where this reader's own shell keeps a project or a task.
     *
     * The panel links into the surface the person is actually in — an employee sent to
     * `/admin/projects/12` would meet a 403 dressed up as a link. The Accountant never reaches
     * this code at all: the whole route group is gated on `messages.use` and they hold none.
     */
    private function surfaceBase(User $user): string
    {
        return $user->surface() === Surface::Admin ? '/admin' : '/employee';
    }

    /**
     * The conversation `?conversation=` asked for, if this person may open it.
     *
     * Looked up inside the inbox that was just built rather than by a fresh query: the inbox is
     * already policy-checked row by row, so an id that is not in it is one this person may not
     * open, and it answers null — the page then opens on the team channel instead of 404ing a
     * whole screen because one query parameter was stale. A stale `?conversation=` is what a
     * bookmark to a project you have since left looks like, and that is not an error.
     *
     * @param  Collection<int, Conversation>  $inbox
     */
    private function requestedConversation(Request $request, $inbox): ?Conversation
    {
        $id = $request->integer('conversation');

        if ($id > 0) {
            $found = $inbox->first(fn (Conversation $conversation): bool => (int) $conversation->getKey() === $id);

            if ($found !== null) {
                return $found;
            }
        }

        // Nothing asked for, or nothing they may open: the team channel, which everybody with
        // `messages.use` can read, is the honest default for a page called Messages.
        return $inbox->first(
            fn (Conversation $conversation): bool => $conversation->type === ConversationType::Team,
        ) ?? $inbox->first();
    }

    /**
     * The conversation, if this requester may see it at all. A record they may not see is
     * absent, so this is a 404 and never a 403 (Part C).
     */
    private function visibleConversation(Request $request, Conversation $conversation): Conversation
    {
        abort_unless(Gate::forUser($request->user())->allows('view', $conversation), 404);

        return $conversation;
    }

    /**
     * The newest announcement, for the banner the plan asks for.
     *
     * Read through the policy like everything else — if this person cannot see the
     * announcements channel there is no banner, rather than a banner that 404s when clicked.
     *
     * @return array<string, mixed>|null
     */
    private function banner(User $user): ?array
    {
        $channel = $this->conversations->announcements();

        if (! Gate::forUser($user)->allows('view', $channel)) {
            return null;
        }

        $latest = $channel->messages()->with('author')->reorder('id', 'desc')->first();

        if ($latest === null) {
            return null;
        }

        $state = $this->conversations->readState($user, $channel);

        return [
            'conversation_id' => (int) $channel->getKey(),
            'body' => (string) $latest->body,
            'author' => $latest->author?->name,
            'created_at' => $latest->created_at?->toIso8601String(),
            // Whether this reader has seen it. The banner is not dismissed by a click on the
            // banner: it goes quiet when the channel is read, which is one state and not two.
            'is_unread' => $state['unread_count'] > 0,
        ];
    }

    /**
     * Everybody this person can start a DM with: active, holding `messages.use`, not themselves.
     *
     * Names and ids only. A message picker is not a directory — the Team page is — and a role
     * or an availability here would be a second, unpoliced copy of that page's payload.
     *
     * @return list<array{id: int, name: string}>
     */
    private function messageablePeople(User $actor): array
    {
        return User::query()
            ->where('status', UserStatus::Active->value)
            ->orderBy('name')
            ->get()
            ->filter(fn (User $user): bool => $this->messageable($user))
            ->reject(fn (User $user): bool => (int) $user->getKey() === (int) $actor->getKey())
            ->map(fn (User $user): array => ['id' => (int) $user->getKey(), 'name' => (string) $user->name])
            ->values()
            ->all();
    }

    /**
     * May this person be sent a direct message at all?
     *
     * Active, and holding the messaging key. Asked as a permission so the Accountant is absent
     * from every picker without their role appearing in this file (decisions 2-13, 2-31).
     */
    private function messageable(User $user): bool
    {
        return $user->isActive() && $user->hasPermission(Permission::MessagesUse);
    }
}
