<?php

namespace App\Http\Requests\Tag;

use App\Models\Project;
use App\Models\Tag;
use App\Support\TagColour;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A new tag: a name, a colour, and either a project or nothing.
 *
 * Authorization is NOT here — TagPolicy answers it, and TagService asks. This request decides
 * only whether the input is something the application could store at all.
 *
 * Two of the three rules are the database's own constraints, stated here so a refusal is a
 * sentence under a field instead of a 500 from PostgreSQL: `tags_colour_is_a_status_token` and
 * the pair of unique indexes over (project_id, name). The database is still the enforcement —
 * see App\Support\TagColour's docblock on why the rule is written three times.
 */
class StoreTagRequest extends FormRequest
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
            'name' => [
                'required',
                'string',
                'max:'.Tag::MAX_NAME,
                // Scoped to the tag's own scope, which is what both unique indexes say: one
                // `SEO` per project, and one global `SEO`. `where('project_id', null)` becomes
                // `is null`, which is the half the composite index cannot express.
                Rule::unique('tags', 'name')->where(
                    fn ($query) => $query->where('project_id', $this->projectId()),
                ),
            ],
            // Never a hex, never a free value: the name of one of the eight status tones.
            'colour' => ['required', Rule::enum(TagColour::class)],
            // Absent or null means a GLOBAL tag, usable on every project. That is the common
            // case — the agency does the same four kinds of work whoever the client is.
            'project_id' => ['nullable', 'integer', $this->visibleProject()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Give the tag a name.',
            'name.unique' => 'A tag with that name already exists in this scope.',
            'colour.required' => 'Choose a colour.',
        ];
    }

    public function name(): string
    {
        return trim((string) $this->validated('name'));
    }

    public function colour(): TagColour
    {
        return TagColour::from((string) $this->validated('colour'));
    }

    /**
     * The project this tag is scoped to, or null for a global one.
     */
    public function project(): ?Project
    {
        $id = $this->projectId();

        return $id === null ? null : Project::query()->findOrFail($id);
    }

    private function projectId(): ?int
    {
        $value = $this->input('project_id');

        return $value === null || $value === '' ? null : (int) $value;
    }

    /**
     * A project id the requester cannot see fails as "no such project", not as "forbidden".
     *
     * A 403 here would confirm that a project with that id exists, which is precisely what
     * Part C's rule about invisible records forbids. A validation failure says the same thing
     * an unknown id says, which is the whole point.
     */
    private function visibleProject(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if ($value === null || $value === '') {
                return;
            }

            $exists = Project::query()
                ->visibleTo($this->user())
                ->whereKey((int) $value)
                ->exists();

            if (! $exists) {
                $fail('That project does not exist.');
            }
        };
    }
}
