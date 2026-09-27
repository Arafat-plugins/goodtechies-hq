<?php

namespace App\Http\Requests\Payroll;

use App\Models\PayrollItem;
use Illuminate\Foundation\Http\FormRequest;

/**
 * One employee's line, as the payroll workbench writes it (master prompt Part D §14, Phase 9).
 *
 * Part D §14 names the fields: *"Accountant fills/adjusts base salary, allowance, bonus,
 * deduction, advance"*. `admin_notes` rides along on the same request because an Admin edits it
 * in the same dialog — but it is a different act with a different policy method
 * (`PayrollItemPolicy::annotate`, ADMIN only), and `PayrollController` asks for that separately.
 *
 * Authorization is **not** here. `PayrollItemPolicy::update()` answers it, and it reads the
 * period's status as well as the person, because Part D §14's *"read-only to the Accountant
 * afterwards"* is a different answer for two roles at the same status. A Form Request cannot
 * see the routed item's period, and a second copy of that table is a second place for it to be
 * wrong.
 *
 * ## `net_salary` is PROHIBITED, not ignored
 *
 * `payroll_items.net_salary` is `GENERATED ALWAYS AS (…) STORED` (decision 9-3): PostgreSQL
 * computes it on every write and refuses any statement that assigns it. `PayrollService::
 * adjustItem()` already narrows to `PayrollItem::ADJUSTABLE`, so an extra key would be dropped
 * in silence — and **silence is the wrong answer here**. A form that posts a net is a form
 * somebody built believing it could set one, and the useful reply is a 422 saying the figure is
 * the database's, not a 200 that quietly kept the old number. `leave_impact` goes with it for
 * the same reason on the other side: it is Calculate's, from approved leave, never typed.
 *
 * Both are **read-only for everybody, always** — an Admin has no more right to type a net than
 * the Accountant does — so there is no role branch anywhere near these two rules.
 *
 * ## Every field is `sometimes`
 *
 * The dialog posts the five figures together, and the Admin's notes field posts on its own from
 * a locked period (annotating a locked month is deliberately still allowed —
 * `PayrollItemPolicy::annotate` has no status window, because the note explaining *why* a lock
 * was reversed has to be writable on the item it is about). `sometimes|required` keeps a field
 * that IS sent from arriving empty, while a field that is not sent is simply not changed.
 */
class AdjustPayrollItemRequest extends FormRequest
{
    /** `decimal(12, 2)` — ten digits before the point, two after. */
    public const MAX_AMOUNT = '9999999999.99';

    /** `admin_notes` is a `text` column; this is a form field, not a document. */
    public const MAX_NOTES = 2000;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $money = ['sometimes', 'required', 'numeric', 'decimal:0,2', 'min:0', 'max:'.self::MAX_AMOUNT];

        $rules = [];

        // The five Part D §14 names, from the model's own list rather than retyped — a sixth
        // adjustable column would otherwise be accepted by the service and refused here.
        foreach (PayrollItem::ADJUSTABLE as $field) {
            $rules[$field] = $money;
        }

        $rules['admin_notes'] = ['sometimes', 'nullable', 'string', 'max:'.self::MAX_NOTES];

        // See the class note. Refused loudly rather than dropped quietly.
        $rules['net_salary'] = ['prohibited'];
        $rules['leave_impact'] = ['prohibited'];

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'net_salary.prohibited' => 'The net salary is worked out by the database from the figures beside it. It cannot be set.',
            'leave_impact.prohibited' => 'Leave impact comes from approved unpaid leave and is written by Calculate. It cannot be typed.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'base_salary' => 'base salary',
            'admin_notes' => 'personal notes',
        ];
    }

    /**
     * The five figures that were actually sent, as exact decimal strings.
     *
     * Strings and never floats — the columns are `decimal(12, 2)` and binary float cannot hold
     * 0.10 (decision 8-4). `PayrollService::adjustItem()` narrows this again to
     * `PayrollItem::ADJUSTABLE`, which is belt and braces on the one rule that must not have a
     * gap.
     *
     * @return array<string, string>
     */
    public function figures(): array
    {
        $validated = $this->validated();
        $figures = [];

        foreach (PayrollItem::ADJUSTABLE as $field) {
            if (array_key_exists($field, $validated)) {
                $figures[$field] = (string) $validated[$field];
            }
        }

        return $figures;
    }

    /** Were any figures sent at all? A notes-only save is a legitimate request. */
    public function changesFigures(): bool
    {
        return $this->figures() !== [];
    }

    /** Was the notes field sent at all? Absent is "leave it alone"; empty is "clear it". */
    public function changesNotes(): bool
    {
        return array_key_exists('admin_notes', $this->validated());
    }

    /** The note to write, or null to clear it. `PayrollService::annotate()` trims it again. */
    public function notes(): ?string
    {
        $value = $this->validated()['admin_notes'] ?? null;

        return $value === null ? null : (string) $value;
    }
}
