<?php

namespace App\Http\Requests\Project;

use App\Support\Permission;
use App\Support\ProjectType;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Polish 026: the Admin renames the project types (SEO, Website Maintenance, …).
 *
 * `labels` is keyed by `ProjectType` value; a blank name puts the built-in one back. Only the
 * eight known types are accepted, so a type cannot be invented here — the list itself is still
 * the enum, only the words on it change.
 */
class UpdateProjectTypeLabelsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission(Permission::SettingsManage);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = ['labels' => ['required', 'array:'.implode(',', array_column(ProjectType::cases(), 'value'))]];

        foreach (ProjectType::cases() as $type) {
            $rules['labels.'.$type->value] = ['nullable', 'string', 'max:60'];
        }

        return $rules;
    }

    /**
     * The names to store: trimmed, and only those that differ from the built-in name.
     *
     * @return array<string, string>
     */
    public function labels(): array
    {
        $sent = (array) $this->validated('labels');
        $labels = [];

        foreach (ProjectType::cases() as $type) {
            $name = trim((string) ($sent[$type->value] ?? ''));

            if ($name !== '' && $name !== $type->defaultLabel()) {
                $labels[$type->value] = $name;
            }
        }

        return $labels;
    }
}
