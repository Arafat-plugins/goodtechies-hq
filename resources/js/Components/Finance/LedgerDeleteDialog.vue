<script setup lang="ts">
import { Trash2 } from '@lucide/vue';
import { computed } from 'vue';
import type { FinanceCategoryKind, LedgerRecord } from '@/Components/Finance/finance';
import { formatMonthLabel, ledgerDeletePrompt, ledgerNoun } from '@/Components/Finance/finance';
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
 * The confirmation before a finance record is destroyed.
 *
 * **It is a hard delete** (decision 8-5): there is no `deleted_at` on `income` or `expenses`,
 * the row is gone from the table and from every total that reads it, and what survives is the
 * audit row — which carries the whole record, including the category's and the project's names
 * as they were, so it can be re-inserted verbatim. That is a stronger guarantee than a
 * `deleted_at` column would be, because `audit_logs` is the one table the runtime database role
 * cannot UPDATE, DELETE or TRUNCATE.
 *
 * So the wording says **deleted**. It does not say archived and it does not say hidden, because
 * neither is true and a person deciding whether to press this has to be told what actually
 * happens. It says out loud that the month's totals move, because on a ledger that is the whole
 * consequence, and it names the record and its amount — a month of a dozen rows several of
 * which are called *Maintenance* is a list where "Are you sure?" is not a question anybody can
 * answer.
 *
 * Focus is the caller's job. reka restores focus to whatever held it when the dialog opened,
 * which here was a `DropdownMenuItem` the `⋯` menu has already unmounted — decision 5-20, which
 * drops a keyboard user at the top of the document. The pages that mount this find the visible
 * `⋯` trigger again by its accessible name and focus it explicitly, and fall back to the page's
 * own Add control when the row itself has just gone.
 */
const props = defineProps<{
    kind: FinanceCategoryKind;
    /** The row being deleted, or null when the dialog is closed. */
    record: LedgerRecord | null;
    currency: string;
    /** True while the DELETE is in flight, so the buttons cannot be pressed twice. */
    deleting: boolean;
}>();

const emit = defineEmits<{ confirm: []; cancel: [] }>();

const noun = computed(() => ledgerNoun(props.kind));

const title = computed(() =>
    props.record ? ledgerDeletePrompt(props.kind, props.record, props.currency) : `Delete this ${noun.value}?`,
);

/** The month this row counts towards, from its own date. */
const monthLabel = computed(() =>
    props.record ? formatMonthLabel(props.record.date.slice(0, 7)) : '',
);
</script>

<template>
    <Dialog :open="record !== null" @update:open="(open: boolean) => { if (!open) { emit('cancel'); } }">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>{{ title }}</DialogTitle>
                <DialogDescription>
                    This deletes the row outright. It is not archived and it is not hidden, and it cannot be
                    undone — {{ monthLabel }}’s totals change with it, here and on the finance dashboard. What
                    remains is the audit log entry, which keeps the whole record.
                </DialogDescription>
            </DialogHeader>
            <DialogFooter>
                <Button type="button" variant="outline" :disabled="deleting" @click="emit('cancel')">
                    Keep it
                </Button>
                <Button type="button" variant="destructive" :disabled="deleting" @click="emit('confirm')">
                    <Trash2 aria-hidden="true" />
                    {{ deleting ? 'Deleting…' : `Delete ${noun}` }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
