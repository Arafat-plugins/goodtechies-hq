<?php

namespace App\Http\Requests\Reports;

use App\Support\ReportFilter;
use App\Support\ReportFilters;
use App\Support\ReportKey;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

/**
 * What a report is asked — `GET /admin/reports/{report}?from=…&to=…&employee=…` (report
 * contract §2).
 *
 * One Form Request for all sixteen reports, because the five keys below are the whole
 * vocabulary and a second request class would be a second place the date format is decided.
 * Validation lives here because in this repo it lives nowhere else (AGENTS.md).
 *
 * ## Four rules, and the fourth is the one that matters
 *
 *   - `from` and `to` are `Y-m-d`, and `to` is not before `from`. Both are optional: the
 *     default window is the current calendar month, applied by `ReportFilters`.
 *   - `employee`, `project` and `client` are integers.
 *   - Anything not on that list is **ignored**, never passed through. `filters()` reads the
 *     validated array and `ReportFilters::for()` drops even the validated keys the report does
 *     not accept, so a builder cannot read a filter its own catalogue entry does not offer.
 *   - **An id the viewer may not see is not an error.** There is no `exists:` rule on any of
 *     the three, and that is deliberate rather than lax: `exists:projects,id` would answer 422
 *     for an id that does not exist and 200 for one that does, which tells an employee exactly
 *     which project ids are real. The scope is what answers instead — the report comes back
 *     empty and says nothing about whether the row exists (Part C §1).
 *
 * ## Authorization is not here
 *
 * `authorize()` returns true, and the permission is asked in the controller against
 * `ReportKey::permission()` — because which permission applies depends on which report was
 * asked for, and a Form Request that resolved the route parameter to answer `authorize()`
 * would be deciding access in two places. The surface middleware has already refused every
 * other shell before any of this runs.
 */
class ReportRequest extends FormRequest
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
            ReportFilter::FROM => ['nullable', 'date_format:Y-m-d'],
            ReportFilter::TO => ['nullable', 'date_format:Y-m-d', 'after_or_equal:'.ReportFilter::FROM],
            ReportFilter::Employee->key() => ['nullable', 'integer', 'min:1'],
            ReportFilter::Project->key() => ['nullable', 'integer', 'min:1'],
            ReportFilter::Client->key() => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ReportFilter::TO.'.after_or_equal' => 'The end of the range cannot be before its start.',
        ];
    }

    /**
     * The validated input as the value object every builder reads.
     *
     * `asOf` is a parameter rather than a `today()` buried in a query, so a report is testable
     * at a fixed date — the same reason `TaskService::filters()` takes one.
     */
    public function filters(ReportKey $key, ?Carbon $asOf = null): ReportFilters
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        return ReportFilters::for($key, $validated, $asOf);
    }
}
