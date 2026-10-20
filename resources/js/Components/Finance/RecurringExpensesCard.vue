<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { Pencil, Plus, Repeat, Trash2 } from '@lucide/vue';
import { computed, ref, useId } from 'vue';
import type { CurrencyOption, FinanceCategory, FinanceProject, RecurringExpense } from '@/Components/Finance/finance';
import { financeRoutes, formatLedgerDate, formatMoney } from '@/Components/Finance/finance';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card, CardAction, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { Checkbox } from '@/Components/ui/checkbox';
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
 * Repeating expenses (a VPS every month, a domain every year): the list, and the form that sets
 * one up. Nothing is recorded on its own: a template that is due (`is_due`) shows "Record now",
 * and only that click turns it into an ordinary expense. "Skip" moves it on without recording.
 *
 * Money is rendered only through `formatMoney()` and dates only through `formatLedgerDate()`,
 * like every other finance screen.
 */
const props = defineProps<{
    items: RecurringExpense[];
    categories: FinanceCategory[];
    projects: FinanceProject[];
    /** The currencies a template may be kept in (`Currency::options()`). */
    currencies: CurrencyOption[];
    /** The currency a new template starts in. */
    defaultCurrency: string;
    /** Today in the agency's timezone, from the server — the default start date. */
    today: string;
    canManage: boolean;
}>();

/** reka's Select has no empty value, so "no project" is a sentinel rather than `''`. */
const NO_PROJECT = 'none';

const FREQUENCY_OPTIONS: { value: RecurringExpense['frequency']; label: string }[] = [
    { value: 'monthly', label: 'Monthly' },
    { value: 'quarterly', label: 'Quarterly' },
    { value: 'yearly', label: 'Yearly' },
];

const categoryId = useId();
const projectId = useId();
const currencyId = useId();
const amountId = useId();
const frequencyId = useId();
const startId = useId();
const endId = useId();
const notesId = useId();
const billableId = useId();
const activeId = useId();

/* ------------------------------------------------------------------ the form */

const formOpen = ref(false);
const editing = ref<RecurringExpense | null>(null);

const form = useForm<{
    category_id: string;
    project_id: string;
    amount: string;
    currency: string;
    frequency: RecurringExpense['frequency'];
    start_date: string;
    ends_on: string;
    notes: string;
    billable: boolean;
    active: boolean;
}>({
    category_id: '',
    project_id: NO_PROJECT,
    amount: '',
    currency: '',
    frequency: 'monthly',
    start_date: '',
    ends_on: '',
    notes: '',
    billable: false,
    active: true,
});

const title = computed(() => (editing.value ? 'Edit repeating expense' : 'Add repeating expense'));

const preview = computed(() => {
    const value = Number.parseFloat(form.amount);

    return Number.isFinite(value) && value > 0 ? formatMoney(value, form.currency) : null;
});

function projectLabel(project: FinanceProject): string {
    return project.domain ? `${project.name} · ${project.domain}` : project.name;
}

/**
 * D7: new templates are BDT only. The currency picker shows only when EDITING a template kept in
 * another currency, so a legacy USD one can still be corrected.
 */
const showCurrency = computed(() => editing.value !== null && editing.value.currency !== 'BDT');

function openCreate(): void {
    editing.value = null;
    form.clearErrors();
    form.category_id = '';
    form.project_id = NO_PROJECT;
    form.amount = '';
    form.currency = 'BDT';
    form.frequency = 'monthly';
    form.start_date = props.today;
    form.ends_on = '';
    form.notes = '';
    form.billable = false;
    form.active = true;
    formOpen.value = true;
}

function openEdit(item: RecurringExpense): void {
    editing.value = item;
    form.clearErrors();
    form.category_id = item.category ? String(item.category.id) : '';
    form.project_id = item.project ? String(item.project.id) : NO_PROJECT;
    form.amount = item.amount;
    form.currency = item.currency;
    form.frequency = item.frequency;
    form.start_date = item.start_date;
    form.ends_on = item.ends_on ?? '';
    form.notes = item.notes ?? '';
    form.billable = item.billable;
    form.active = item.active;
    formOpen.value = true;
}

function closeForm(): void {
    formOpen.value = false;
    form.clearErrors();
}

function submit(): void {
    form.transform((data) => ({
        category_id: data.category_id,
        project_id: data.project_id === NO_PROJECT ? null : data.project_id,
        amount: data.amount,
        currency: showCurrency.value ? data.currency : 'BDT',
        frequency: data.frequency,
        start_date: data.start_date,
        ends_on: data.ends_on === '' ? null : data.ends_on,
        notes: data.notes,
        billable: data.billable,
        active: data.active,
    }));

    const options = { preserveScroll: true, onSuccess: () => closeForm() };

    if (editing.value) {
        form.put(financeRoutes.recurringExpenses.update(editing.value.id), options);

        return;
    }

    form.post(financeRoutes.recurringExpenses.store(), options);
}

/* -------------------------------------------------------------------- deleting */

const pending = ref<RecurringExpense | null>(null);
const deleting = ref(false);

function askRemove(item: RecurringExpense): void {
    pending.value = item;
}

function cancelRemove(): void {
    if (!deleting.value) {
        pending.value = null;
    }
}

function confirmRemove(): void {
    const item = pending.value;

    if (!item || deleting.value) {
        return;
    }

    deleting.value = true;

    router.delete(financeRoutes.recurringExpenses.destroy(item.id), {
        preserveScroll: true,
        onFinish: () => {
            deleting.value = false;
            pending.value = null;
        },
    });
}

/* ------------------------------------------------------- recording and skipping */

/** Ids of the rows with a record or skip request in flight: their buttons stay disabled. */
const busy = ref<Set<number>>(new Set());

function isBusy(item: RecurringExpense): boolean {
    return busy.value.has(item.id);
}

function setBusy(id: number, value: boolean): void {
    const next = new Set(busy.value);

    if (value) {
        next.add(id);
    } else {
        next.delete(id);
    }

    busy.value = next;
}

function recordNow(item: RecurringExpense): void {
    if (isBusy(item)) {
        return;
    }

    router.post(financeRoutes.recurringExpenses.record(item.id), {}, {
        preserveScroll: true,
        onStart: () => setBusy(item.id, true),
        onFinish: () => setBusy(item.id, false),
    });
}

const skipping = ref<RecurringExpense | null>(null);

function askSkip(item: RecurringExpense): void {
    skipping.value = item;
}

function cancelSkip(): void {
    const item = skipping.value;

    if (!item || !isBusy(item)) {
        skipping.value = null;
    }
}

function confirmSkip(): void {
    const item = skipping.value;

    if (!item || isBusy(item)) {
        return;
    }

    router.post(financeRoutes.recurringExpenses.skip(item.id), {}, {
        preserveScroll: true,
        onStart: () => setBusy(item.id, true),
        onFinish: () => {
            setBusy(item.id, false);
            skipping.value = null;
        },
    });
}
</script>

<template>
    <Card class="min-w-0">
        <CardHeader>
            <CardTitle class="flex items-center gap-2">
                <Repeat class="size-4 text-muted-foreground" aria-hidden="true" />
                Repeating expenses
            </CardTitle>
            <CardDescription>
                Expenses that come back on a schedule — VPS, domain, hosting. They are never recorded on their own:
                when one is due it shows here, and it becomes an expense only when you record it.
            </CardDescription>
            <CardAction v-if="canManage">
                <Button size="sm" variant="outline" @click="openCreate">
                    <Plus aria-hidden="true" />
                    Add repeating expense
                </Button>
            </CardAction>
        </CardHeader>

        <CardContent>
            <p v-if="items.length === 0" class="text-sm text-muted-foreground">
                Nothing repeats yet. Add your VPS, hosting or domain renewals here.
            </p>

            <ul v-else class="flex min-w-0 flex-col divide-y divide-border">
                <li
                    v-for="item in items"
                    :key="item.id"
                    class="flex min-w-0 flex-col gap-2 py-3 first:pt-0 last:pb-0 sm:flex-row sm:items-center sm:justify-between"
                >
                    <div class="flex min-w-0 flex-col gap-1">
                        <div class="flex min-w-0 flex-wrap items-center gap-2">
                            <span class="truncate font-medium">{{ item.category?.name ?? 'Uncategorised' }}</span>
                            <Badge v-if="!item.active" variant="secondary">Paused</Badge>
                            <Badge v-if="item.is_due" variant="secondary">Due {{ formatLedgerDate(item.next_due_date) }}</Badge>
                            <Badge v-if="item.billable" variant="outline">Billable</Badge>
                        </div>
                        <p class="truncate text-xs text-muted-foreground">
                            {{ item.project?.name ?? '—' }}
                        </p>
                    </div>

                    <div class="flex min-w-0 flex-wrap items-center gap-x-4 gap-y-1 sm:justify-end">
                        <span class="font-medium tabular-nums">{{ formatMoney(item.amount, item.currency) }}</span>
                        <span class="text-sm text-muted-foreground">{{ item.frequency_label }}</span>
                        <span v-if="!item.is_due" class="text-sm tabular-nums text-muted-foreground">
                            Next: {{ formatLedgerDate(item.next_due_date) }}
                        </span>
                        <div v-if="canManage && item.is_due" class="flex items-center gap-1">
                            <Button size="sm" :disabled="isBusy(item)" @click="recordNow(item)">Record now</Button>
                            <Button size="sm" variant="ghost" :disabled="isBusy(item)" @click="askSkip(item)">Skip</Button>
                        </div>
                        <div v-if="canManage" class="flex items-center gap-1">
                            <Button
                                size="icon"
                                variant="ghost"
                                :aria-label="`Edit ${item.category?.name ?? 'repeating expense'}`"
                                @click="openEdit(item)"
                            >
                                <Pencil aria-hidden="true" />
                            </Button>
                            <Button
                                size="icon"
                                variant="ghost"
                                :aria-label="`Delete ${item.category?.name ?? 'repeating expense'}`"
                                @click="askRemove(item)"
                            >
                                <Trash2 aria-hidden="true" />
                            </Button>
                        </div>
                    </div>
                </li>
            </ul>
        </CardContent>
    </Card>

    <Dialog :open="formOpen" @update:open="(value: boolean) => { if (!value) { closeForm(); } }">
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>{{ title }}</DialogTitle>
                <DialogDescription>
                    It falls due on the start date and on every date it repeats after that, until the
                    end date if there is one. Each time it is due, record it from this list.
                </DialogDescription>
            </DialogHeader>

            <form class="flex min-w-0 flex-col gap-4" @submit.prevent="submit">
                <div class="flex flex-col gap-2">
                    <Label :for="categoryId">Category</Label>
                    <Select v-model="form.category_id">
                        <SelectTrigger :id="categoryId" class="w-full">
                            <SelectValue placeholder="Choose an expense category" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem v-for="category in categories" :key="category.id" :value="String(category.id)">
                                {{ category.name }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <p v-if="form.errors.category_id" class="text-xs text-destructive">{{ form.errors.category_id }}</p>
                </div>

                <div class="flex flex-col gap-2">
                    <Label :for="projectId">Project</Label>
                    <Select v-model="form.project_id">
                        <SelectTrigger :id="projectId" class="w-full">
                            <SelectValue placeholder="Not linked to a project" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem :value="NO_PROJECT">Not linked to a project</SelectItem>
                            <SelectItem v-for="project in projects" :key="project.id" :value="String(project.id)">
                                {{ projectLabel(project) }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <p v-if="form.errors.project_id" class="text-xs text-destructive">{{ form.errors.project_id }}</p>
                </div>

                <div class="flex min-w-0 flex-col gap-4 sm:flex-row">
                    <div v-if="showCurrency" class="flex min-w-0 flex-col gap-2 sm:w-40">
                        <Label :for="currencyId">Currency</Label>
                        <Select v-model="form.currency">
                            <SelectTrigger :id="currencyId" class="w-full">
                                <SelectValue placeholder="Choose a currency" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem v-for="option in currencies" :key="option.value" :value="option.value">
                                    {{ option.label }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <p v-if="form.errors.currency" class="text-xs text-destructive">{{ form.errors.currency }}</p>
                    </div>

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
                        <p v-if="preview" class="text-xs tabular-nums text-muted-foreground">Each time: {{ preview }}</p>
                        <p v-if="form.errors.amount" class="text-xs text-destructive">{{ form.errors.amount }}</p>
                    </div>

                    <div class="flex min-w-0 flex-1 flex-col gap-2">
                        <Label :for="frequencyId">Repeats</Label>
                        <Select v-model="form.frequency">
                            <SelectTrigger :id="frequencyId" class="w-full">
                                <SelectValue placeholder="How often" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem v-for="option in FREQUENCY_OPTIONS" :key="option.value" :value="option.value">
                                    {{ option.label }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <p v-if="form.errors.frequency" class="text-xs text-destructive">{{ form.errors.frequency }}</p>
                    </div>
                </div>

                <div class="flex min-w-0 flex-col gap-4 sm:flex-row">
                    <div class="flex min-w-0 flex-1 flex-col gap-2">
                        <Label :for="startId">Start date</Label>
                        <Input :id="startId" v-model="form.start_date" type="date" required />
                        <p class="text-xs text-muted-foreground">The first date it is due.</p>
                        <p v-if="form.errors.start_date" class="text-xs text-destructive">{{ form.errors.start_date }}</p>
                    </div>

                    <div class="flex min-w-0 flex-1 flex-col gap-2">
                        <Label :for="endId">Ends on</Label>
                        <Input :id="endId" v-model="form.ends_on" type="date" :min="form.start_date || undefined" />
                        <p class="text-xs text-muted-foreground">Optional. Leave empty to repeat until paused.</p>
                        <p v-if="form.errors.ends_on" class="text-xs text-destructive">{{ form.errors.ends_on }}</p>
                    </div>
                </div>

                <div class="flex flex-col gap-2">
                    <Label :for="notesId">Notes</Label>
                    <Textarea :id="notesId" v-model="form.notes" rows="2" maxlength="2000" placeholder="Hetzner VPS for client sites" />
                    <p v-if="form.errors.notes" class="text-xs text-destructive">{{ form.errors.notes }}</p>
                </div>

                <div class="flex items-start gap-2">
                    <Checkbox :id="billableId" :model-value="form.billable" @update:model-value="form.billable = $event === true" />
                    <div class="flex flex-col gap-0.5">
                        <Label :for="billableId">Billable to the client</Label>
                        <p class="text-xs text-muted-foreground">Tick when the client pays this back.</p>
                    </div>
                </div>

                <div class="flex items-start gap-2">
                    <Checkbox :id="activeId" :model-value="form.active" @update:model-value="form.active = $event === true" />
                    <div class="flex flex-col gap-0.5">
                        <Label :for="activeId">Active</Label>
                        <p class="text-xs text-muted-foreground">Untick to pause it without deleting it.</p>
                    </div>
                </div>

                <DialogFooter>
                    <Button type="button" variant="outline" @click="closeForm">Cancel</Button>
                    <Button type="submit" :disabled="form.processing">Save</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <Dialog :open="pending !== null" @update:open="(value: boolean) => { if (!value) { cancelRemove(); } }">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Delete this repeating expense?</DialogTitle>
                <DialogDescription>
                    <template v-if="pending">
                        {{ pending.category?.name ?? 'This expense' }} of {{ formatMoney(pending.amount, pending.currency) }},
                        {{ pending.frequency_label.toLowerCase() }}, will stop coming due. Expenses already
                        recorded from it stay in the ledger.
                    </template>
                </DialogDescription>
            </DialogHeader>
            <DialogFooter>
                <Button type="button" variant="outline" :disabled="deleting" @click="cancelRemove">Cancel</Button>
                <Button type="button" variant="destructive" :disabled="deleting" @click="confirmRemove">Delete</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <Dialog :open="skipping !== null" @update:open="(value: boolean) => { if (!value) { cancelSkip(); } }">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Skip this repeating expense?</DialogTitle>
                <DialogDescription>
                    <template v-if="skipping">
                        Skip {{ skipping.category?.name ?? 'this expense' }} this time? No expense is recorded, and it
                        will next be due after {{ formatLedgerDate(skipping.next_due_date) }}.
                    </template>
                </DialogDescription>
            </DialogHeader>
            <DialogFooter>
                <Button type="button" variant="outline" :disabled="skipping !== null && isBusy(skipping)" @click="cancelSkip">
                    Cancel
                </Button>
                <Button type="button" :disabled="skipping !== null && isBusy(skipping)" @click="confirmSkip">Skip</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
