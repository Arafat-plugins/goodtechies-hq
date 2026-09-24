<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { CalendarOff, Info } from '@lucide/vue';
import { computed, useId, watch } from 'vue';
import type { LeaveBalanceRow, LeaveRequestRow, LeaveTypeOption } from '@/Components/Leave/leave';
import { formatDays, formatWindow, leaveRoutes } from '@/Components/Leave/leave';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { NativeSelect, NativeSelectOption } from '@/Components/ui/native-select';
import { Textarea } from '@/Components/ui/textarea';

/**
 * Applying for leave, and answering a correction request by amending and resubmitting.
 *
 * One form for both, because it is the same four fields and the same request: resubmitting is
 * `correction_requested → pending` on the row that is already there, so a second form would
 * have been a second place for the same rules to be written.
 *
 * ## The date range is two native date inputs, and that is a decision
 *
 * The accessibility floor requires a date-range picker to be usable by keyboard. A native
 * `<input type="date">` is keyboard-operable by construction — typed digits, arrow keys, the
 * platform picker on Enter — needs no focus trap, no roving tabindex and no live region, and
 * announces itself correctly in every screen reader without this repo owning the code that
 * makes that true. A two-month grid widget would have had to earn all of that, and the thing
 * it buys — seeing which days are free — is on the leave calendar next door, where an Admin
 * reads it.
 *
 * `min` on the second input follows the first, so the browser refuses a backwards range before
 * the server has to; `ApplyLeaveRequest` refuses it again with `after_or_equal`.
 *
 * ## What the form says before it is sent
 *
 * Two things, because both of them are surprises otherwise:
 *
 *   - **how many days this will actually cost**, which is the employee's own working days in
 *     the window and not the number of dates in it. The exact count is the server's — it is the
 *     schedule's answer, and duplicating that arithmetic here is exactly the second statement
 *     decision 2-37 forbids — so the form says which rule applies rather than a number it
 *     invented, and the flash after the write names the count;
 *   - **that Unpaid days are unpaid**, and that an uncapped type has no balance to run out of.
 *     Both are facts about the type sent by the server (`has_balance`, `is_unpaid`), never
 *     inferred from the name.
 */

const props = defineProps<{
    types: LeaveTypeOption[];
    balances: LeaveBalanceRow[];
    /** Present when this form is answering a correction request rather than filing a new one. */
    editing?: LeaveRequestRow | null;
}>();

const emit = defineEmits<{ cancel: [] }>();

const typeId = useId();
const startId = useId();
const endId = useId();
const reasonId = useId();

/**
 * Put the caret in the first field.
 *
 * Exposed rather than done here, because the only caller is the page that decides this form is
 * now answering a correction — it knows when, this does not. `preventScroll` because that caller
 * scrolls, and a focus that scrolls too fights it: the browser jumps, then the smooth scroll
 * starts from somewhere else.
 *
 * Found by `id` rather than a template ref: the id is already on the real `<select>` because
 * `<Label for>` needs it there, so this cannot drift from what the label points at, and it does
 * not depend on `NativeSelect` forwarding a ref it has never promised to forward.
 */
function focus(): void {
    const field = typeof document === 'undefined' ? null : document.getElementById(typeId);

    (field as HTMLElement | null)?.focus({ preventScroll: true });
}

defineExpose({ focus });

const form = useForm({
    leave_type_id: props.editing?.type?.id ?? props.types[0]?.id ?? 0,
    start_date: props.editing?.start_date ?? '',
    end_date: props.editing?.end_date ?? '',
    reason: props.editing?.reason ?? '',
});

/** Re-seed the fields whenever the request being amended changes. */
watch(
    () => props.editing?.id,
    () => {
        form.clearErrors();
        form.leave_type_id = props.editing?.type?.id ?? props.types[0]?.id ?? 0;
        form.start_date = props.editing?.start_date ?? '';
        form.end_date = props.editing?.end_date ?? '';
        form.reason = props.editing?.reason ?? '';
    },
);

const selected = computed<LeaveTypeOption | null>(
    () => props.types.find((type) => type.id === Number(form.leave_type_id)) ?? null,
);

/** Days left of the selected type, or null when it has no cap to have days left of. */
const remaining = computed<number | null>(() => {
    if (!selected.value?.has_balance) {
        return null;
    }

    return props.balances.find((row) => row.type.id === selected.value?.id)?.balance_days ?? 0;
});

/** The window as a phrase, once both ends are filled in. */
const window = computed<string | null>(() =>
    form.start_date && form.end_date ? formatWindow(form.start_date, form.end_date) : null,
);

function submit(): void {
    if (props.editing) {
        form.put(leaveRoutes.resubmit(props.editing.id), { preserveScroll: true });

        return;
    }

    form.post(leaveRoutes.apply, {
        preserveScroll: true,
        onSuccess: () => {
            form.reset('start_date', 'end_date', 'reason');
        },
    });
}
</script>

<template>
    <Card class="flex flex-col gap-4 p-4 md:p-6">
        <div class="flex flex-col gap-1">
            <h2 class="text-base font-semibold tracking-tight">
                {{ editing ? 'Amend and send back' : 'Apply for leave' }}
            </h2>
            <p class="text-sm text-muted-foreground">
                <template v-if="editing">
                    {{ editing.approver?.name ?? 'An approver' }} asked for a correction. Change what you need to
                    and send it back — it keeps its place in the queue.
                </template>
                <template v-else>
                    Only your working days are counted, so a weekend inside the dates costs you nothing.
                </template>
            </p>
        </div>

        <form class="flex flex-col gap-4" @submit.prevent="submit">
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="flex min-w-0 flex-col gap-2 sm:col-span-2">
                    <Label :for="typeId">Type of leave</Label>
                    <NativeSelect :id="typeId" v-model="form.leave_type_id" class="w-full" required>
                        <NativeSelectOption v-for="type in types" :key="type.id" :value="type.id">
                            {{ type.name }}
                        </NativeSelectOption>
                    </NativeSelect>
                    <p v-if="form.errors.leave_type_id" class="text-xs text-destructive">
                        {{ form.errors.leave_type_id }}
                    </p>
                </div>

                <div class="flex min-w-0 flex-col gap-2">
                    <Label :for="startId">First day</Label>
                    <Input :id="startId" v-model="form.start_date" type="date" required />
                    <p v-if="form.errors.start_date" class="text-xs text-destructive">
                        {{ form.errors.start_date }}
                    </p>
                </div>

                <div class="flex min-w-0 flex-col gap-2">
                    <Label :for="endId">Last day</Label>
                    <Input :id="endId" v-model="form.end_date" type="date" :min="form.start_date || undefined" required />
                    <p v-if="form.errors.end_date" class="text-xs text-destructive">{{ form.errors.end_date }}</p>
                </div>

                <div class="flex min-w-0 flex-col gap-2 sm:col-span-2">
                    <Label :for="reasonId">Reason</Label>
                    <Textarea
                        :id="reasonId"
                        v-model="form.reason"
                        rows="3"
                        placeholder="What the time is for. The person approving it reads this."
                        required
                    />
                    <p v-if="form.errors.reason" class="text-xs text-destructive">{{ form.errors.reason }}</p>
                </div>
            </div>

            <!--
                What this will cost, in words rather than in a number this screen invented.
                The count is the schedule's answer and comes back in the flash.
            -->
            <div class="flex flex-col gap-2 rounded-md border bg-muted/40 p-3 text-xs text-muted-foreground">
                <p v-if="window" class="flex items-start gap-2">
                    <CalendarOff class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                    <span>{{ window }} — your working days inside those dates are what is counted.</span>
                </p>

                <p v-if="selected" class="flex items-start gap-2">
                    <Info class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                    <span v-if="remaining !== null">
                        {{ selected.name }}: {{ formatDays(remaining) }} left. A request for more than that is
                        refused.
                    </span>
                    <span v-else-if="selected.is_unpaid">
                        {{ selected.name }} has no balance, so it is never refused for want of days — but every day
                        of it is <strong class="font-medium text-foreground">unpaid</strong>.
                    </span>
                    <span v-else>
                        {{ selected.name }} has no balance, so it is never refused for want of days. These days are
                        still paid.
                    </span>
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <Button type="submit" :disabled="form.processing">
                    {{ editing ? 'Send back for approval' : 'Request leave' }}
                </Button>
                <Button v-if="editing" type="button" variant="outline" @click="emit('cancel')">Cancel</Button>
            </div>
        </form>
    </Card>
</template>
