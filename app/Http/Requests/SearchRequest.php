<?php

namespace App\Http\Requests;

use App\Services\SearchService;
use App\Support\SearchableType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `GET /search?q=…[&type=…]`.
 *
 * Validation lives in a Form Request because in this repo it lives nowhere else (AGENTS.md),
 * and there is exactly one rule worth stating — which is itself the interesting part.
 *
 * ## There is no minimum length here, on purpose
 *
 * A term shorter than `SearchService::MINIMUM` is **200 with nothing in it**, not 422, and so
 * is an absent `q`. Decision M-4 settled this for message search and the reason is sharper for
 * a command palette, which sends a request on every keystroke: a `min:2` rule here would make
 * the box flash a validation error on the first letter of every search anybody ever ran. A
 * half-typed term is not a malformed request. The service answers it with an empty result set
 * and the palette renders "no matches", which is both true and quiet.
 *
 * ## A hundred characters is not somebody typing
 *
 * The upper bound IS a 422, also per M-4. Past a hundred characters the request did not come
 * from the palette, and answering it would mean tokenising an arbitrarily long string into an
 * arbitrarily large `tsquery` on every one of eight indexes. That is a malformed request and
 * saying so is the honest answer.
 *
 * Authorization is deliberately absent — `authorize()` returns true — because **every
 * signed-in role may search**. What differs between them is what they find, not whether they
 * may look, and that difference is `SearchService`'s scoping rather than a gate on the route.
 * A `can:` here would have to name a capability that does not exist.
 *
 * ## `type` narrows, it never widens (brief 010)
 *
 * The palette searches only the section the person is in — tasks on Tasks, projects on
 * Projects, everything on a Dashboard — and says so with an optional `type`: one
 * `SearchableType` value, several as `type[]=…`, or several comma-separated (`type=task,file`).
 * Absent or empty means every type, which is exactly the endpoint's behaviour before the
 * parameter existed. A value that is not a `SearchableType` is a malformed request and is a
 * 422, never a 500 and never silently ignored — a typo that quietly searched everything would
 * look like a working scope.
 *
 * It is a filter over WHICH groups run, and nothing else: every group that does run is scoped
 * by `SearchService` exactly as before, so naming a type you hold no key for answers nothing.
 */
class SearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:'.SearchService::MAXIMUM],
            'type' => ['nullable', 'array', 'max:'.count(SearchableType::cases())],
            'type.*' => ['required', 'string', Rule::enum(SearchableType::class)],
        ];
    }

    /**
     * `type=task,file` and `type[]=task&type[]=file` are the same request; an empty `type=` is
     * no `type` at all.
     */
    protected function prepareForValidation(): void
    {
        $type = $this->query('type');

        if (is_string($type)) {
            $type = trim($type) === '' ? null : array_map('trim', explode(',', $type));

            $this->merge(['type' => $type]);
        }
    }

    /**
     * The types to search, or null for every type.
     *
     * @return list<SearchableType>|null
     */
    public function types(): ?array
    {
        $types = $this->validated('type');

        if (! is_array($types) || $types === []) {
            return null;
        }

        return array_values(array_unique(
            array_map(fn (string $type): SearchableType => SearchableType::from($type), $types),
            SORT_REGULAR,
        ));
    }

    /**
     * The term, normalised to a string so the service never has to ask whether it was sent.
     */
    public function term(): string
    {
        return trim((string) $this->query('q', ''));
    }
}
