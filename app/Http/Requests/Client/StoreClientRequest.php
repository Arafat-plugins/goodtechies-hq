<?php

namespace App\Http\Requests\Client;

use App\Support\ClientStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Authorization is the ClientPolicy's job and is called in the controller, so this only
 * has to describe what a valid client looks like.
 */
class StoreClientRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'status' => ['required', Rule::enum(ClientStatus::class)],
            'internal_notes' => ['nullable', 'string', 'max:5000'],
            'contacts' => ['nullable', 'array', 'max:10'],
            'contacts.*.name' => ['required_with:contacts', 'string', 'max:120'],
            'contacts.*.role' => ['nullable', 'string', 'max:120'],
            'contacts.*.email' => ['nullable', 'email', 'max:255'],
            'contacts.*.phone' => ['nullable', 'string', 'max:40'],
        ];
    }
}
