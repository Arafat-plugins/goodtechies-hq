<script setup lang="ts">
import { BadgeCheck, Lock } from '@lucide/vue';
import { computed } from 'vue';
import type { PayrollConfirmKind } from '@/Components/Payroll/payroll';
import { Button } from '@/Components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/Components/ui/dialog';

/**
 * The two confirmations that close a month: **Lock**, and **Mark paid**.
 *
 * Both are here rather than in two files because they are the same dialog with different words,
 * and the words are the whole of each one. What differs is how far the consequence reaches —
 * and that difference is stated in the copy, deliberately, rather than implied by a colour:
 *
 *   - **Lock** closes the month to the finance ledger and **can be reversed by an Admin**,
 *     with a reason, which the dialog says out loud so that nobody treats it as the point of
 *     no return.
 *   - **Mark paid IS the point of no return**, and the dialog says so in words. `paid` is
 *     terminal — `PayrollStatus::TRANSITIONS['paid']` is empty — and both `locked` and `paid`
 *     close the ledger while only a lock is reversible, so a month that has been paid can
 *     never be reopened to finance. That is not softened here, is not called *archiving*, and
 *     is not hidden behind "are you sure?". It is the one sentence somebody needs before they
 *     press it, and it is the first one they read. (Decision 9-17 has this in front of the
 *     client at GATE E precisely because the consequence is this large.)
 *
 * Focus is the caller's job: reka restores focus to whatever held it when the dialog opened,
 * and the pages that mount this return it to the named control explicitly, because a confirmed
 * move closes the dialog by *navigating* and no close handler runs on that path (the shape
 * decision 5-20 describes, arrived at from the other direction).
 */
const props = defineProps<{
    /** 'lock' or 'paid' when open; null when closed. */
    kind: Exclude<PayrollConfirmKind, 'none' | 'reverse'> | null;
    /** "September 2026". */
    monthLabel: string;
    /** True while the POST is in flight, so nothing can be pressed twice. */
    working: boolean;
}>();

const emit = defineEmits<{ confirm: []; cancel: [] }>();

const isPaid = computed(() => props.kind === 'paid');

const title = computed(() =>
    isPaid.value ? `Mark ${props.monthLabel} as paid?` : `Lock ${props.monthLabel}?`,
);
</script>

<template>
    <Dialog :open="kind !== null" @update:open="(open: boolean) => { if (!open) { emit('cancel'); } }">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>{{ title }}</DialogTitle>
                <DialogDescription>
                    <template v-if="isPaid">
                        This is the last move {{ monthLabel }} can make. Paid is final: the payslips are
                        released, and the month can never be reopened to finance afterwards. Every income and
                        expense dated in {{ monthLabel }} stays closed for good — created, edited, moved in,
                        moved out or deleted are all refused from here on, and no Admin can undo it. If a figure
                        on this month is wrong, fix it before you press this; afterwards the only route is a
                        correction in a later month.
                    </template>
                    <template v-else>
                        Locking closes {{ monthLabel }} to the finance ledger: no income or expense dated in it
                        can be recorded, edited, moved in, moved out or deleted while it is locked. The figures
                        on every line stop being editable too. An Admin can reverse this, with a reason that is
                        written to the audit log — so this is a closed door, not a final one.
                    </template>
                </DialogDescription>
            </DialogHeader>
            <DialogFooter>
                <Button type="button" variant="outline" :disabled="working" @click="emit('cancel')">
                    {{ isPaid ? 'Not yet' : 'Keep it open' }}
                </Button>
                <Button
                    type="button"
                    :variant="isPaid ? 'destructive' : 'default'"
                    :disabled="working"
                    @click="emit('confirm')"
                >
                    <BadgeCheck v-if="isPaid" aria-hidden="true" />
                    <Lock v-else aria-hidden="true" />
                    <template v-if="working">Working…</template>
                    <template v-else-if="isPaid">Mark paid — this is final</template>
                    <template v-else>Lock the month</template>
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
