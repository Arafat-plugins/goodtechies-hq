<?php

namespace App\Http\Requests\Payroll;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Reversing a lock. **ADMIN only, and a reason is required** (master prompt Part D §14,
 * Part C §4: *"only ADMIN can reverse a lock (requires reason, audit-logged)"*).
 *
 * ## The reason is required in three places, and that is deliberate
 *
 *   1. **Here**, so the person gets a field-level 422 on the form they typed it into rather
 *      than a flash sentence at the top of a screen they have to scroll back up to find.
 *   2. **`PayrollService::reverseLock()`**, which throws
 *      `PayrollStateException::reversalNeedsAReason()` — because the service is a public API
 *      that a command, a seeder or a later screen can call without passing through this class.
 *   3. **The database**, as `payroll_periods_reversal_is_whole`: `(lock_reversed_by IS NULL) =
 *      (lock_reversal_reason IS NULL)`, so a reversal with nobody attached or with no stated
 *      cause cannot exist as a row (decision 9-5).
 *
 * Three statements of one rule is not duplication here: they refuse three different callers,
 * and the one that must never be removable is the third.
 *
 * Authorization is `PayrollPeriodPolicy::reverseLock()` — the one verb in that file that names
 * a role — asked by the controller and again inside the service.
 */
class ReverseLockRequest extends FormRequest
{
    /** `lock_reversal_reason` is a `text` column; this is a sentence, not a document. */
    public const MAX_REASON = 1000;

    /**
     * Long enough to be a reason and not a keystroke.
     *
     * *"no"* is not why a month was reopened, and this reason is read years later by whoever is
     * asking why September changed after it closed.
     */
    public const MIN_REASON = 5;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:'.self::MIN_REASON, 'max:'.self::MAX_REASON],
        ];
    }

    /**
     * Trim before validating, so that a field holding only spaces is *required*-empty rather
     * than a five-character reason. The service trims again before it writes.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('reason')) {
            $this->merge(['reason' => trim((string) $this->input('reason'))]);
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Give a reason for reversing the lock. It is recorded on the period and in the audit log.',
            'reason.min' => 'Say why in a few words — this reason is what the audit log will show.',
        ];
    }

    public function reason(): string
    {
        return (string) $this->validated()['reason'];
    }
}
