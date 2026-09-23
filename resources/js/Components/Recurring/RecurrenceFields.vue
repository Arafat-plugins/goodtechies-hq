<script setup lang="ts">
import { computed, useId } from 'vue';
import type { RecurrenceDraft, RecurrenceFrequency } from '@/Components/Recurring/recurring';
import { MAX_DAY_OF_MONTH, MAX_INTERVAL_DAYS, MIN_INTERVAL_DAYS, WEEKDAYS } from '@/Components/Recurring/recurring';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';

/**
 * The recurrence editor: three shapes of one rule.
 *
 * ## Why there are three sets of controls and one request body
 *
 * `App\Support\RecurrenceRule` has exactly three named constructors — `monthly(dayOfMonth)`,
 * `weekly(weekday)`, `custom(intervalDays, anchor)` — plus one `dueOffsetDays` knob that means
 * the same thing on all three: days after the period STARTS. So the form's whole job is to pick
 * a frequency and fill in the parameters that frequency uses, which is exactly what is on
 * screen: choose Monthly and the control is a day of the month; choose Weekly and it is a
 * weekday; choose Custom and it is an interval plus the date the first cycle started.
 *
 * Nothing here works out what those choices MEAN. When the rule next fires, which period that
 * run belongs to and when the instance is due are all `RecurrenceRule`'s answers, fetched from
 * `…/recurring/preview` by whoever mounts this — see `RecurringTemplateDialog`. There is no
 * fourth shape to invent and no date arithmetic to get wrong.
 *
 * ## Why the bounds are on the controls as well as on the server
 *
 * `RecurrenceRule` clamps rather than throws, because a console command at five past midnight
 * must generate the obvious thing rather than die. A form that let somebody type 31 and then
 * silently stored 28 would have told them nothing, so the Form Request refuses it with a
 * sentence and the control here does not offer it in the first place.
 */

const draft = defineModel<RecurrenceDraft>({ required: true });

const props = withDefaults(
    defineProps<{
        /** The server's field errors, by the key it names them with. */
        errors?: Partial<Record<string, string>>;
        disabled?: boolean;
    }>(),
    { errors: () => ({}), disabled: false },
);

const uid = useId();
const ids = {
    frequency: `${uid}-frequency`,
    dayOfMonth: `${uid}-day-of-month`,
    weekday: `${uid}-weekday`,
    interval: `${uid}-interval`,
    anchor: `${uid}-anchor`,
    due: `${uid}-due`,
    dueOffset: `${uid}-due-offset`,
};

const FREQUENCIES: { value: RecurrenceFrequency; label: string }[] = [
    { value: 'monthly', label: 'Monthly' },
    { value: 'weekly', label: 'Weekly' },
    { value: 'custom', label: 'Custom' },
];

/**
 * The due control is a choice between two sentences rather than a nullable number, because
 * "leave it empty for the end of the period" is a rule nobody reads. `due_at_period_end` is
 * the editor's own flag; `ruleFields()` turns it back into `due_offset_days: null`.
 */
const dueMode = computed<string>({
    get: () => (draft.value.due_at_period_end ? 'end' : 'offset'),
    set: (value) => {
        draft.value.due_at_period_end = value === 'end';
    },
});

/** `<select>` hands back a string; the request wants the number the rule takes. */
const weekday = computed<string>({
    get: () => String(draft.value.weekday),
    set: (value) => {
        draft.value.weekday = Number(value);
    },
});

const frequency = computed<string>({
    get: () => draft.value.frequency,
    set: (value) => {
        draft.value.frequency = value as RecurrenceFrequency;
    },
});

function error(key: string): string | undefined {
    return props.errors[key];
}
</script>

<template>
    <fieldset class="flex min-w-0 flex-col gap-4">
        <legend class="sr-only">How often this repeats</legend>

        <div class="grid min-w-0 gap-4 sm:grid-cols-2">
            <div class="flex min-w-0 flex-col gap-2">
                <Label :for="ids.frequency">Repeats</Label>
                <Select v-model="frequency" :disabled="disabled">
                    <SelectTrigger :id="ids.frequency" class="w-full">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem v-for="option in FREQUENCIES" :key="option.value" :value="option.value">
                            {{ option.label }}
                        </SelectItem>
                    </SelectContent>
                </Select>
                <p v-if="error('frequency')" class="text-xs text-destructive">{{ error('frequency') }}</p>
            </div>

            <!-- Monthly: one number, clamped to the 28th so a rule never skips February. -->
            <div v-if="draft.frequency === 'monthly'" class="flex min-w-0 flex-col gap-2">
                <Label :for="ids.dayOfMonth">Day of the month</Label>
                <Input
                    :id="ids.dayOfMonth"
                    v-model.number="draft.day_of_month"
                    type="number"
                    inputmode="numeric"
                    min="1"
                    :max="MAX_DAY_OF_MONTH"
                    :disabled="disabled"
                    :aria-invalid="error('day_of_month') ? true : undefined"
                />
                <p v-if="error('day_of_month')" class="text-xs text-destructive">{{ error('day_of_month') }}</p>
            </div>

            <!-- Weekly: the ISO weekday the instance is generated on. -->
            <div v-else-if="draft.frequency === 'weekly'" class="flex min-w-0 flex-col gap-2">
                <Label :for="ids.weekday">Day of the week</Label>
                <Select v-model="weekday" :disabled="disabled">
                    <SelectTrigger :id="ids.weekday" class="w-full">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem v-for="day in WEEKDAYS" :key="day.value" :value="String(day.value)">
                            {{ day.label }}
                        </SelectItem>
                    </SelectContent>
                </Select>
                <p v-if="error('weekday')" class="text-xs text-destructive">{{ error('weekday') }}</p>
            </div>
        </div>

        <!-- Custom: the interval and the date the very first cycle began. -->
        <div v-if="draft.frequency === 'custom'" class="grid min-w-0 gap-4 sm:grid-cols-2">
            <div class="flex min-w-0 flex-col gap-2">
                <Label :for="ids.interval">Days between runs</Label>
                <Input
                    :id="ids.interval"
                    v-model.number="draft.interval_days"
                    type="number"
                    inputmode="numeric"
                    :min="MIN_INTERVAL_DAYS"
                    :max="MAX_INTERVAL_DAYS"
                    :disabled="disabled"
                    :aria-invalid="error('interval_days') ? true : undefined"
                />
                <p v-if="error('interval_days')" class="text-xs text-destructive">{{ error('interval_days') }}</p>
            </div>

            <div class="flex min-w-0 flex-col gap-2">
                <Label :for="ids.anchor">First cycle starts</Label>
                <Input
                    :id="ids.anchor"
                    v-model="draft.anchor"
                    type="date"
                    :disabled="disabled"
                    :aria-invalid="error('anchor') ? true : undefined"
                />
                <p v-if="error('anchor')" class="text-xs text-destructive">{{ error('anchor') }}</p>
            </div>
        </div>

        <div class="grid min-w-0 gap-4 sm:grid-cols-2">
            <div class="flex min-w-0 flex-col gap-2">
                <Label :for="ids.due">The generated task is due</Label>
                <Select v-model="dueMode" :disabled="disabled">
                    <SelectTrigger :id="ids.due" class="w-full">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="end">At the end of the period</SelectItem>
                        <SelectItem value="offset">A fixed number of days after it starts</SelectItem>
                    </SelectContent>
                </Select>
            </div>

            <div v-if="!draft.due_at_period_end" class="flex min-w-0 flex-col gap-2">
                <Label :for="ids.dueOffset">Days after the period starts</Label>
                <Input
                    :id="ids.dueOffset"
                    v-model.number="draft.due_offset_days"
                    type="number"
                    inputmode="numeric"
                    min="0"
                    :max="MAX_INTERVAL_DAYS"
                    :disabled="disabled"
                    :aria-invalid="error('due_offset_days') ? true : undefined"
                />
                <p v-if="error('due_offset_days')" class="text-xs text-destructive">
                    {{ error('due_offset_days') }}
                </p>
            </div>
        </div>
    </fieldset>
</template>
