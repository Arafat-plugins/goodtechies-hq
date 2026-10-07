<?php

namespace App\Http\Resources;

use App\Models\AuditLog;
use App\Support\AuditEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One audit row as the Audit Log viewer reads it.
 *
 * ## It does not strip anything, and that is the rule
 *
 * `old_value` and `new_value` go out **verbatim**, including a salary, a project price, a
 * payroll figure or an employee's email. That is not an oversight in the field-absence rule
 * (Part B §3 rule 1) — it is the rule's other half. Part C §4 requires `salary.changed` and
 * `project.price_changed` to be recorded *with old and new values*, and acceptance criterion 11
 * requires the log to capture every event in that list. A viewer that redacted the values would
 * leave the agency with a compliance table nobody can audit.
 *
 * What keeps that safe is where this resource is allowed to appear, not what it removes:
 *
 *   - It has exactly **one caller** — `Admin\AuditLogController` — and no other controller,
 *     service or resource may use it. There is no `whenLoaded` escape hatch and no partial
 *     mode, because a resource with a "safe subset" is a resource somebody will reach for from
 *     a screen that should not have it.
 *   - That controller is behind `surface:admin` **and** `can:audit.view`, which Part C §1 gives
 *     to ADMIN alone. An ACCOUNTANT holds `payroll.view_others` and may read every payslip in
 *     the agency — and is still 403 here, because reading the history of who changed what is a
 *     different question from reading what it now says.
 *
 * ## The diff is computed here, once
 *
 * `old_value` / `new_value` are JSON, and two blobs printed side by side are not a diff: the
 * reader has to find which of eleven keys moved. So `diff` is the reading of the pair — which
 * field changed, from what, to what — and it is computed on the server for the reason
 * `HolidayResource` derives its weekday there: a second copy of this in a Vue computed drifts,
 * and this one is worth testing in Pest rather than in a browser.
 *
 * The raw pair still travels beside it. An auditor is entitled to see exactly what the table
 * stored, in the shape it stored it, and `diff` is an interpretation — a helpful one, but not
 * the record.
 *
 * ### What the four `kind`s mean
 *
 *   - `created` — `old_value` is null. There was nothing before this; every field is new. Not
 *     the same as *unchanged*, and not the same as *empty*.
 *   - `deleted` — `new_value` is null. This row is the only thing left of what went (the
 *     `finance.record_deleted` case: `income` and `expenses` are hard deletes).
 *   - `updated` — both sides are maps. The interesting one, and the only one with unchanged
 *     fields to hide.
 *   - `opaque` — at least one side is present but is **not** a string-keyed map: a scalar, or a
 *     list. No writer in this build produces one (every `auditValues()` is a flat map), but the
 *     column is `jsonb` with nothing enforcing that, and a row from an older build may be
 *     anything. It renders as the two values whole, with a sentence saying why, rather than as
 *     an exception.
 *   - `empty` — both sides are null. The event is the whole of the record (there is no such
 *     writer today either).
 *
 * @mixin AuditLog
 */
class AuditLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var AuditLog $log */
        $log = $this->resource;

        $event = (string) $log->event;
        $old = $log->old_value;
        $new = $log->new_value;

        return [
            'id' => (int) $log->getKey(),

            // The raw string as stored, so a row from an older build is still identifiable, and
            // the label beside it. `AuditEvent::labelFor()` humanises a value it has no case
            // for rather than returning nothing — the column has no CHECK behind it.
            'event' => $event,
            'event_label' => AuditEvent::labelFor($event),
            'event_group' => AuditEvent::groupFor($event),
            'event_known' => AuditEvent::tryFrom($event) !== null,

            // Who. Null actor is a fact and not a gap: a console command or a queue worker acts
            // with no signed-in user (`hq:mark-absent` at 23:55, the overdue sweep), and the
            // screen says *System* rather than leaving the cell blank.
            'actor' => $log->actor === null ? null : [
                'id' => (int) $log->actor->getKey(),
                'name' => (string) $log->actor->name,
                'email' => (string) $log->actor->email,
            ],

            // What it was about. `target_type` is the morph class as stored — the FQCN, because
            // this application registers no morph map — so the short name is derived here and
            // not by a Vue `split('\\')`.
            'target' => $log->target_type === null ? null : [
                'type' => (string) $log->target_type,
                'label' => self::targetLabel((string) $log->target_type),
                'id' => $log->target_id === null ? null : (int) $log->target_id,
            ],

            // Verbatim. See the class docblock.
            'old_value' => $old,
            'new_value' => $new,
            'diff' => self::diff($old, $new),

            // When, and from where. `created_at` is ISO 8601 with its offset so nothing has to
            // be guessed; `recorded_at` is the same instant in the agency's timezone, rendered
            // by the server for the reason the date filter boundaries there too — a log whose
            // rows are printed in the reader's browser timezone and filtered in the agency's
            // puts "yesterday" at the top of today.
            'created_at' => $log->created_at?->toIso8601String(),
            'recorded_at' => $log->created_at
                ?->copy()
                ->timezone((string) config('app.timezone'))
                ->isoFormat('D MMM YYYY, h:mm:ss a'),

            'ip' => $log->ip,
            'user_agent' => $log->user_agent,
        ];
    }

    /**
     * `App\Models\PayrollPeriod` → `Payroll period`.
     *
     * The stored value is the FQCN and it stays in the payload; this is only what a reader is
     * shown. Derived rather than looked up in a table of the twenty models that appear, because
     * such a table is a list somebody has to remember to extend and the answer is mechanical.
     *
     * Public because the target-type filter's options are built from the distinct values in the
     * column and have to print the same name this does — two derivations of one class's name is
     * how a chip comes to say something the column beside it does not.
     */
    public static function targetLabel(string $type): string
    {
        $short = str_contains($type, '\\')
            ? (string) substr($type, (int) strrpos($type, '\\') + 1)
            : $type;

        $words = preg_replace('/(?<!^)[A-Z]/', ' $0', $short);

        return ucfirst(strtolower((string) ($words ?? $short)));
    }

    /**
     * Read the pair of JSON values as a field-by-field diff.
     *
     * Top level only, and deliberately: every writer's payload is a **flat** map by construction
     * (each model's `auditValues()` is one method used for both halves), so there is no nesting
     * to walk — and a nested value is handed over whole rather than flattened into
     * `a.b.c` keys, so nothing about it is hidden from the reader.
     *
     * Two values are compared with `===`, which for decoded JSON compares arrays recursively and
     * by key order. A nested map written with its keys in a different order on each side would
     * therefore read as *changed* when it is not. That is the safe direction of the error — it
     * never hides a change — and it cannot arise here anyway: both halves of every pair come out
     * of the same method, and `jsonb` normalises key order identically on both sides.
     *
     * @return array{kind: string, fields: list<array<string, mixed>>, changed_count: int, unchanged_count: int}
     */
    private static function diff(mixed $old, mixed $new): array
    {
        $oldMap = self::asMap($old);
        $newMap = self::asMap($new);

        // A side that is present but is not a map cannot be diffed by field. Nothing in this
        // build writes one; `jsonb` allows it and an older build might have.
        if (($old !== null && $oldMap === null) || ($new !== null && $newMap === null)) {
            return self::noFields('opaque');
        }

        if ($old === null && $new === null) {
            return self::noFields('empty');
        }

        $kind = match (true) {
            $oldMap === null => 'created',
            $newMap === null => 'deleted',
            default => 'updated',
        };

        $oldMap ??= [];
        $newMap ??= [];

        // **Ordered by field name, because the writer's order is already gone.** `old_value` and
        // `new_value` are `jsonb`, and PostgreSQL's `jsonb` does not store an object's keys in
        // the order they were written: it normalises them by key length and then bytewise. A
        // salary row written as (id, employee_id, employee_name, base_salary, allowance,
        // effective_from, set_by) reads back as (id, set_by, allowance, base_salary,
        // employee_id, employee_name, effective_from) — verified against a real row in this
        // repo, not assumed.
        //
        // So "keep the payload's order" is not an option that exists; the only choice is between
        // `jsonb`'s ordering, which looks random to a reader, and one that does not. Sorted by
        // the label a reader actually sees, a list of eleven fields is scannable and two rows of
        // the same event always list their fields the same way.
        $keys = array_keys($oldMap + $newMap);

        usort($keys, fn (int|string $a, int|string $b): int => strcasecmp(
            self::fieldLabel((string) $a),
            self::fieldLabel((string) $b),
        ));

        $fields = [];
        $changed = 0;
        $unchanged = 0;

        foreach ($keys as $key) {
            $inOld = array_key_exists($key, $oldMap);
            $inNew = array_key_exists($key, $newMap);
            $oldValue = $inOld ? $oldMap[$key] : null;
            $newValue = $inNew ? $newMap[$key] : null;

            // Four states, and the two absences are not the same thing as a null value. A key
            // missing from one side was never recorded there; a key present with the value null
            // was recorded as empty. A reader chasing "when did her allowance stop being set"
            // needs to tell those apart.
            $state = match (true) {
                ! $inOld && $inNew => 'added',
                $inOld && ! $inNew => 'removed',
                $oldValue === $newValue => 'unchanged',
                default => 'changed',
            };

            $state === 'unchanged' ? $unchanged++ : $changed++;

            $fields[] = [
                'key' => (string) $key,
                'label' => self::fieldLabel((string) $key),
                'state' => $state,
                'in_old' => $inOld,
                'in_new' => $inNew,
                'old' => $oldValue,
                'new' => $newValue,
            ];
        }

        return [
            'kind' => $kind,
            'fields' => $fields,
            'changed_count' => $changed,
            'unchanged_count' => $unchanged,
        ];
    }

    /**
     * The value as a string-keyed map, or null when it is not one.
     *
     * A JSON list decodes to an array with integer keys, which is not a set of fields and must
     * not be diffed as one — `tag.deleted` carries a `task_ids` list *inside* its map, and a
     * payload that were itself a list would have no field names at all.
     *
     * @return array<string, mixed>|null
     */
    private static function asMap(mixed $value): ?array
    {
        if (! is_array($value) || $value === []) {
            return is_array($value) ? [] : null;
        }

        foreach (array_keys($value) as $key) {
            if (! is_string($key)) {
                return null;
            }
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * `base_salary` → `Base salary`.
     *
     * Sentence case rather than `Str::headline()`'s Title Case, because these are field names in
     * a sentence about a record and `Base Salary` reads like a menu item. An id stays lowercase
     * (`Employee id`) for the same reason.
     */
    private static function fieldLabel(string $key): string
    {
        $words = trim(str_replace(['_', '-', '.'], ' ', $key));

        return $words === '' ? $key : ucfirst($words);
    }

    /**
     * The two diff kinds that have no fields to list.
     *
     * @return array{kind: string, fields: list<array<string, mixed>>, changed_count: int, unchanged_count: int}
     */
    private static function noFields(string $kind): array
    {
        return ['kind' => $kind, 'fields' => [], 'changed_count' => 0, 'unchanged_count' => 0];
    }
}
