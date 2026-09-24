<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\File;
use App\Models\Message;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Support\ConversationType;
use App\Support\Permission;
use App\Support\UserStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * The rooms: finding or creating each kind of conversation, listing the ones a person is in,
 * and how far they have read. Writing a message is MessageService's, and only its.
 *
 * ## One store
 *
 * The spec lists a `task_comments` table AND a `task`-type conversation. The recorded decision
 * is one store: every task gets a `conversations` row of type `task` when it is created, and
 * its comments are its `messages`. `task_comments` does not exist and must not be added.
 *
 * ## Membership is computed, for every type
 *
 * Nothing in this class reads `conversation_members` to decide who may do anything. Who may
 * read or post is ConversationPolicy, which asks the linked task's or project's own policy, the
 * `messages.use` permission, or a DM's two columns — see ConversationType for the table of
 * which is which. `conversation_members` is touched only by markRead(), and only to move a
 * timestamp.
 *
 * ## Every "the" conversation is a firstOrCreate behind a unique index
 *
 * `forTask`, `forProject`, `team`, `announcements` and `dmBetween` all read the same way and
 * all mean it literally: there is a partial unique index behind each one, so however many
 * callers race for a room they get the same room. That is what makes it safe to call any of
 * them from a controller, a seeder, a backfill or a job without any of them knowing whether
 * somebody else got there first.
 */
class ConversationService
{
    /**
     * The relations a thread payload needs, so a panel is not a query per bubble.
     *
     * @var list<string>
     */
    public const MESSAGE_RELATIONS = [
        'author',
        'attachments.uploader',
        'mentions',
    ];

    /**
     * How many messages a thread sends at once.
     *
     * A channel that has been running for a year is not a payload. The newest N are what a chat
     * screen opens on — the same window Telegram opens on — and older ones are read by asking
     * for them (`?before=`), which is one parameter rather than a pagination component in a
     * thread that is read upwards.
     */
    public const THREAD_WINDOW = 50;

    /**
     * How many hits a search answers with.
     *
     * One screenful and a bit. Search here is "find the message I am thinking of", not a report
     * — somebody who needs the thirty-first hit needs a better term, and paginating a result set
     * that is already scoped to one person's inbox would be a second window to keep in step
     * with the thread's.
     */
    public const SEARCH_LIMIT = 30;

    /**
     * The shortest term worth running.
     *
     * A single character matches most of the inbox, so it is answered with nothing rather than
     * with everything — and with 200, because a half-typed term is not a malformed request.
     */
    public const SEARCH_MINIMUM = 2;

    /**
     * How many attachments and tasks the context panel carries.
     *
     * The panel is a glance, not the Files tab and not the Tasks list; both of those already
     * exist and both are reached from it.
     */
    public const CONTEXT_FILES = 20;

    public const CONTEXT_TASKS = 10;

    public function __construct(private readonly ActivityLogger $activity) {}

    /*
    |--------------------------------------------------------------------------
    | The rooms
    |--------------------------------------------------------------------------
    */

    /**
     * The task's discussion, creating it if this task predates conversations.
     *
     * `firstOrCreate` rather than `create`, and that is load-bearing in three different ways:
     *
     *   - TASKS THAT PREDATE CONVERSATIONS. The conversations migration backfills one row per
     *     existing task, so a running database is complete the moment it migrates. This is the
     *     belt to that migration's braces.
     *   - THE SEEDER AND THE FACTORY. `Task::factory()` and TaskSeeder's `firstOrCreate` do not
     *     go through TaskService::create(), so they would otherwise produce tasks with no
     *     conversation and tests that pass for the wrong reason.
     *   - IDEMPOTENCE. `conversations_one_per_task` is a unique index, so "the" conversation is
     *     literal however many callers race for it.
     */
    public function forTask(Task $task): Conversation
    {
        return Conversation::query()->firstOrCreate(
            [
                'type' => ConversationType::Task,
                'linked_task_id' => $task->getKey(),
            ],
            [
                // No title. A task discussion is named by its task, and a copy of the title
                // here would be a second one to keep in step with every rename.
                'title' => null,
            ],
        );
    }

    /**
     * The project's channel.
     *
     * Created with the project from Phase 6 on (ProjectService::create) and backfilled for every
     * project that predates it, so in practice this always finds one. It is a `firstOrCreate`
     * for the third case the backfill's own docblock names: a project created by the old code in
     * the window while the migration ran belongs to neither half, and the first person to open
     * its Discussion tab makes its channel rather than meeting a 404 forever.
     */
    public function forProject(Project $project): Conversation
    {
        return Conversation::query()->firstOrCreate(
            [
                'type' => ConversationType::Project,
                'linked_project_id' => $project->getKey(),
            ],
            ['title' => null],
        );
    }

    /**
     * The one team channel.
     */
    public function team(): Conversation
    {
        return $this->singleton(ConversationType::Team, 'Team');
    }

    /**
     * The one announcements channel. Everybody with `messages.use` reads it; only a holder of
     * `announcements.send` may post — ConversationPolicy, not this class.
     */
    public function announcements(): Conversation
    {
        return $this->singleton(ConversationType::Announcement, 'Announcements');
    }

    /**
     * The DM between these two people, creating it the first time somebody opens it.
     *
     * The pair is stored ORDERED — `dm_one_id < dm_two_id`, which the table's CHECK insists on —
     * so "the DM between A and B" and "the DM between B and A" are the same lookup and the same
     * row, without either side of the application remembering to sort. Two people clicking
     * *Message* on each other in the same second get one thread, because
     * `conversations_one_per_dm_pair` says so.
     *
     * A DM with yourself is not a thing; the caller is expected to have refused it (the picker
     * does not offer you) and this refuses it again, because a self-DM would break the ordered
     * pair the CHECK requires.
     */
    public function dmBetween(User $one, User $two): ?Conversation
    {
        $ids = [(int) $one->getKey(), (int) $two->getKey()];

        if ($ids[0] === $ids[1]) {
            return null;
        }

        sort($ids);

        return Conversation::query()->firstOrCreate(
            [
                'type' => ConversationType::Dm,
                'dm_one_id' => $ids[0],
                'dm_two_id' => $ids[1],
            ],
            ['title' => null],
        );
    }

    private function singleton(ConversationType $type, string $title): Conversation
    {
        return Conversation::query()->firstOrCreate(['type' => $type], ['title' => $title]);
    }

    /*
    |--------------------------------------------------------------------------
    | The inbox
    |--------------------------------------------------------------------------
    */

    /**
     * Every conversation this person may open, newest activity first within its group.
     *
     * **Every row is policy-checked.** The queries below narrow by the cheap, list-shaped half
     * of each rule — the projects they can see, the DMs they are named in — and then every
     * candidate goes through `ConversationPolicy::view` one at a time. That is the same split
     * NotificationDispatcher draws between a candidate list and a per-object gate, for the same
     * reason: the list-shaped query is an optimisation and the policy is the rule, and if the
     * two ever disagree the policy has to win.
     *
     * Task discussions are deliberately absent: there is one per task, they are read inside
     * their task, and an inbox holding three hundred of them is not an inbox.
     *
     * @return Collection<int, Conversation>
     */
    public function inboxFor(User $user): Collection
    {
        if (! $user->isActive() || ! $user->hasPermission(Permission::MessagesUse)) {
            return new Collection;
        }

        $candidates = Conversation::query()
            ->with(['project', 'dmOne', 'dmTwo'])
            ->whereIn('type', [
                ConversationType::Team->value,
                ConversationType::Announcement->value,
                ConversationType::Project->value,
            ])
            ->orWhere(fn ($query) => $query->dmsFor($user))
            ->get();

        return $candidates
            ->filter(fn (Conversation $conversation): bool => Gate::forUser($user)->allows('view', $conversation))
            ->values();
    }

    /**
     * The newest message in each of these conversations, keyed by conversation id.
     *
     * One query for the whole inbox rather than one per row: a `DISTINCT ON` is the Postgres
     * way to say "the latest per group", and the `(conversation_id, id)` index already exists
     * for it.
     *
     * @param  iterable<int, Conversation>  $conversations
     * @return Collection<int, Message>
     */
    public function latestMessages(iterable $conversations): Collection
    {
        $ids = [];

        foreach ($conversations as $conversation) {
            $ids[] = (int) $conversation->getKey();
        }

        if ($ids === []) {
            return new Collection;
        }

        $latest = Message::query()
            ->whereIn('conversation_id', $ids)
            ->selectRaw('DISTINCT ON (conversation_id) *')
            ->orderBy('conversation_id')
            ->orderByDesc('id')
            ->with('author')
            ->get();

        return $latest->keyBy('conversation_id');
    }

    /**
     * How many unread messages this person has in each of these conversations, keyed by id.
     *
     * One grouped query over the whole inbox, with the reader's own messages excluded for the
     * reason readState() excludes them: a count that goes up when you post is measuring the
     * wrong thing.
     *
     * @param  iterable<int, Conversation>  $conversations
     * @return array<int, int>
     */
    public function unreadCounts(User $user, iterable $conversations): array
    {
        $ids = [];

        foreach ($conversations as $conversation) {
            $ids[] = (int) $conversation->getKey();
        }

        if ($ids === []) {
            return [];
        }

        $rows = DB::table('messages')
            ->leftJoin('conversation_members', function ($join) use ($user) {
                $join->on('conversation_members.conversation_id', '=', 'messages.conversation_id')
                    ->where('conversation_members.user_id', '=', $user->getKey());
            })
            ->whereIn('messages.conversation_id', $ids)
            ->where('messages.author_id', '!=', $user->getKey())
            ->where(function ($query) {
                $query->whereNull('conversation_members.last_read_at')
                    ->orWhereColumn('messages.created_at', '>', 'conversation_members.last_read_at');
            })
            ->groupBy('messages.conversation_id')
            ->selectRaw('messages.conversation_id, count(*) as total')
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            $counts[(int) $row->conversation_id] = (int) $row->total;
        }

        return $counts;
    }

    /*
    |--------------------------------------------------------------------------
    | Searching
    |--------------------------------------------------------------------------
    */

    /**
     * Messages matching a term — inside the conversations this person may open, and nowhere
     * else.
     *
     * **The scope is built before the term is, and that order is the whole rule.** The candidate
     * ids come from `inboxFor()`, which has already put every row through
     * `ConversationPolicy::view` one at a time, and the `ILIKE` runs only inside them. The
     * alternative — search `messages`, then drop what the reader may not have — would have made
     * the *number* of hits a fact about conversations they cannot open: a term that appears once
     * in a project channel they are not on would be discoverable by the shape of the answer even
     * when no row of it was ever sent. Part C says a record they may not see is absent, and a
     * count of absent records is not absent.
     *
     * Task discussions are outside it for the reason they are outside the inbox: they are read
     * inside their task, and a search over three hundred of them is not this feature.
     *
     * Returns up to `$limit + 1` rows. The extra one is the probe a *has more* flag is decided
     * from, read in the same statement rather than as a second `count()` over the same scope.
     *
     * @return Collection<int, Message>
     */
    public function search(User $user, string $term, int $limit = self::SEARCH_LIMIT): Collection
    {
        $term = trim($term);

        if (mb_strlen($term) < self::SEARCH_MINIMUM) {
            return new Collection;
        }

        $ids = $this->inboxFor($user)
            ->map(fn (Conversation $conversation): int => (int) $conversation->getKey())
            ->all();

        if ($ids === []) {
            return new Collection;
        }

        return Message::query()
            ->whereIn('conversation_id', $ids)
            // ILIKE because this is PostgreSQL and a search nobody can spell the case of is not
            // a search. A message with no body is an attachment-only one and cannot match.
            ->where('body', 'ILIKE', '%'.self::escapeLike($term).'%')
            // The row's own conversation, with what `labelFor()` needs to name it: the label is
            // per reader, so it cannot be a column and it cannot be a query per hit.
            ->with(['author', 'conversation.project', 'conversation.dmOne', 'conversation.dmTwo'])
            ->orderByDesc('id')
            ->limit(max(1, $limit) + 1)
            ->get();
    }

    /**
     * Make a user's term mean itself inside a `LIKE` pattern.
     *
     * Without this a `%` typed into the search box matches everything and a `_` matches any
     * character — not a security hole, the scope above is what holds, but a search that quietly
     * ignores two of the characters on the keyboard.
     */
    private static function escapeLike(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term);
    }

    /*
    |--------------------------------------------------------------------------
    | The context panel
    |--------------------------------------------------------------------------
    */

    /**
     * The files posted into this conversation, newest first.
     *
     * Read through the MESSAGES rather than off `files.message_id` directly, because the panel
     * wants each file with the `message_attachments` pivot beside it — `kind` is how the file
     * rides on its bubble and is not a fact about the file — and that is the same relation, and
     * therefore the same answer, `MessageResource` already draws attachments from. Deriving the
     * kind again from the mime type here would be a second opinion about a stored column.
     *
     * A file that has been removed drops out on its own: `attachments()` goes through the File
     * model, which soft-deletes, so the row in the join table is not what decides whether there
     * is a file.
     *
     * The caller has ALREADY established that this person may see the conversation. An
     * attachment is exactly as visible as the thread it was posted into, which is the shape
     * `messages()` above has and for the same reason.
     *
     * @return SupportCollection<int, File>
     */
    public function attachmentsIn(Conversation $conversation, int $limit = self::CONTEXT_FILES): SupportCollection
    {
        $limit = max(1, $limit);

        /** @var Collection<int, Message> $carriers */
        $carriers = $conversation->messages()
            ->whereHas('attachments')
            ->with(['attachments.uploader'])
            ->reorder('id', 'desc')
            ->limit($limit)
            ->get();

        return $carriers
            ->flatMap(fn (Message $message): iterable => $message->attachments->sortByDesc('id')->values())
            ->take($limit)
            ->values();
    }

    /**
     * This project's tasks, as far as THIS reader can see them, newest first.
     *
     * `Task::visibleTo()` and not the project's task list: somebody can be on a project channel
     * and still not be on every task in it, and the panel is a shortcut into work rather than a
     * second, unpoliced copy of the Tasks list. The caller has already asked
     * `ProjectPolicy::view` about the project itself; this is the narrower question underneath
     * it.
     *
     * @return Collection<int, Task>
     */
    public function projectTasksFor(User $user, Project $project, int $limit = self::CONTEXT_TASKS): Collection
    {
        return Task::query()
            ->visibleTo($user)
            ->where('project_id', $project->getKey())
            ->orderByDesc('id')
            ->limit(max(1, $limit))
            ->get();
    }

    /*
    |--------------------------------------------------------------------------
    | Reading a thread
    |--------------------------------------------------------------------------
    */

    /**
     * The messages, oldest first, with their authors, attachments and mentions.
     *
     * NOT scoped by the requester, deliberately: the caller has already established that this
     * person may see the conversation, and that is the whole of the rule — a message is exactly
     * as visible as the discussion it is in, and no more. The same shape FileService::for() has,
     * for the same reason.
     *
     * `$before` walks backwards a window at a time. The rows come back oldest-first either way,
     * because a thread is read downwards however it was fetched.
     *
     * @return Collection<int, Message>
     */
    public function messages(Conversation $conversation, ?int $before = null, ?int $limit = null): Collection
    {
        $limit = $limit ?? self::THREAD_WINDOW;

        $window = $conversation->messages()
            ->with(self::MESSAGE_RELATIONS)
            ->when($before !== null, fn ($query) => $query->where('id', '<', $before))
            ->reorder('id', 'desc')
            ->limit(max(1, $limit))
            ->get();

        return $window->sortBy('id')->values();
    }

    /**
     * Is there anything older than the window just fetched? What a *Load earlier* control needs.
     */
    public function hasOlderThan(Conversation $conversation, ?int $oldestId): bool
    {
        if ($oldestId === null) {
            return false;
        }

        return $conversation->messages()->where('id', '<', $oldestId)->exists();
    }

    /**
     * Move this person's unread line to now.
     *
     * The ONLY thing that writes `conversation_members`, and all it writes is a timestamp. A
     * row here is read state, not an access grant: ConversationPolicy never looks at it, so one
     * left behind by somebody who has since been taken off the task — or the project, or
     * deactivated — grants them nothing.
     *
     * `updateExistingPivot` rather than `syncWithoutDetaching`, so an existing row keeps its
     * identity and only its timestamp moves.
     */
    public function markRead(User $user, Conversation $conversation): void
    {
        $existing = $conversation->members()->whereKey($user->getKey())->exists();

        $existing
            ? $conversation->members()->updateExistingPivot($user->getKey(), ['last_read_at' => now()])
            : $conversation->members()->attach($user->getKey(), ['last_read_at' => now()]);
    }

    /**
     * How many messages this person has not read, and when their line sits.
     *
     * Null `last_read_at` means they have never opened it, so everything is unread. Their OWN
     * messages are excluded: a count that goes up when you post is a count that is measuring
     * the wrong thing.
     *
     * @return array{last_read_at: string|null, unread_count: int}
     */
    public function readState(User $user, Conversation $conversation): array
    {
        $member = $conversation->members()->whereKey($user->getKey())->first();

        // The pivot carries no casts, so the timestamp arrives as a string. Parsed here rather
        // than by giving the pivot a model of its own: one call site, one line.
        $raw = $member?->pivot?->last_read_at;
        $lastReadAt = $raw === null ? null : Carbon::parse($raw);

        $unread = $conversation->messages()
            ->where('author_id', '!=', $user->getKey())
            ->when($lastReadAt !== null, fn ($query) => $query->where('created_at', '>', $lastReadAt))
            ->count();

        return [
            'last_read_at' => $lastReadAt === null ? null : $lastReadAt->toIso8601String(),
            'unread_count' => $unread,
        ];
    }

    /**
     * Who may be @mentioned in this conversation: the people who can already read it.
     *
     * The picker's option list and the mention rule are **the same set**, computed here once, so
     * a name a composer offers is never one the server then refuses. It is policy-checked per
     * person rather than derived from a role or from `conversation_members`, which means the
     * Accountant is absent from every picker in the application without being named in any of
     * them — they hold no `messages.use`, and for a task discussion TaskPolicy refuses them.
     *
     * Fifteen people is the whole company (Part A), so this is a full scan and a gate call per
     * active user, deliberately: a cleverer query would be a second statement of five different
     * access rules.
     *
     * @return Collection<int, User>
     */
    public function mentionableIn(Conversation $conversation): Collection
    {
        /** @var Collection<int, User> $active */
        $active = User::query()
            ->where('status', UserStatus::Active->value)
            ->orderBy('name')
            ->get();

        return $active
            ->filter(fn (User $user): bool => Gate::forUser($user)->allows('view', $conversation))
            ->values();
    }

    /**
     * Note in the subject's own activity trail that its discussion moved.
     *
     * Called by MessageService, and here rather than there because the subject of a conversation
     * is this class's business. The BODY is deliberately not in it: `activity_logs` is a
     * different audience from the discussion, and quoting a comment into it would be publishing
     * it twice under one set of rules.
     */
    public function recordActivity(Conversation $conversation, User $actor, bool $withAttachment): void
    {
        $subject = $conversation->subject();

        if ($subject === null) {
            return;
        }

        $this->activity->record($subject, $withAttachment
            ? 'Message posted in the discussion, with an attachment'
            : 'Message posted in the discussion', $actor);
    }
}
