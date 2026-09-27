<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, useId, watch } from 'vue';
import type { PayrollItem, PayrollLeaveBreakdown } from '@/Components/Payroll/payroll';
import {
    PAYROLL_ADJUSTABLE,
    PAYROLL_FIELD_LONG_LABELS,
    formatMoney,
    leaveImpactSentence,
    payrollRoutes,
    receivesAdminNotes,
} from '@/Components/Payroll/payroll';
import { Button } from '@/Components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';

/**
 * **One employee's line, edited.** The five figures Part D §14 gives the Accountant, and — for
 * an Admin, and for nobody else — the personal notes beside them.
 *
 * ## Three things this form cannot do, by construction
 *
 *   1. **It cannot post a net.** `net_salary` is a `GENERATED ALWAYS … STORED` column
 *      (decision 9-3): PostgreSQL computes it from the five figures on every write. There is no
 *      input for it, it is not in the form object, and `AdjustPayrollItemRequest` **prohibits**
 *      the key outright — so a hand-made request is a 422 saying the figure is the database's,
 *      not a 200 that quietly kept the old number. The dialog shows the current net as text,
 *      with a line saying who works it out.
 *   2. **It cannot post a leave impact.** That figure is Calculate's, from approved unpaid
 *      leave. Same treatment: shown, explained, never typed.
 *   3. **It cannot show `admin_notes` to somebody who did not receive it.** The test is
 *      `receivesAdminNotes()` — the presence of the KEY, never a null value — because the
 *      server leaves the key out for the Accountant and for the employee the note is about
 *      (decision 9-10). No field, no placeholder, no empty box.
 *
 * ## Nothing here does arithmetic on money
 *
 * Every figure rendered is a string the server sent, passed through `formatMoney()` — the one
 * place money becomes text in this application (decision 8-4). The inputs hold the **raw
 * decimal strings** the columns contain, so what is submitted is what was shown; there is no
 * `toFixed` on a parsed float anywhere in this file, and the net is not recomputed as the
 * figures change. A net that updated as you typed would be a second implementation of a
 * database expression, and it would be wrong the first time somebody had unpaid leave.
 *
 * Focus is the caller's job — see `Pages/Shared/Payroll/Show.vue`. reka restores focus to
 * whatever held it when the dialog opened, which is exactly right here because the trigger is
 * an ordinary button that stays mounted; the page still returns it explicitly, because a save
 * closes this dialog by *navigating* and no close handler runs on that path.
 */
const props = defineProps<{
    /** The line being edited, or null when the dialog is closed. */
    item: PayrollItem | null;
    /** The period the line is on — the URL needs it, and the sentences name it. */
    periodId: number;
    monthLabel: string;
    /** What this line's leave impact is made of. */
    breakdown: PayrollLeaveBreakdown | undefined;
    currency: string;
    /** False once the month is past editing — the dialog then reads rather than writes. */
    figuresEditable: boolean;
}>();

const emit = defineEmits<{ close: [] }>();

const notesId = useId();

const form = useForm<Record<string, string>>({
    base_salary: '',
    allowance: '',
    bonus: '',
    deduction: '',
    advance: '',
    admin_notes: '',
});

const employeeName = computed(() => props.item?.employee?.name ?? 'this line');

/** May the Admin write the personal note? The server answered; this only renders it. */
const canAnnotate = computed(() => props.item !== null && props.item.permissions.can_annotate);

/** Is there anything to submit at all, or is this a read-only view of a closed month? */
const canSubmit = computed(
    () => (props.figuresEditable && (props.item?.permissions.can_update ?? false)) || canAnnotate.value,
);

const leaveSentence = computed(() =>
    props.item === null
        ? ''
        : leaveImpactSentence(
              employeeName.value,
              props.monthLabel,
              props.breakdown,
              props.item.leave_impact,
              props.currency,
          ),
);

/** A stable input id per field, so the `<Label for>` pairs survive a re-render. */
const fieldIdBase = useId();
const fieldIds: Record<string, string> = {};

for (const field of PAYROLL_ADJUSTABLE) {
    fieldIds[field] = `${fieldIdBase}-${field}`;
}

function submit(): void {
    const item = props.item;

    if (!item) {
        return;
    }

    // Only what this person may actually change travels. An Accountant's save carries no
    // `admin_notes` key at all — not an empty one — so the server never has to decide whether
    // a blank note from somebody who may not write notes means "clear it".
    form.transform((data) => {
        const payload: Record<string, unknown> = {};

        if (props.figuresEditable && item.permissions.can_update) {
            for (const field of PAYROLL_ADJUSTABLE) {
                payload[field] = data[field];
            }
        }

        if (canAnnotate.value) {
            payload.admin_notes = data.admin_notes.trim() === '' ? null : data.admin_notes;
        }

        return payload;
    });

    // **`onSuccess` closes it, and that is not a nicety.** A save answers `back()`, so the page
    // re-renders with fresh props while this component keeps holding the item object it was
    // opened with — the dialog would stay up showing the figures as they were BEFORE the save,
    // over a table that had already updated. (Found in the browser, not in a test: the
    // assertion that catches it is "the overlay is gone", which no HTTP test makes.)
    //
    // A validation failure deliberately does NOT close: the errors belong on the fields that
    // caused them.
    form.put(payrollRoutes.item(props.periodId, item.id), {
        preserveScroll: true,
        onSuccess: () => emit('close'),
    });
}

/**
 * Fill the form when the dialog opens, and clear the errors when it closes — so a cancelled
 * edit leaves neither a half-typed figure nor somebody else's validation message behind.
 *
 * The values are the **exact decimal strings** the server sent. They are not parsed, rounded or
 * reformatted on the way in, so a figure that goes back untouched is byte-identical to the one
 * in the column.
 */
watch(
    () => [props.item?.id, props.item !== null] as const,
    () => {
        form.clearErrors();

        const item = props.item;

        if (!item) {
            return;
        }

        for (const field of PAYROLL_ADJUSTABLE) {
            form[field] = item[field];
        }

        form.admin_notes = receivesAdminNotes(item) ? (item.admin_notes ?? '') : '';
    },
    { immediate: true },
);
</script>

<template>
    <Dialog :open="item !== null" @update:open="(open: boolean) => { if (!open) { emit('close'); } }">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>{{ employeeName }} — {{ monthLabel }}</DialogTitle>
                <DialogDescription>
                    <template v-if="figuresEditable && item?.permissions.can_update">
                        Base, allowance, bonus, deduction and advance are yours to set. Leave impact and the net
                        are not: one comes from approved unpaid leave when Calculate runs, the other is worked
                        out by the database from the five figures above it.
                    </template>
                    <template v-else-if="canAnnotate">
                        The figures on this line are settled and can no longer be changed. The personal note can.
                    </template>
                    <template v-else>
                        This line is read-only.
                    </template>
                </DialogDescription>
            </DialogHeader>

            <form v-if="item" class="flex min-w-0 flex-col gap-4" @submit.prevent="submit">
                <div
                    v-if="figuresEditable && item.permissions.can_update"
                    class="grid min-w-0 grid-cols-1 gap-4 sm:grid-cols-2"
                >
                    <div v-for="field in PAYROLL_ADJUSTABLE" :key="field" class="flex min-w-0 flex-col gap-2">
                        <Label :for="fieldIds[field]">{{ PAYROLL_FIELD_LONG_LABELS[field] }}</Label>
                        <Input
                            :id="fieldIds[field]"
                            v-model="form[field]"
                            type="number"
                            inputmode="decimal"
                            step="0.01"
                            min="0"
                            required
                            class="tabular-nums"
                            placeholder="0.00"
                        />
                        <p v-if="form.errors[field]" class="text-xs text-destructive">
                            {{ form.errors[field] }}
                        </p>
                    </div>
                </div>

                <!--
                    The month is closed to edits, so the five figures are read rather than
                    typed. A row of disabled inputs would be a form that looks writable and is
                    not (DESIGN.md §5 rule 12) — this is the same table the sheet shows.
                -->
                <table v-else class="w-full text-sm">
                    <caption class="sr-only">
                        {{ employeeName }}’s figures for {{ monthLabel }}, which can no longer be changed.
                    </caption>
                    <tbody>
                        <tr v-for="field in PAYROLL_ADJUSTABLE" :key="field" class="border-b border-border/60">
                            <th scope="row" class="py-2 pr-3 text-left font-normal text-muted-foreground">
                                {{ PAYROLL_FIELD_LONG_LABELS[field] }}
                            </th>
                            <td class="py-2 text-right tabular-nums">{{ formatMoney(item[field], currency) }}</td>
                        </tr>
                    </tbody>
                </table>

                <!--
                    The two read-only figures, and the explanation of the first one. A deduction
                    nobody can explain is the thing that generates the support ticket, so the
                    number of unpaid days and the number of payable days are both said out loud
                    — they are the two numbers the division was done with.
                -->
                <div class="flex min-w-0 flex-col gap-2 rounded-md border border-border bg-muted/40 p-4">
                    <div class="flex items-baseline justify-between gap-3">
                        <span class="text-sm text-muted-foreground">Leave impact</span>
                        <span class="text-sm tabular-nums">{{ formatMoney(item.leave_impact, currency) }}</span>
                    </div>
                    <p class="text-xs text-muted-foreground">{{ leaveSentence }}</p>

                    <div class="mt-2 flex items-baseline justify-between gap-3 border-t border-border pt-2">
                        <span class="text-sm font-medium">Net salary</span>
                        <span class="text-sm font-medium tabular-nums">
                            {{ formatMoney(item.net_salary, currency) }}
                        </span>
                    </div>
                    <p class="text-xs text-muted-foreground">
                        Worked out by the database: base plus allowance plus bonus, less deduction, advance and
                        leave impact. It updates when you save, not while you type.
                    </p>
                </div>

                <!--
                    Part D §14's "personal notes". It exists in this form only for somebody the
                    server sent the key to — an Admin. For everybody else there is no field, no
                    placeholder and no empty box.
                -->
                <div v-if="canAnnotate" class="flex flex-col gap-2">
                    <Label :for="notesId">Personal note</Label>
                    <Textarea
                        :id="notesId"
                        v-model="form.admin_notes"
                        rows="3"
                        placeholder="Why this line looks the way it does"
                    />
                    <p class="text-xs text-muted-foreground">
                        Admins only. It is not on the payslip, the Accountant never receives it, and neither does
                        {{ employeeName }}.
                    </p>
                    <p v-if="form.errors.admin_notes" class="text-xs text-destructive">
                        {{ form.errors.admin_notes }}
                    </p>
                </div>

                <DialogFooter>
                    <Button type="button" variant="outline" :disabled="form.processing" @click="emit('close')">
                        {{ canSubmit ? 'Cancel' : 'Close' }}
                    </Button>
                    <Button v-if="canSubmit" type="submit" :disabled="form.processing">
                        {{ form.processing ? 'Saving…' : 'Save this line' }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
