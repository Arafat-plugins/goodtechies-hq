<?php

namespace App\Http\Requests\Conversation;

use App\Exceptions\FileStateException;
use App\Services\FileService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

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
 * Authorization is NOT here. ConversationPolicy answers it and ConversationService asks.
 */
class StoreMessageRequest extends FormRequest
{
    /**
     * The longest a single message may be.
     *
     * Four thousand characters. Long enough for a full brief pasted into a task; short enough
     * that a message is still a message and not a document, which is what the Files panel and
     * the task description are for.
     */
    public const MAX_BODY = 4000;

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
                'bail',
                'file',
                'max:'.FileService::maxKilobytes(),
                $this->acceptable(),
            ],

            // Who the composer's @mention picker named. Ids only, and validated no further
            // here on purpose: `exists:users,id` would be a second, weaker statement of a rule
            // MessageService already applies properly — it keeps only the ids that can read
            // this conversation AND are named in the body, and silently drops the rest. A 422
            // for an id that does not exist would also tell a caller which ids do, which is a
            // question no endpoint in this application answers.
            'mentions' => ['sometimes', 'array', 'max:'.self::MAX_MENTIONS],
            'mentions.*' => ['integer'],
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
            'file.max' => 'That file is too large. The limit is '
                .round(FileService::MAX_BYTES / 1048576).' MB.',
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

    public function upload(): ?UploadedFile
    {
        $upload = $this->file('file');

        return $upload instanceof UploadedFile ? $upload : null;
    }

    /**
     * FileService's own "will this be stored at all" rule, reported against the field — the
     * same closure StoreFileRequest uses, because it is the same question.
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

            try {
                FileService::assertAcceptable($value);
            } catch (FileStateException $exception) {
                $fail($exception->getMessage());
            }
        };
    }
}
