<?php

namespace App\Http\Requests\Audit;

use Illuminate\Foundation\Http\FormRequest;

/**
 * What the audit log may be asked — `GET /admin/audit-log?actor=…&event=…&target_type=…&date_from=…&date_to=…`.
 *
 * Validation lives here because in this repo it lives nowhere else (AGENTS.md). Authorization
 * does not: `can:audit.view` is on the route and `AuditLogPolicy::viewAny` is asked in the
 * controller, and a Form Request that answered `authorize()` as well would be deciding access in
 * two places.
 *
 * ## The event filter does not validate against `AuditEvent`, and that is the point
 *
 * `audit_logs.event` is a **plain indexed string with no CHECK constraint** — verified against
 * the Phase 0 migration, which creates it as `$table->string('event')->index()`. `AuditEvent` is
 * what *writes* the column, not what the column is limited to, so a row inserted by an older
 * build can legitimately carry an event string this build has no case for.
 *
 * A `Rule::enum(AuditEvent::class)` here would therefore answer **422 to a filter for a row that
 * exists** — the reader would be told their question was invalid while the row sat in the table
 * two pixels away, and the one event they most needed to isolate (a renamed one, from the build
 * before the rename) would be the one they could not ask for. So the rule is `string`, the
 * controller compares it to the column, and an event nobody ever recorded simply returns nothing.
 * `AuditLogController::eventOptions()` is what offers the known list; this is what accepts an
 * answer outside it.
 *
 * ## Nothing here checks that an id exists
 *
 * `actor` takes a user id or the literal `system`, and there is no `exists:users,id` on it — for
 * `ReportRequest`'s reason and one of its own. An `exists` rule would answer 422 for an unknown
 * id and 200 for a known one, which is an id oracle; and an actor whose rows have all aged out
 * of a date range is not an error, it is an empty list. The scope answers instead.
 *
 * ## `system` is a value, not a missing one
 *
 * Rows written by a console command or a queue worker have `actor_id = null` — `hq:mark-absent`
 * at 23:55, the overdue sweep, anything the scheduler does. "Which of these did nobody do" is a
 * real question about an audit log, and it cannot be asked by *omitting* the filter, because
 * omitting it means "everybody". So the filter has a third state and its own word.
 */
class AuditLogIndexRequest extends FormRequest
{
    /** The sentinel `actor` value meaning *no signed-in actor* — a command, a job, the scheduler. */
    public const SYSTEM_ACTOR = 'system';

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
            // A positive integer user id, or the sentinel. A regex rather than two rules,
            // because the two spellings are alternatives and `integer|in:system` would accept
            // neither cleanly.
            'actor' => ['nullable', 'string', 'regex:/^(system|[1-9][0-9]{0,18})$/'],
            // See the class docblock: deliberately not an enum rule.
            'event' => ['nullable', 'string', 'max:255'],
            // The morph class as it is stored, which is the FQCN (this application registers no
            // morph map). Bounded by the column's own length, and compared rather than resolved
            // — nothing here instantiates a class named in a query string.
            'target_type' => ['nullable', 'string', 'max:255'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'actor.regex' => 'That is not an actor this log can be filtered by.',
            'date_to.after_or_equal' => 'The end of the range cannot be before its start.',
        ];
    }

    /**
     * The five filters, always all five keys, null where the reader did not narrow.
     *
     * Every key is echoed back to the page from the controller, which is how the screen knows
     * which chips to offer: a chip for a key the server does not read would be a control that
     * narrows nothing (DESIGN.md §5.11). Always-present keys mean that list is stable rather
     * than depending on what this particular request happened to carry.
     *
     * @return array{actor: string|null, event: string|null, target_type: string|null, date_from: string|null, date_to: string|null}
     */
    public function filters(): array
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        return [
            'actor' => $this->trimmed($validated, 'actor'),
            'event' => $this->trimmed($validated, 'event'),
            'target_type' => $this->trimmed($validated, 'target_type'),
            'date_from' => $this->trimmed($validated, 'date_from'),
            'date_to' => $this->trimmed($validated, 'date_to'),
        ];
    }

    /**
     * One validated key as a non-empty string, or null.
     *
     * `FilterBar` removes a chip by writing the parameter as an empty string before it drops it,
     * so `?event=` arrives on a perfectly ordinary Clear and must mean *not narrowed* rather
     * than *narrowed to the empty event*.
     *
     * @param  array<string, mixed>  $validated
     */
    private function trimmed(array $validated, string $key): ?string
    {
        $value = $validated[$key] ?? null;

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
