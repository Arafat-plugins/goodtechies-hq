<?php

namespace App\Http\Requests\Recurring;

use App\Support\RecurrenceFrequency;
use App\Support\RecurrenceRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * The recurrence half of a template form: three shapes of one rule, validated and assembled.
 *
 * ## Why the three shapes share one set of fields
 *
 * `RecurrenceRule` has exactly three named constructors and no fourth, so this trait's whole job
 * is to pick one and hand it its arguments. The form sends `frequency` plus the parameters that
 * frequency uses — `day_of_month`, or `weekday`, or `interval_days` + `anchor` — and
 * `due_offset_days` if the author wants a due date other than the end of the period.
 *
 * The other frequencies' fields are ignored rather than forbidden. A monthly rule arriving with
 * a leftover `weekday` from an editor the author toggled through twice is not an error; it is a
 * field that does not apply, and `rule()` never looks at it. Refusing it would make a perfectly
 * clear intention fail validation because of a control that was on screen a moment ago.
 *
 * ## Why the bounds are restated here
 *
 * `RecurrenceRule` CLAMPS — it is read by a console command at five past midnight against rows
 * nobody is watching, so it turns nonsense into the obvious thing rather than throwing. That is
 * right for the engine and wrong for a form: an admin who types 31 and is silently given the
 * 28th has been told nothing. So the bounds are asserted here, against the rule's own constants,
 * and a person gets a sentence under the field. The clamp stays as the last line of defence for
 * input that never came through a form.
 */
trait BuildsRecurrenceRule
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    protected function recurrenceRules(): array
    {
        return [
            'frequency' => ['required', Rule::enum(RecurrenceFrequency::class)],

            // Clamped at 28 by the rule so that February never silently skips a month; asserted
            // here so that asking for the 31st is a refusal a person can read.
            'day_of_month' => [
                'nullable',
                'required_if:frequency,'.RecurrenceFrequency::Monthly->value,
                'integer',
                'between:1,'.RecurrenceRule::MAX_DAY_OF_MONTH,
            ],

            // ISO weekdays, 1 = Monday … 7 = Sunday, matching RecurrenceRule::weekly().
            'weekday' => [
                'nullable',
                'required_if:frequency,'.RecurrenceFrequency::Weekly->value,
                'integer',
                'between:1,7',
            ],

            'interval_days' => [
                'nullable',
                'required_if:frequency,'.RecurrenceFrequency::Custom->value,
                'integer',
                'between:'.RecurrenceRule::MIN_INTERVAL_DAYS.','.RecurrenceRule::MAX_INTERVAL_DAYS,
            ],

            // The day the very first custom period began. Every later period is counted from it,
            // including periods BEFORE it — the rule floors, so a backdated anchor still lands
            // every cycle on the same grid.
            'anchor' => [
                'nullable',
                'required_if:frequency,'.RecurrenceFrequency::Custom->value,
                'date',
            ],

            // Days after the period START, on all three frequencies. Null means "the end of the
            // period", which is the default and what most retainers want.
            'due_offset_days' => [
                'nullable',
                'integer',
                'between:0,'.RecurrenceRule::MAX_INTERVAL_DAYS,
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function recurrenceMessages(): array
    {
        return [
            'frequency.required' => 'Choose how often this repeats.',
            'day_of_month.required_if' => 'Choose which day of the month it runs on.',
            'day_of_month.between' => 'Pick a day from 1 to '.RecurrenceRule::MAX_DAY_OF_MONTH.
                ', so the rule never skips February.',
            'weekday.required_if' => 'Choose which day of the week it runs on.',
            'interval_days.required_if' => 'Say how many days there are between runs.',
            'interval_days.between' => 'A cycle runs from '.RecurrenceRule::MIN_INTERVAL_DAYS.
                ' to '.RecurrenceRule::MAX_INTERVAL_DAYS.' days.',
            'anchor.required_if' => 'Choose the date the first cycle starts from.',
            'due_offset_days.between' => 'A due date is 0 to '.RecurrenceRule::MAX_INTERVAL_DAYS.
                ' days after the period starts.',
        ];
    }

    /**
     * The rule this request describes.
     *
     * One `match`, three constructors, and no arithmetic: everything the rule means — which day
     * a period starts, what its key is, when it is due — belongs to `RecurrenceRule` and is
     * asked of it, never restated here.
     */
    public function recurrenceRule(): RecurrenceRule
    {
        $offset = $this->integerOrNull('due_offset_days');

        return match (RecurrenceFrequency::from((string) $this->validated('frequency'))) {
            RecurrenceFrequency::Monthly => RecurrenceRule::monthly(
                $this->integerOrNull('day_of_month') ?? 1,
                $offset,
            ),
            RecurrenceFrequency::Weekly => RecurrenceRule::weekly(
                $this->integerOrNull('weekday') ?? 1,
                $offset,
            ),
            RecurrenceFrequency::Custom => RecurrenceRule::custom(
                $this->integerOrNull('interval_days') ?? 14,
                (string) $this->validated('anchor'),
                $offset,
            ),
        };
    }

    private function integerOrNull(string $key): ?int
    {
        $value = $this->validated($key);

        return $value === null || $value === '' ? null : (int) $value;
    }
}
