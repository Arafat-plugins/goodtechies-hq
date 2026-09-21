<?php

namespace App\Http\Requests\Task;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A reference link on a task.
 *
 * Only http and https are accepted. A `javascript:` or `data:` URL rendered into an anchor on
 * the detail page is a cross-site-scripting hole with extra steps, and the validator is the
 * place that refuses it — not a directive in a Vue file that does not exist yet.
 */
class StoreTaskLinkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $url = $this->input('url');

        if (is_string($url)) {
            $this->merge(['url' => trim($url)]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'url' => ['required', 'string', 'max:2048', 'url:http,https'],
            'label' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function url(): string
    {
        return (string) $this->validated('url');
    }

    public function label(): ?string
    {
        $label = $this->validated('label');

        return is_string($label) && trim($label) !== '' ? trim($label) : null;
    }
}
