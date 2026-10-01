<?php

namespace App\Http\Requests\Messages;

use App\Http\Requests\Conversation\StoreMessageRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Editing a message's text (12-79). Same length rule as posting. Authorization is
 * MessagePolicy::update, asked by the controller.
 */
class UpdateMessageRequest extends FormRequest
{
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
            'body' => ['required', 'string', 'max:'.StoreMessageRequest::MAX_BODY],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'body.required' => 'A message needs some text. Delete it instead.',
            'body.max' => 'That message is too long. The limit is '.StoreMessageRequest::MAX_BODY.' characters.',
        ];
    }

    public function body(): string
    {
        return trim((string) $this->validated('body'));
    }
}
