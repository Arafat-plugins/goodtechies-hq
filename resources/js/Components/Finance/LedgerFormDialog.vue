<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, useId, watch } from 'vue';
import type {
    FinanceCategory,
    FinanceCategoryKind,
    FinanceProject,
    IncomeRecord,
    LedgerRecord,
} from '@/Components/Finance/finance';
import { financeRoutes, formatMoney, ledgerNoun } from '@/Components/Finance/finance';
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
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Textarea } from '@/Components/ui/textarea';

/**
 * Record money, or correct a record of it. **One component for create and for edit, and one for
 * both sides of the ledger.**
 *
 * Create and edit are the same five fields with the same five rules — there is no field that
 * exists on one and not the other — so a second component would have been this file copied, and
 * the copy is the one that would not get the next rule. Income and expenses differ by exactly
 * one field, the project link Part D §20 gives an expense none of, so that field is dropped
 * rather than the component forked.
 *
 * ## The category picker cannot offer the wrong side
 *
 * `categories` arrives already filtered by the server — `FinanceService::categories(kind)` for
 * this form's own kind — so there is no client-side `filter()` here that could go stale, and
 * nothing this control can be set to is refusable on that ground. Beneath it, two more layers
 * say the same thing: `Store{Income,Expense}Request` scopes its `exists` rule to the kind, so a
 * hand-made request is a **422 on the field** rather than a 500, and the composite foreign key
 * on `(category_id, category_kind)` refuses it in the database whatever reaches it (decision
 * 8-2). The picker is the courtesy; the constraint is the promise.
 *
 * ## Where the state lives
 *
 * In the URL. This dialog is open because the page is at `/finance/income/create` or
 * `/finance/income/{id}/edit`, so a refresh keeps the form open, the back button closes it, and
 * a validation failure redirects to an address that renders it again with the server's errors
 * on it. Closing emits `close` and the page navigates back to the ledger — there is no local
 * "is the dialog open" flag to fall out of step with the address bar.
 *
 * Nothing here formats money except `formatMoney()`, and nothing here parses a date: the amount
 * input holds the raw decimal string the server sent and a native `type="date"` input holds the
 * server's own `YYYY-MM-DD`.
 */
const props = defineProps<{
    open: boolean;
    kind: FinanceCategoryKind;
    /** Null to add; a row to correct. */
    record: LedgerRecord | null;
    /** This side's categories, in picker order, already filtered by the server. */
    categories: FinanceCategory[];
    /** Income only. Empty on the expense form, which has no project link. */
    projects: FinanceProject[];
    currency: string;
    /** Today in the agency's timezone, from the server — the default date on a new record. */
    today: string;
}>();

const emit = defineEmits<{ close: [] }>();

const categoryId = useId();
const amountId = useId();
const dateId = useId();
const projectId = useId();
const notesId = useId();

/** reka's Select has no empty value, so "no project" is a sentinel rather than `''`. */
const NO_PROJECT = 'none';

const noun = computed(() => ledgerNoun(props.kind));
const isIncome = computed(() => props.kind === 'income');
const isEdit = computed(() => props.record !== null);

const form = useForm<{
    category_id: string;
    amount: string;
    date: string;
    notes: string;
    project_id: string;
}>({
    category_id: '',
    amount: '',
    date: '',
    notes: '',
    project_id: NO_PROJECT,
});

const title = computed(() =>
    isEdit.value ? `Edit this ${noun.value}` : isIncome.value ? 'Record income' : 'Record an expense',
);

/**
 * What the dialog says under its title.
 *
 * The edit copy names the consequence out loud: an edit moves the month's totals, and on a
 * ledger that is the point of making one. It also says the change is recorded, because Part D
 * §13 requires every edit to be audit-logged with old and new values and somebody entering a
 * correction deserves to know that before they save rather than after.
 */
const description = computed(() => {
    if (isEdit.value) {
        return `Changing the amount, the date or the category moves this ${noun.value} in the month’s totals. The change is recorded in the audit log with what it was and what it became.`;
    }

    return isIncome.value
        ? 'A payment in: what it was for, how much arrived, the day it arrived, and the project it belongs to where it belongs to one.'
        : 'A payment out: what it was for, how much went out and the day it went.';
});

/** "Buffalo Modular — SEO · buffalomodular.com". One line, two of the three keys. */
function projectLabel(project: FinanceProject): string {
    return project.domain ? `${project.name} · ${project.domain}` : project.name;
}

/** The amount as it will be filed, so a typo is visible before the save rather than after. */
const preview = computed(() => {
    const value = Number.parseFloat(form.amount);

    return Number.isFinite(value) && value > 0 ? formatMoney(value, props.currency) : null;
});

function submit(): void {
    const routes = isIncome.value ? financeRoutes.income : financeRoutes.expenses;

    // `transform` rather than a second source of truth: the sentinel becomes null on its way
    // out and the form fields stay strings, which is what the inputs hold.
    form.transform((data) => {
        const payload: Record<string, unknown> = {
            category_id: data.category_id,
            amount: data.amount,
            date: data.date,
            notes: data.notes,
        };

        if (isIncome.value) {
            payload.project_id = data.project_id === NO_PROJECT ? null : data.project_id;
        }

        return payload;
    });

    if (props.record) {
        form.put(routes.update(props.record.id), { preserveScroll: true });

        return;
    }

    form.post(routes.store(), { preserveScroll: true });
}

/**
 * Fill the form when the dialog opens, and clear the errors when it closes — so a cancelled
 * edit leaves neither a half-filled form nor somebody else's validation message behind.
 *
 * A new record defaults to **today**, from the server, rather than to the first of the month
 * being read: somebody adding a payment is nearly always adding today's.
 */
watch(
    () => [props.open, props.record?.id] as const,
    ([open]) => {
        form.clearErrors();

        if (!open) {
            return;
        }

        const record = props.record;

        form.category_id = record?.category ? String(record.category.id) : '';
        form.amount = record?.amount ?? '';
        form.date = record?.date ?? props.today;
        form.notes = record?.notes ?? '';

        const project = (record as IncomeRecord | null)?.project ?? null;
        form.project_id = project ? String(project.id) : NO_PROJECT;
    },
    { immediate: true },
);
</script>

<template>
    <Dialog :open="open" @update:open="(value: boolean) => { if (!value) { emit('close'); } }">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>{{ title }}</DialogTitle>
                <DialogDescription>{{ description }}</DialogDescription>
            </DialogHeader>

            <form class="flex min-w-0 flex-col gap-4" @submit.prevent="submit">
                <div class="flex flex-col gap-2">
                    <Label :for="categoryId">Category</Label>
                    <Select v-model="form.category_id">
                        <SelectTrigger :id="categoryId" class="w-full">
                            <SelectValue :placeholder="`Choose ${isIncome ? 'an income' : 'an expense'} category`" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="category in categories"
                                :key="category.id"
                                :value="String(category.id)"
                            >
                                {{ category.name }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <p v-if="form.errors.category_id" class="text-xs text-destructive">
                        {{ form.errors.category_id }}
                    </p>
                </div>

                <div class="flex min-w-0 flex-col gap-4 sm:flex-row">
                    <div class="flex min-w-0 flex-1 flex-col gap-2">
                        <Label :for="amountId">Amount</Label>
                        <Input
                            :id="amountId"
                            v-model="form.amount"
                            type="number"
                            inputmode="decimal"
                            step="0.01"
                            min="0.01"
                            required
                            class="tabular-nums"
                            placeholder="0.00"
                        />
                        <!--
                            The figure as it will be filed. Not decoration: it is where a
                            mistyped 86000 is caught, before the save rather than in next
                            month's total.
                        -->
                        <p v-if="preview" class="text-xs tabular-nums text-muted-foreground">
                            Filed as {{ preview }}
                        </p>
                        <p v-else class="text-xs text-muted-foreground">
                            {{ currency }}, greater than zero. Two decimal places.
                        </p>
                        <p v-if="form.errors.amount" class="text-xs text-destructive">
                            {{ form.errors.amount }}
                        </p>
                    </div>

                    <div class="flex min-w-0 flex-1 flex-col gap-2">
                        <Label :for="dateId">Date</Label>
                        <Input :id="dateId" v-model="form.date" type="date" required />
                        <p class="text-xs text-muted-foreground">
                            The month this {{ noun }} counts towards.
                        </p>
                        <p v-if="form.errors.date" class="text-xs text-destructive">
                            {{ form.errors.date }}
                        </p>
                    </div>
                </div>

                <!--
                    Income only. Part D §20 gives an expense no project column, so the expense
                    form has no picker rather than a disabled one (DESIGN.md §5 rule 12).

                    The options carry a project's name and its domain and nothing else — no
                    client, no contact, no status, no count. That is the whole key set this
                    payload has, for an Admin exactly as for the Accountant.
                -->
                <div v-if="isIncome" class="flex flex-col gap-2">
                    <Label :for="projectId">Project</Label>
                    <Select v-model="form.project_id">
                        <SelectTrigger :id="projectId" class="w-full">
                            <SelectValue placeholder="Not linked to a project" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem :value="NO_PROJECT">Not linked to a project</SelectItem>
                            <!--
                                One text node per option, not a two-line stack: reka's
                                `SelectValue` mirrors the selected item's text into the trigger,
                                and a stack there renders as one run-on word in a control one
                                line high. The domain rides on the same line, which is what
                                tells four "— Website Maintenance" projects apart (Part C §2).
                            -->
                            <SelectItem v-for="project in projects" :key="project.id" :value="String(project.id)">
                                {{ projectLabel(project) }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <p class="text-xs text-muted-foreground">
                        Optional. Linking a payment to a project is what makes the finance-by-project report add up.
                    </p>
                    <p v-if="form.errors.project_id" class="text-xs text-destructive">
                        {{ form.errors.project_id }}
                    </p>
                </div>

                <div class="flex flex-col gap-2">
                    <Label :for="notesId">Notes</Label>
                    <Textarea
                        :id="notesId"
                        v-model="form.notes"
                        rows="2"
                        maxlength="1000"
                        :placeholder="isIncome ? 'September SEO retainer' : 'Office rent share — September'"
                    />
                    <p v-if="form.errors.notes" class="text-xs text-destructive">
                        {{ form.errors.notes }}
                    </p>
                </div>

                <DialogFooter>
                    <Button type="button" variant="outline" @click="emit('close')">Cancel</Button>
                    <Button type="submit" :disabled="form.processing">
                        {{ isEdit ? `Save ${noun}` : `Record ${noun}` }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
