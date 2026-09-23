<?php

namespace App\Services;

use App\Events\TaskCommented;
use App\Exceptions\ConversationStateException;
use App\Exceptions\FileStateException;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Task;
use App\Models\User;
use App\Support\AttachmentKind;
use App\Support\ConversationType;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * The task discussion: the conversation a task is born with, and the messages in it.
 *
 * ## One store
 *
 * The spec lists a `task_comments` table AND a `task`-type conversation. The recorded decision
 * is one store: every task gets a `conversations` row of type `task` when it is created, and
 * its comments are its `messages`. `task_comments` does not exist and must not be added.
 *
 * ## Membership follows task access, by being the same question
 *
 * Nothing in this class reads `conversation_members` to decide anything. Who may read or post
 * is ConversationPolicy, which asks TaskPolicy about the linked task — the same call the task
 * list makes — so a reassignment needs no sync here or anywhere else. `conversation_members` is
 * touched only by markRead(), and only to move a timestamp.
 *
 * ## Attachments go through FileService
 *
 * There is one writer of bytes in this application and it is not this class. A message
 * attachment is a File owned by the Message, stored by FileService::store(), which brings the
 * size limit, the extension/MIME pairing, the ULID path, the checksum and the signed expiring
 * URL with it. This class adds the `message_attachments` row that says how the file rides on
 * the bubble, and nothing else.
 *
 * Validation is in the Form Request. Authorization is in ConversationPolicy and MessagePolicy;
 * this class asks the gate and turns a refusal into an exception, so a non-HTTP caller is
 * refused the same way.
 */
class ConversationService
{
    /**
     * The relations a discussion payload needs, so a panel is not a query per bubble.
     *
     * @var list<string>
     */
    public const MESSAGE_RELATIONS = [
        'author',
        'attachments.uploader',
    ];

    public function __construct(
        private readonly FileService $files,
        private readonly ActivityLogger $activity,
    ) {}

    /**
     * The task's discussion, creating it if this task predates conversations.
     *
     * `firstOrCreate` rather than `create`, and that is load-bearing in three different ways:
     *
     *   - TASKS THAT PREDATE CONVERSATIONS. The conversations migration backfills one row per
     *     existing task, so a running database is complete the moment it migrates. This is the
     *     belt to that migration's braces: a task restored from a backup taken before the
     *     backfill, or created by something that bypassed TaskService, still gets its
     *     discussion the first time anybody opens it, instead of 404ing forever.
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
     * The messages, oldest first, with their authors and attachments.
     *
     * NOT scoped by the requester, deliberately: the caller has already established that this
     * person may see the conversation, and that is the whole of the rule — a message is exactly
     * as visible as the discussion it is in, and no more. The same shape FileService::for()
     * has, for the same reason.
     *
     * @return Collection<int, Message>
     */
    public function messages(Conversation $conversation): Collection
    {
        return $conversation->messages()->with(self::MESSAGE_RELATIONS)->get();
    }

    /**
     * Post a message, optionally with a file on it.
     *
     * The order inside the transaction is forced by the schema and is the right order anyway:
     * the message row has to exist before a file can be owned by it (`files.message_id` is a
     * foreign key), and the attachment row has to come last because its composite foreign key
     * checks that the file it names really is owned by that message.
     *
     * @throws AuthorizationException
     * @throws ConversationStateException
     * @throws FileStateException
     */
    public function post(
        User $actor,
        Conversation $conversation,
        ?string $body = null,
        ?UploadedFile $upload = null,
    ): Message {
        if (! Gate::forUser($actor)->allows('post', $conversation)) {
            throw new AuthorizationException('You are not allowed to post in this discussion.');
        }

        $body = $this->clean($body);

        if ($body === null && $upload === null) {
            throw ConversationStateException::emptyMessage();
        }

        // Refuse an unacceptable upload BEFORE writing the message row. Without this the
        // transaction would roll the message back anyway, but the caller would have burnt an id
        // and — more to the point — FileService would have written bytes to disk inside a
        // transaction that then disappeared, leaving an orphan file with no row.
        if ($upload !== null) {
            FileService::assertAcceptable($upload);
        }

        return DB::transaction(function () use ($actor, $conversation, $body, $upload): Message {
            $message = Message::create([
                'conversation_id' => $conversation->getKey(),
                'author_id' => $actor->getKey(),
                'body' => $body,
            ]);

            if ($upload !== null) {
                $file = $this->files->store($actor, $message, $upload);

                // How the file rides on the bubble. `kind` comes off the file's own MIME type
                // through the inline list, so an image renders in place and everything else is
                // a download. `duration_seconds` stays null — voice is Phase 6.
                $message->attachments()->attach($file->getKey(), [
                    'kind' => AttachmentKind::forFile($file)->value,
                    'duration_seconds' => null,
                ]);
            }

            // The task's own timeline gets a line, the way a checklist tick or a link does —
            // so somebody scanning the activity panel sees that the discussion moved without
            // the timeline becoming a second copy of what was said. The BODY is deliberately
            // not in it: activity_logs is a different audience from the discussion, and
            // quoting a comment into it would be publishing it twice with one set of rules.
            $subject = $conversation->subject();

            if ($subject !== null) {
                $this->activity->record($subject, $upload === null
                    ? 'Message posted in the discussion'
                    : 'Message posted in the discussion, with an attachment', $actor);
            }

            // Slice 5. The spec's "comments" are these messages, so TaskCommented is fired
            // here and nowhere else. The `instanceof` is not a placeholder for the other four
            // conversation types: they cannot exist yet (ConversationPolicy denies them
            // outright) and each gets its own event in Phase 6 with its own recipients — a
            // project channel is not a task discussion with a different id.
            //
            // Inside the transaction with the message it is about: a post that rolls back
            // notifies nobody. This is the type §11's dedup example is written about — twelve
            // of these inside the window are one row reading "12 new comments in …".
            if ($subject instanceof Task) {
                event(new TaskCommented($subject, $actor, $message));
            }

            // The author has by definition read their own message.
            $this->markRead($actor, $conversation);

            return $message->load(self::MESSAGE_RELATIONS);
        });
    }

    /**
     * Move this person's unread line to now.
     *
     * The ONLY thing that writes `conversation_members`, and all it writes is a timestamp. A
     * row here is read state, not an access grant: ConversationPolicy never looks at it, so one
     * left behind by somebody who has since been taken off the task grants them nothing.
     *
     * `updateOrCreate` on the pivot rather than `syncWithoutDetaching`, so an existing row keeps
     * its identity and only its timestamp moves.
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
     * Trim, and treat a message of nothing but whitespace as no message at all.
     */
    private function clean(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
