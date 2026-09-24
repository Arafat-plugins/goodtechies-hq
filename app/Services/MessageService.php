<?php

namespace App\Services;

use App\Events\MessagePosted;
use App\Events\TaskCommented;
use App\Exceptions\ConversationStateException;
use App\Exceptions\FileStateException;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Task;
use App\Models\User;
use App\Support\AttachmentKind;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

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
    public function __construct(
        private readonly FileService $files,
        private readonly ConversationService $conversations,
    ) {}

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
     * @param  int|null  $duration  a voice note's length in seconds, and null for anything else
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

        $mentioned = $this->resolveMentions($conversation, $actor, $body, $mentionIds);

        return DB::transaction(function () use (
            $actor,
            $conversation,
            $body,
            $upload,
            $mentioned,
            $kind,
            $duration,
        ): Message {
            $message = Message::create([
                'conversation_id' => $conversation->getKey(),
                'author_id' => $actor->getKey(),
                'body' => $body,
            ]);

            if ($upload !== null) {
                $file = $this->files->store($actor, $message, $upload);

                // How the file rides on the bubble. `kind` comes off the file's own MIME type
                // through the inline list, so an image renders in place, an audio recording
                // becomes a voice note, and everything else is a download. `duration_seconds`
                // is null for everything but a voice note, which is the one kind whose length
                // the bubble has to print before it is played.
                $message->attachments()->attach($file->getKey(), [
                    'kind' => ($kind ?? AttachmentKind::forFile($file))->value,
                    'duration_seconds' => $kind === AttachmentKind::Voice ? $duration : null,
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
