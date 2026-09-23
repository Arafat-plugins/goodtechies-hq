<?php

namespace App\Http\Requests\Tag;

use App\Models\Tag;
use App\Support\TagColour;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Renaming or recolouring a tag.
 *
 * `project_id` is `prohibited`, which is the same move `UpdateTaskRequest` makes with `status`
 * and for the same reason: a field the write refuses is refused at the edge with a message,
 * not dropped silently further in. Moving a tag between scopes is not a rename — it would take
 * the label off every task that can no longer use it — so there is no endpoint for it at all.
 * See TagService::update().
 */
class UpdateTagRequest extends FormRequest
{
    /**
     * Refuse a tag this requester may not see — here, ahead of the rules.
     *
     * `rules()` below builds its uniqueness scope from *this* tag's `project_id`, and a Form
     * Request validates before the controller body runs. So without this check, a PUT naming a
     * tag scoped to a project the requester cannot see answers **422** — "A tag with that name
     * already exists in this scope" — instead of the 404 `ManagesTags::visibleTag()` would
     * give it a moment later. That 422 is an existence oracle: it confirms both a tag id and a
     * tag name inside another client's work. Part C says a record they may not see is ABSENT,
     * and absent has to mean absent at the first thing that answers, not the second.
     *
     * 404 rather than `return false` (403) for the reason `visibleTag()` spells out: the name
     * of a tag names that client's work, so "you may not" already says too much.
     */
    public function authorize(): bool
    {
        abort_unless(
            Tag::query()
                ->visibleTo($this->user())
                ->whereKey($this->tag()->getKey())
                ->exists(),
            404,
        );

        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Both optional: a recolour sends no name, a rename sends no colour.
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:'.Tag::MAX_NAME,
                Rule::unique('tags', 'name')
                    ->where(fn ($query) => $query->where('project_id', $this->tag()->project_id))
                    ->ignore($this->tag()->getKey()),
            ],
            'colour' => ['sometimes', 'required', Rule::enum(TagColour::class)],
            'project_id' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => 'A tag with that name already exists in this scope.',
            'project_id.prohibited' => 'A tag cannot be moved between projects. Delete it and create it where it belongs.',
        ];
    }

    /**
     * The changes, with the name trimmed. Only the keys that were actually sent.
     *
     * @return array<string, mixed>
     */
    public function changes(): array
    {
        $changes = array_intersect_key($this->validated(), array_flip(['name', 'colour']));

        if (array_key_exists('name', $changes)) {
            $changes['name'] = trim((string) $changes['name']);
        }

        if (array_key_exists('colour', $changes)) {
            $changes['colour'] = TagColour::from((string) $changes['colour']);
        }

        return $changes;
    }

    private function tag(): Tag
    {
        /** @var Tag $tag */
        $tag = $this->route('tag');

        return $tag;
    }
}
