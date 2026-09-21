<?php

namespace App\Http\Requests\Project;

use App\Support\BillingType;
use App\Support\Priority;
use App\Support\ProjectType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creating a project. Members and the finance row may arrive in the same request; the services
 * decide whether the actor is allowed to write either of them.
 *
 * A new project is always Active, so `status` is not accepted here — it is an update field.
 */
class StoreProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Domains are stored bare and lower-cased (`example.com`), so the same site typed as
     * `https://Example.com/` matches the one already on file.
     */
    protected function prepareForValidation(): void
    {
        $domain = $this->input('domain');

        if (! is_string($domain)) {
            return;
        }

        $domain = rtrim(preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', trim($domain)) ?? '', '/');

        $this->merge(['domain' => $domain === '' ? null : mb_strtolower($domain)]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...$this->projectRules(),
            'members' => ['nullable', 'array'],
            'members.*' => ['integer', 'exists:employees,id'],
            'finance' => ['nullable', 'array'],
            ...UpdateProjectFinanceRequest::financeRules('finance.'),
        ];
    }

    /**
     * The project's own fields, shared with UpdateProjectRequest.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    protected function projectRules(): array
    {
        return [
            'client_id' => ['nullable', 'exists:clients,id'],
            'name' => ['required', 'string', 'max:255'],
            'domain' => ['nullable', 'string', 'max:255'],
            'project_type' => ['required', Rule::enum(ProjectType::class)],
            'billing_type' => ['required', Rule::enum(BillingType::class)],
            'priority' => ['required', Rule::enum(Priority::class)],
            'start_date' => ['nullable', 'date'],
            'deadline' => ['nullable', 'date', 'after_or_equal:start_date'],
            'pm_id' => ['nullable', 'exists:employees,id'],
            'internal_notes' => ['nullable', 'string', 'max:5000'],
            'employee_notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
