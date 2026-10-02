<?php

namespace App\Services;

use App\Events\ConversationActivity;
use App\Events\MessagePosted;
use App\Events\TaskCommented;
use App\Exceptions\ConversationStateException;
use App\Exceptions\FileStateException;
use App\Models\Conversation;
use App\Models\File;
use App\Models\Message;
use App\Models\MessageReaction;
use App\Models\Task;
use App\Models\User;
use App\Support\AttachmentKind;
use App\Support\AuditEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Writing a message: the one door, for every conversation type (master prompt Part D §10).
 *
 * ConversationService owns the rooms; this owns what is said in them. A message is posted here
 * or it is not posted — the task discussion endpoints, the Messages page, the project
 * Discussion tab and the announcement composer all arrive at this method, so there is one place
 * where "a message says something", "who may speak here", "who was named" and "who hears about
 * it" are decided.
 *
 * ## Attachments go through FileService
 *
 * There is one writer of bytes in this application and it is not this class. A message
 * attachment is a File owned by the Message, stored by FileService::store(), which brings the
 * size limit, the extension/MIME pairing, the ULID path, the checksum and the signed expiring
 * URL with it (decision 2-25: a signed link is a policy check, not a bearer token). This class
 * adds the `message_attachments` row that says how the file rides on the bubble, and nothing
 * else.
 *
 * ## The seam voice arrives through
 *
 * A voice note is an attachment with `kind = voice` and a `duration_seconds`, and the only two
 * things that are not already here are a recorder in the composer and those two values on the
 * way in. `post()` takes `$duration` for exactly that reason and AttachmentKind decides the
 * rest: when the composer starts sending an audio blob, this method needs no change, and
 * MessageResource already prints a `duration_seconds` the thread already renders an `<audio>`
 * for.
 *
 * ## The seam realtime arrives through
 *
 * Every post fires `MessagePosted` after the transaction that wrote it. A broadcast listener
 * subscribes to that event and to nothing in here; nothing in this class knows that Reverb
 * exists, and a post that rolls back has broadcast nothing because the event is dispatched from
 * inside the transaction that either commits or does not.
 *
 * Validation is in the Form Request. Authorization is in ConversationPolicy and MessagePolicy;
 * this class asks the gate and turns a refusal into an exception, so a non-HTTP caller is
 * refused the same way.
 */
class MessageService
{
    /**
     * The shortest recording that is a message rather than a slip of the thumb.
     *
     * One second. A hold-to-record button releases on a tap, and a zero-second file is a
     * mis-press — there is nothing in it to play.
     */
    public const MIN_VOICE_SECONDS = 1;

    /**
     * The longest recording this application takes: five minutes.
     *
     * A voice note is a message, not a podcast. Something worth five minutes of talking is worth
     * a file in the Files panel, which has the 25 MB limit and the version history a recording
     * of that length actually needs. The number is a product rule and not a transport one —
     * MAX_BYTES still applies underneath it, and a five-minute Opus recording is nowhere near it.
     */
    public const MAX_VOICE_SECONDS = 300;

    /**
     * The most distinct emoji one person may put on one message (12-79).
     */
    public const MAX_REACTIONS_PER_USER = 8;

    public function __construct(
        private readonly FileService $files,
        private readonly ConversationService $conversations,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Rewrite a message's text (12-79). Authorization is MessagePolicy::update, asked by the
     * caller; this method keeps the record: the old body goes to the audit log, the mentions are
     * re-derived from the new text the way `post()` filters them, and nobody is notified.
     */
    public function edit(User $actor, Message $message, string $body): Message
    {
        // Trimmed; UpdateMessageRequest holds it to StoreMessageRequest::MAX_BODY.
        $body = trim($body);

        return DB::transaction(function () use ($actor, $message, $body): Message {
            $old = $message->body;
            $conversation = $message->conversation;

            $message->forceFill([
                'body' => $body,
                'edited_at' => now(),
            ])->save();

            // Same filters as post(): readable by the conversation, named in the body, not the
            // actor. The edit carries no picker ids, so every reader of the room is a candidate
            // and the text decides. No notification is written for a mention an edit adds.
            $candidates = array_map('intval', $this->conversations->mentionableIn($conversation)->modelKeys());
            $mentioned = $this->resolveMentions($conversation, $actor, $body, $candidates);
            $message->mentions()->sync($mentioned->modelKeys());

            $this->audit->record(
                AuditEvent::MessageEdited,
                $message,
                ['body' => $old],
                ['body' => $body],
                $actor,
            );

            ConversationActivity::dispatch((int) $conversation->getKey(), (int) $message->getKey(), 'edited');

            return $message;
        });
    }

    /**
     * Delete a message for everyone (12-79). The row stays — the thread keeps its shape — but
     * its body, files, mentions and reactions go; the original lives only in the audit row.
     * Authorization is MessagePolicy::delete, asked by the caller.
     */
    public function delete(User $actor, Message $message): Message
    {
        return DB::transaction(function () use ($actor, $message): Message {
            $files = $message->files()->get();

            $this->audit->record(
                AuditEvent::MessageDeleted,
                $message,
                [
                    'body' => $message->body,
                    'attachments' => array_map('intval', $files->modelKeys()),
                ],
                ['deleted_by' => (int) $actor->getKey()],
                $actor,
            );

            // FileService::delete audits each file and frees its bytes.
            $files->each(fn (File $file) => $this->files->delete($actor, $file));

            $message->mentions()->detach();
            $message->reactions()->delete();

            $message->forceFill([
                'body' => null,
                'deleted_at' => now(),
                'deleted_by' => $actor->getKey(),
            ])->save();

            ConversationActivity::dispatch((int) $message->conversation_id, (int) $message->getKey(), 'deleted');

            return $message;
        });
    }

    /**
     * Add the actor's emoji to the message, or take it off if it is already there (12-79).
     * Authorization is MessagePolicy::react, asked by the caller. No notification, no audit.
     *
     * @throws ValidationException
     */
    public function toggleReaction(User $actor, Message $message, string $emoji): Message
    {
        DB::transaction(function () use ($actor, $message, $emoji): void {
            $mine = MessageReaction::query()
                ->where('message_id', $message->getKey())
                ->where('user_id', $actor->getKey())
                ->lockForUpdate()
                ->get();

            $existing = $mine->firstWhere('emoji', $emoji);

            if ($existing !== null) {
                $existing->delete();
            } else {
                if ($mine->pluck('emoji')->unique()->count() >= self::MAX_REACTIONS_PER_USER) {
                    throw ValidationException::withMessages([
                        'emoji' => 'You can put at most '.self::MAX_REACTIONS_PER_USER.' reactions on one message.',
                    ]);
                }

                MessageReaction::create([
                    'message_id' => $message->getKey(),
                    'user_id' => $actor->getKey(),
                    'emoji' => $emoji,
                ]);
            }

            ConversationActivity::dispatch((int) $message->conversation_id, (int) $message->getKey(), 'reaction');
        });

        return $message;
    }

    /**
     * Post a message, optionally with a file on it and optionally naming people.
     *
     * The order inside the transaction is forced by the schema and is the right order anyway:
     * the message row has to exist before a file can be owned by it (`files.message_id` is a
     * foreign key), the attachment row has to come last because its composite foreign key checks
     * that the file it names really is owned by that message, and the mentions have to be
     * written before the events so a listener reading them sees all of them.
     *
     * @param  list<int>  $mentionIds  user ids the composer named; every one is re-checked
     * @param  AttachmentKind|null  $kind  how the file rides on the bubble; null derives it from
     *                                     the file's own type, which is every case but a voice
     *                                     note — see AttachmentKind
     * @param  int|null  $duration  a voice note's length in seconds, and null for anything else.
     *                              It is the CLIENT'S measurement and therefore a claim: over
     *                              HTTP StoreMessageRequest refuses one outside
     *                              MIN_VOICE_SECONDS…MAX_VOICE_SECONDS with a 422, and
     *                              `voiceSeconds()` below clamps whatever survives, so no caller
     *                              — job, command or test — can write a row that says a bubble
     *                              is nine thousand seconds long
     * @param  int|null  $replyToId  the message this one replies to (12-82): it must exist, be in
     *                               THIS conversation and not be deleted, or a ValidationException
     *                               on `reply_to_id`
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
        array $mentionIds = [],
        ?AttachmentKind $kind = null,
        ?int $duration = null,
        ?int $replyToId = null,
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
        // The kind decides WHICH allow-list is applied: a recording is measured against
        // FileService::VOICE_TYPES and an ordinary attachment against FileService::TYPES, which
        // has no audio in it and gains none. See VOICE_TYPES for why that is two lists.
        if ($upload !== null) {
            FileService::assertAcceptable($upload, $kind, forMessage: true);
        }

        if ($replyToId !== null) {
            $this->assertRepliable($conversation, $replyToId);
        }

        $mentioned = $this->resolveMentions($conversation, $actor, $body, $mentionIds);

        return DB::transaction(function () use (
            $actor,
            $conversation,
            $body,
            $upload,
            $mentioned,
            $kind,
            $duration,
            $replyToId,
        ): Message {
            $message = Message::create([
                'conversation_id' => $conversation->getKey(),
                'author_id' => $actor->getKey(),
                'body' => $body,
                'reply_to_id' => $replyToId,
            ]);

            if ($upload !== null) {
                $file = $this->files->store($actor, $message, $upload, $kind);

                // How the file rides on the bubble. `kind` comes off the file's own MIME type
                // through the inline list, so an image renders in place, an audio recording
                // becomes a voice note, and everything else is a download. `duration_seconds`
                // is null for everything but a voice note, which is the one kind whose length
                // the bubble has to print before it is played.
                $message->attachments()->attach($file->getKey(), [
                    'kind' => ($kind ?? AttachmentKind::forFile($file))->value,
                    'duration_seconds' => self::voiceSeconds($kind, $duration),
                ]);
            }

            if ($mentioned->isNotEmpty()) {
                $message->mentions()->attach($mentioned->modelKeys());
            }

            $this->conversations->recordActivity($conversation, $actor, $upload !== null);

            $mentionedIds = array_map('intval', $mentioned->modelKeys());

            // The task discussion's own event, unchanged since Phase 2 in everything but the
            // last argument — which is what stops somebody who was BOTH named in a comment and
            // an assignee of its task getting two rows about one sentence. See the event.
            $subject = $conversation->subject();

            if ($subject instanceof Task) {
                event(new TaskCommented($subject, $actor, $message, $mentionedIds));
            }

            // Every type, every time: the mentions, the DM, the announcement, and the seam a
            // broadcast listener hangs off. Inside the transaction with the message it is
            // about, so a post that rolls back notifies nobody.
            event(new MessagePosted($conversation, $message, $actor, $mentionedIds));

            // The author has by definition read their own message.
            $this->conversations->markRead($actor, $conversation);

            return $message->load(ConversationService::MESSAGE_RELATIONS);
        });
    }

    /**
     * A reply must answer a live message in the same conversation (12-82). One error for every
     * way it can fail, so the answer never tells a caller which ids exist elsewhere.
     *
     * @throws ValidationException
     */
    private function assertRepliable(Conversation $conversation, int $replyToId): void
    {
        $ok = Message::query()
            ->whereKey($replyToId)
            ->where('conversation_id', $conversation->getKey())
            ->whereNull('deleted_at')
            ->exists();

        if (! $ok) {
            throw ValidationException::withMessages([
                'reply_to_id' => 'You can only reply to a message in this conversation.',
            ]);
        }
    }

    /**
     * What `duration_seconds` is allowed to say.
     *
     * Two rules, and they are different rules:
     *
     *   - **Null for anything that is not a voice note.** A duration on a PDF is meaningless, and
     *     a column that is sometimes meaningless is a column nobody can read a query against.
     *   - **Clamped for a voice note.** The number came off a `MediaRecorder` in somebody's
     *     browser, which means it came off a client and is a claim, not a measurement this server
     *     made. Over HTTP a claim outside the range is a 422 and never reaches here — but the
     *     rule a stored row has to satisfy cannot live only in a Form Request, because a job, a
     *     console command or an importer never meets one. So the last thing before the INSERT
     *     puts the number inside the range it is allowed to be in.
     *
     * Clamping rather than throwing, and only here: the refusal a person should SEE belongs at
     * the boundary they typed at, with copy against the field. This is the backstop, and a
     * backstop that throws turns a bad number in a batch job into a failed batch job.
     */
    private static function voiceSeconds(?AttachmentKind $kind, ?int $duration): ?int
    {
        if ($kind !== AttachmentKind::Voice || $duration === null) {
            return null;
        }

        return max(self::MIN_VOICE_SECONDS, min(self::MAX_VOICE_SECONDS, $duration));
    }

    /**
     * Which of the named people actually get a mention row.
     *
     * Two filters, and both matter:
     *
     *   1. **They can already read this conversation.** A mention is not a grant — nothing in
     *      ConversationPolicy reads `message_mentions` — so a row for somebody who cannot see
     *      the thread would be a record that can never be acted on, and an unenforceable row is
     *      one that will eventually be treated as a permission by somebody. The set is exactly
     *      `ConversationService::mentionableIn()`, which is the same set the picker was built
     *      from, so a name the composer offered is never refused here.
     *   2. **The body actually names them.** `message_mentions` is the record that a person was
     *      ADDRESSED, and it has to agree with what every reader of the thread can see. The
     *      picker inserts `@Name`, so this passes by construction; a hand-built request that
     *      sends ids without the text silently gets no rows, rather than notifying somebody
     *      about a line that never mentioned them.
     *
     * The actor is dropped: naming yourself is not a request for your own attention, and
     * NotificationService would drop the row anyway.
     *
     * @param  list<int>  $mentionIds
     * @return Collection<int, User>
     */
    private function resolveMentions(
        Conversation $conversation,
        User $actor,
        ?string $body,
        array $mentionIds,
    ): Collection {
        $wanted = array_values(array_unique(array_filter(array_map('intval', $mentionIds))));

        if ($wanted === [] || $body === null) {
            return new Collection;
        }

        return $this->conversations->mentionableIn($conversation)
            ->filter(fn (User $user): bool => in_array((int) $user->getKey(), $wanted, true))
            ->reject(fn (User $user): bool => (int) $user->getKey() === (int) $actor->getKey())
            ->filter(fn (User $user): bool => self::namedIn($body, (string) $user->name))
            ->values();
    }

    /**
     * Does this body contain an `@` followed by this person's name?
     *
     * Matched on the FIRST word of the name as well as the whole of it, because `@Tapu` is what
     * somebody types and "Tapu Ahmed" is what the directory holds. Case-insensitive, and
     * anchored on the `@` so the word appearing in ordinary prose is not a mention — *"ask Tapu
     * about it"* names nobody, which is the distinction the table exists to record.
     */
    private static function namedIn(string $body, string $name): bool
    {
        $name = trim($name);

        if ($name === '') {
            return false;
        }

        $first = (string) (preg_split('/\s+/u', $name)[0] ?? '');

        foreach (array_unique([$name, $first]) as $candidate) {
            if ($candidate === '') {
                continue;
            }

            if (preg_match('/@'.preg_quote($candidate, '/').'/iu', $body) === 1) {
                return true;
            }
        }

        return false;
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
