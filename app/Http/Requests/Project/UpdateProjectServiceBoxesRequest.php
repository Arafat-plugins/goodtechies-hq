<?php

namespace App\Http\Requests\Project;

use App\Support\Permission;
use App\Support\ProjectServiceBoxes;
use App\Support\ProjectType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Polish 033: the Admin edits the service boxes on Admin → Projects — renames one, adds one,
 * removes one, chooses which project types each gathers. A type sits in one box at most; a type
 * in none goes to the automatic "Other" box.
 */
class UpdateProjectServiceBoxesRequest extends FormRequest
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
        return [
            'boxes' => ['required', 'array', 'min:1', 'max:'.ProjectServiceBoxes::MAX_BOXES],
            'boxes.*' => ['array:name,types'],
            'boxes.*.name' => ['required', 'string', 'max:40'],
            'boxes.*.types' => ['present', 'array'],
            'boxes.*.types.*' => ['string', 'in:'.implode(',', array_column(ProjectType::cases(), 'value'))],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'boxes.*.name.required' => 'Every box needs a name.',
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $seen = [];
                $names = [];

                foreach ((array) $this->input('boxes', []) as $index => $box) {
                    $name = mb_strtolower(trim((string) ($box['name'] ?? '')));

                    if ($name !== '' && in_array($name, $names, true)) {
                        $validator->errors()->add("boxes.$index.name", 'Two boxes cannot have the same name.');
                    }

                    $names[] = $name;

                    foreach ((array) ($box['types'] ?? []) as $type) {
                        if (in_array($type, $seen, true)) {
                            $validator->errors()->add("boxes.$index.types", 'A project type can be in one box only.');
                        }

                        $seen[] = $type;
                    }
                }
            },
        ];
    }

    /**
     * @return list<array{name: string, types: list<string>}>
     */
    public function boxes(): array
    {
        return ProjectServiceBoxes::clean((array) $this->validated('boxes'));
    }
}
