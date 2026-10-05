<?php

namespace App\Http\Requests\Extension;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Trading a pairing code for a token (docs/extension-api.md §2). Nobody is signed in yet, so
 * there is nothing to authorize here: the code itself is the credential, and who it belongs to
 * is decided by `ExtensionPairingService::exchange()`.
 */
class ExchangeCodeRequest extends FormRequest
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
            'code' => ['required', 'string', 'size:8', 'alpha_num'],
            'device_name' => ['required', 'string', 'max:120'],
            'install_uuid' => ['required', 'uuid'],
        ];
    }
}
