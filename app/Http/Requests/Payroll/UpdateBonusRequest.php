<?php

namespace App\Http\Requests\Payroll;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Editing one bonus: the same fields as creating one, without the employees or the status.
 */
class UpdateBonusRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return StoreBonusRequest::bonusRules();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return StoreBonusRequest::bonusMessages();
    }
}
