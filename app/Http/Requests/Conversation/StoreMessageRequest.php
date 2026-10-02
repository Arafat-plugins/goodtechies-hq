<?php

namespace App\Http\Requests\Conversation;

use App\Exceptions\FileStateException;
use App\Services\FileService;
use App\Services\MessageService;
use App\Support\AttachmentKind;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

/**
 * Posting a message: text, a file, or both.
 *
 * The file half is StoreFileRequest's rules, reached the same way — from FileService's own
 * constants, so the validator and the writer cannot come to hold different lists. The one
 * difference is that `file` is optional here and required there: a message with only text is
 * the common case, and a message with only a file is a perfectly ordinary "here you go".
 *
 * `required_without` in both directions is the rule the database cannot hold. A message needs
 * something in it, but half of that answer lives in `message_attachments`, so no CHECK can see
 * it; ConversationService states the same rule at the write, for callers that are not HTTP.
 *
 * ## The voice half (Phase 6)
 *
 * Two extra fields, and the whole of the recorder's contract with the server:
 *
 *   - `kind` — optional, and the only value it may hold is `voice`. ABSENT is the normal case
 *     and means "work it out from the file", which is what every caller before Phase 6 did and
 *     still does. It is not a free choice of the three AttachmentKind cases: `file` and `image`
 *     are DERIVED from the file's own type and letting a client assert one would be letting it
 *     decide whether its upload renders inline.
 *   - `duration` — the recording's length in seconds. **Required when `kind=voice` and
 *     prohibited otherwise**, because it has no meaning anywhere else, and a field that is
 *     silently ignored half the time is a field a client will eventually send wrong.
 *
 * `duration` is a CLAIM — the composer's `MediaRecorder` measured it, not this server — so it is
 * bounded here (`MessageService::MIN_VOICE_SECONDS`…`MAX_VOICE_SECONDS`) and clamped again at
 * the write. Outside the range is a 422 against the field; there is no "nearest sensible value"
 * substituted behind the sender's back.
 *
 * The file itself is checked against `FileService::VOICE_TYPES` rather than `TYPES` when the
 * kind says voice — one narrow list, reached only by a recording, so no other upload panel in
 * the application starts accepting audio. FileService::VOICE_TYPES carries that argument.
 *
 * Authorization is NOT here. ConversationPolicy answers it and ConversationService asks.
 */
class StoreMessageRequest extends FormRequest
{
    /**
     * The longest a single message may be.
     *
     * Twenty thousand characters (12-82). The composer no longer counts characters; this stays
     * only as an abuse guard against a hand-built request posting megabytes of text.
     */
    public const MAX_BODY = 20000;

    /**
     * The most people one message may name.
     *
     * Twenty, which is more than the whole company (Part A: five to fifteen people) — so it is
     * not a rule about how many colleagues you may address, it is a ceiling that stops a
     * hand-built request asking the server to gate-check ten thousand ids.
     */
    public const MAX_MENTIONS = 20;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'body' => ['nullable', 'required_without:file', 'string', 'max:'.self::MAX_BODY],
            'file' => [
                'nullable',
                'required_without:body',
                // A voice note IS its recording. `required_without:body` above would let
                // `kind=voice` through on text alone, which would store a bubble that claims to
                // be a recording and has nothing to play.
                'required_if:kind,'.AttachmentKind::Voice->value,
                'bail',
                'file',
                // No `max:` here: messages carry no application size cap (12-82); the
                // transport limit in nginx/PHP is what bounds them.
                $this->acceptable(),
            ],

            // How the file rides on the bubble, when the sender is the one who knows. `voice` is
            // the only word accepted: see the class docblock for why `file` and `image` are not
            // a client's to assert.
            'kind' => ['nullable', 'string', Rule::in([AttachmentKind::Voice->value])],

            // The recording's length, as the browser measured it. Required exactly when there is
            // a recording, refused otherwise, and bounded — `integer` before `between` so a
            // non-numeric value is reported as the wrong type rather than as out of range.
            'duration' => [
                'prohibited_unless:kind,'.AttachmentKind::Voice->value,
                'required_if:kind,'.AttachmentKind::Voice->value,
                'bail',
                'integer',
                'between:'.MessageService::MIN_VOICE_SECONDS.','.MessageService::MAX_VOICE_SECONDS,
            ],

            // Who the composer's @mention picker named. Ids only, and validated no further
            // here on purpose: `exists:users,id` would be a second, weaker statement of a rule
            // MessageService already applies properly — it keeps only the ids that can read
            // this conversation AND are named in the body, and silently drops the rest. A 422
            // for an id that does not exist would also tell a caller which ids do, which is a
            // question no endpoint in this application answers.
            'mentions' => ['sometimes', 'array', 'max:'.self::MAX_MENTIONS],
            'mentions.*' => ['integer'],

            // The message this one replies to (12-82). Only its shape is checked here: "exists,
            // in this conversation, not deleted" is MessageService::post()'s rule, so a job or a
            // test is refused the same way.
            'reply_to_id' => ['nullable', 'integer'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'body.required_without' => 'Write something, or attach a file.',
            'file.required_without' => 'Write something, or attach a file.',
            'body.max' => 'That message is too long. The limit is '.self::MAX_BODY.' characters.',
            'mentions.max' => 'That is more people than one message can name.',
            'file.required_if' => 'A voice note needs its recording.',
            'kind.in' => 'That is not a kind of attachment this endpoint accepts.',
            'duration.prohibited_unless' => 'A length belongs to a voice note. Leave it off an ordinary attachment.',
            'duration.required_if' => 'A voice note needs its length in seconds.',
            'duration.integer' => 'A voice note\'s length is a whole number of seconds.',
            'duration.between' => 'A voice note is between '
                .MessageService::MIN_VOICE_SECONDS.' and '.MessageService::MAX_VOICE_SECONDS
                .' seconds long. Record something longer as a file instead.',
        ];
    }

    public function body(): ?string
    {
        $body = trim((string) $this->validated('body'));

        return $body === '' ? null : $body;
    }

    /**
     * The ids the picker named, de-duplicated. What they mean is MessageService's business.
     *
     * @return list<int>
     */
    public function mentionIds(): array
    {
        $ids = $this->validated('mentions');

        if (! is_array($ids)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map('intval', $ids))));
    }

    /** The message this one replies to, or null. MessageService checks it belongs here. */
    public function replyToId(): ?int
    {
        $id = $this->validated('reply_to_id');

        return is_numeric($id) ? (int) $id : null;
    }

    public function upload(): ?UploadedFile
    {
        $upload = $this->file('file');

        return $upload instanceof UploadedFile ? $upload : null;
    }

    /**
     * How the sender says the file rides on the bubble, or null to let the file decide.
     *
     * Read from `validated()`, so the only value that can ever come back is `voice` — anything
     * else was a 422 and never reached here.
     */
    public function attachmentKind(): ?AttachmentKind
    {
        $kind = $this->validated('kind');

        return is_string($kind) ? AttachmentKind::tryFrom($kind) : null;
    }

    /**
     * The recording's length in seconds, and null when there is no recording.
     *
     * Guarded on the kind as well as on the value: `prohibited_unless` already refuses a
     * duration without `kind=voice`, and this makes it impossible for a future loosening of that
     * rule to start writing a length onto a spreadsheet.
     */
    public function duration(): ?int
    {
        if ($this->attachmentKind() !== AttachmentKind::Voice) {
            return null;
        }

        $duration = $this->validated('duration');

        return is_numeric($duration) ? (int) $duration : null;
    }

    /**
     * FileService's own "will this be stored at all" rule, reported against the field — the
     * same closure StoreFileRequest uses, because it is the same question.
     *
     * It asks with the kind, so a recording is measured against the voice list and everything
     * else against the ordinary one. The kind is read from the RAW input rather than from
     * `validated()`, because this closure runs during validation and `kind`'s own rule may not
     * have been reached yet; a bogus kind fails its own rule anyway, and reading a bogus one
     * here can only send the file down the ordinary, narrower-for-audio path.
     */
    private function acceptable(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if ($value === null) {
                return;
            }

            if (! $value instanceof UploadedFile) {
                $fail('That upload did not arrive intact. Try again.');

                return;
            }

            $claimed = $this->input('kind');

            try {
                FileService::assertAcceptable(
                    $value,
                    is_string($claimed) ? AttachmentKind::tryFrom($claimed) : null,
                    forMessage: true,
                );
            } catch (FileStateException $exception) {
                $fail($exception->getMessage());
            }
        };
    }
}
