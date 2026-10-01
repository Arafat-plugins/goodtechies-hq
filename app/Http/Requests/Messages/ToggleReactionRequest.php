<?php

namespace App\Http\Requests\Messages;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Toggling one emoji reaction on a message (12-79). Only emoji characters (with their joiners,
 * variation selectors, skin-tone modifiers and keycaps) pass. Authorization is
 * MessagePolicy::react, asked by the controller.
 */
class ToggleReactionRequest extends FormRequest
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
            'emoji' => [
                'required',
                'string',
                'max:32',
                'regex:/^(?:\p{Extended_Pictographic}|\p{Emoji_Modifier}|\p{Regional_Indicator}|\x{200D}|\x{FE0F}|\x{20E3}|[#*0-9])+$/u',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'emoji.regex' => 'That is not an emoji.',
        ];
    }

    public function emoji(): string
    {
        return (string) $this->validated('emoji');
    }
}
