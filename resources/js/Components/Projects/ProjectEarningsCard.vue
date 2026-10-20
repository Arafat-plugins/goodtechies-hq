<script lang="ts">
export interface ProjectEarningEntryRow {
    id: number;
    kind: string;
    kind_label: string;
    amount: string;
    currency: string;
    date: string;
    description: string | null;
    recorded_by: string | null;
    updated_at: string | null;
}

export interface ProjectEarningTotals {
    currency: string;
    contract_value: string;
    earned: string;
    received: string;
}

export interface ProjectEarningKindOption {
    value: string;
    label: string;
    description: string;
}

export interface CurrencyOption {
    value: string;
    label: string;
}
</script>

<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { Pencil, Plus, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import { formatLedgerDate, formatMoney } from '@/Components/Finance/finance';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card, CardAction, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
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

const props = defineProps<{
    projectId: number;
    entries: ProjectEarningEntryRow[];
    totals: ProjectEarningTotals[];
    kinds: ProjectEarningKindOption[];
    currencies: CurrencyOption[];
    defaultCurrency: string;
    canEdit: boolean;
}>();

/** The three kinds, in the order the totals show them. */
const TOTAL_KINDS = [
    { key: 'contract_value', label: 'Contract value' },
    { key: 'earned', label: 'Earned' },
    { key: 'received', label: 'Received' },
] as const;

/** Today in the viewer's own calendar, as `YYYY-MM-DD`. */
function today(): string {
    const now = new Date();
    const month = String(now.getMonth() + 1).padStart(2, '0');
    const day = String(now.getDate()).padStart(2, '0');

    return `${now.getFullYear()}-${month}-${day}`;
}

const firstKind = computed(() => props.kinds[0]?.value ?? 'contract_value');

/* ------------------------------------------------------------------ create / edit */

const formOpen = ref(false);
const editing = ref<ProjectEarningEntryRow | null>(null);

const form = useForm({
    kind: firstKind.value,
    currency: props.defaultCurrency,
    amount: '' as string | number,
    date: today(),
    description: '',
});

const selectedKind = computed(() => props.kinds.find((kind) => kind.value === form.kind) ?? null);

function openCreate(): void {
    editing.value = null;
    form.clearErrors();
    form.kind = firstKind.value;
    form.currency = props.defaultCurrency;
    form.amount = '';
    form.date = today();
    form.description = '';
    formOpen.value = true;
}

function openEdit(entry: ProjectEarningEntryRow): void {
    editing.value = entry;
    form.clearErrors();
    form.kind = entry.kind;
    form.currency = entry.currency;
    form.amount = entry.amount;
    form.date = entry.date;
    form.description = entry.description ?? '';
    formOpen.value = true;
}

function submit(): void {
    if (form.processing) {
        return;
    }

    form.transform((data) => ({
        ...data,
        amount: String(data.amount).trim(),
        description: data.description.trim() === '' ? null : data.description.trim(),
    }));

    const options = {
        preserveScroll: true,
        onSuccess: () => {
            formOpen.value = false;
        },
    };

    if (editing.value) {
        form.put(`/admin/projects/${props.projectId}/earnings/${editing.value.id}`, options);
    } else {
        form.post(`/admin/projects/${props.projectId}/earnings`, options);
    }
}

/* ------------------------------------------------------------------ delete */

const deleting = ref<ProjectEarningEntryRow | null>(null);
const deleteProcessing = ref(false);

const deleteOpen = computed({
    get: () => deleting.value !== null,
    set: (open: boolean) => {
        if (!open && !deleteProcessing.value) {
            deleting.value = null;
        }
    },
});

function confirmDelete(entry: ProjectEarningEntryRow): void {
    deleting.value = entry;
}

function destroy(): void {
    const entry = deleting.value;

    if (!entry || deleteProcessing.value) {
        return;
    }

    router.delete(`/admin/projects/${props.projectId}/earnings/${entry.id}`, {
        preserveScroll: true,
        onStart: () => {
            deleteProcessing.value = true;
        },
        onFinish: () => {
            deleteProcessing.value = false;
            deleting.value = null;
        },
    });
}
</script>

<template>
    <Card class="min-w-0 gap-4">
        <CardHeader>
            <CardTitle class="text-sm font-medium">Project financials</CardTitle>
            <CardDescription>
                Contract value, earned and received amounts recorded for this project. These are kept here only —
                they are not added to Income, and completing the project records nothing by itself.
            </CardDescription>
            <CardAction v-if="canEdit">
                <Button type="button" size="sm" @click="openCreate">
                    <Plus aria-hidden="true" />
                    Record earning
                </Button>
            </CardAction>
        </CardHeader>

        <CardContent class="flex flex-col gap-4">
            <div v-if="totals.length > 0" class="flex flex-col gap-3">
                <dl
                    v-for="total in totals"
                    :key="total.currency"
                    class="grid gap-3 rounded-lg border p-3 sm:grid-cols-3"
                    :aria-label="`Totals in ${total.currency}`"
                >
                    <div v-for="kind in TOTAL_KINDS" :key="kind.key" class="flex min-w-0 flex-col gap-1">
                        <dt class="text-xs text-muted-foreground">{{ kind.label }} ({{ total.currency }})</dt>
                        <dd class="text-base font-medium tabular-nums">
                            {{ formatMoney(total[kind.key], total.currency) }}
                        </dd>
                    </div>
                </dl>
                <p class="text-xs text-muted-foreground">
                    Amounts in different currencies are shown separately and never added together.
                </p>
            </div>

            <template v-if="entries.length > 0">
                <table class="hidden w-full text-sm sm:table">
                    <thead>
                        <tr class="border-b text-left text-xs text-muted-foreground">
                            <th scope="col" class="py-2 pr-3 font-medium">Date</th>
                            <th scope="col" class="py-2 pr-3 font-medium">Kind</th>
                            <th scope="col" class="py-2 pr-3 text-right font-medium">Amount</th>
                            <th scope="col" class="py-2 pr-3 font-medium">Description</th>
                            <th v-if="canEdit" scope="col" class="py-2 text-right font-medium">
                                <span class="sr-only">Actions</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="entry in entries" :key="entry.id" class="border-b last:border-b-0">
                            <td class="py-2 pr-3 whitespace-nowrap tabular-nums">{{ formatLedgerDate(entry.date) }}</td>
                            <td class="py-2 pr-3">
                                <Badge variant="secondary">{{ entry.kind_label }}</Badge>
                            </td>
                            <td class="py-2 pr-3 text-right whitespace-nowrap tabular-nums">
                                {{ formatMoney(entry.amount, entry.currency) }}
                            </td>
                            <td class="py-2 pr-3 break-words whitespace-pre-wrap text-muted-foreground">
                                {{ entry.description ?? '—' }}
                            </td>
                            <td v-if="canEdit" class="py-2 text-right whitespace-nowrap">
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    :aria-label="`Edit ${entry.kind_label} of ${formatMoney(entry.amount, entry.currency)}`"
                                    @click="openEdit(entry)"
                                >
                                    <Pencil aria-hidden="true" />
                                    Edit
                                </Button>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    :aria-label="`Delete ${entry.kind_label} of ${formatMoney(entry.amount, entry.currency)}`"
                                    @click="confirmDelete(entry)"
                                >
                                    <Trash2 aria-hidden="true" />
                                    Delete
                                </Button>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <ul class="flex flex-col gap-3 sm:hidden">
                    <li v-for="entry in entries" :key="entry.id" class="flex flex-col gap-2 rounded-lg border p-3">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <Badge variant="secondary">{{ entry.kind_label }}</Badge>
                            <span class="text-xs text-muted-foreground tabular-nums">{{ formatLedgerDate(entry.date) }}</span>
                        </div>
                        <p class="text-base font-medium tabular-nums">{{ formatMoney(entry.amount, entry.currency) }}</p>
                        <p v-if="entry.description" class="text-sm break-words whitespace-pre-wrap text-muted-foreground">
                            {{ entry.description }}
                        </p>
                        <div v-if="canEdit" class="flex flex-wrap gap-2">
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                :aria-label="`Edit ${entry.kind_label} of ${formatMoney(entry.amount, entry.currency)}`"
                                @click="openEdit(entry)"
                            >
                                <Pencil aria-hidden="true" />
                                Edit
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                :aria-label="`Delete ${entry.kind_label} of ${formatMoney(entry.amount, entry.currency)}`"
                                @click="confirmDelete(entry)"
                            >
                                <Trash2 aria-hidden="true" />
                                Delete
                            </Button>
                        </div>
                    </li>
                </ul>
            </template>

            <div v-else class="flex flex-col items-start gap-3 rounded-lg border border-dashed p-4">
                <p class="text-sm text-muted-foreground">No earnings recorded for this project yet.</p>
                <Button v-if="canEdit" type="button" variant="outline" size="sm" @click="openCreate">
                    <Plus aria-hidden="true" />
                    Record earning
                </Button>
            </div>
        </CardContent>

        <Dialog v-model:open="formOpen">
            <DialogContent class="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{{ editing ? 'Edit earning' : 'Record earning' }}</DialogTitle>
                    <DialogDescription>
                        Recorded on this project only. Nothing is added to Income.
                    </DialogDescription>
                </DialogHeader>

                <form class="flex flex-col gap-4" novalidate @submit.prevent="submit">
                    <div class="flex min-w-0 flex-col gap-2">
                        <Label for="earning-kind">Kind</Label>
                        <Select v-model="form.kind" :disabled="form.processing">
                            <SelectTrigger
                                id="earning-kind"
                                class="w-full"
                                :aria-invalid="form.errors.kind ? true : undefined"
                                aria-describedby="earning-kind-help"
                            >
                                <SelectValue placeholder="Choose a kind" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem v-for="kind in kinds" :key="kind.value" :value="kind.value">
                                    {{ kind.label }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <p id="earning-kind-help" class="text-xs text-muted-foreground">
                            {{ selectedKind?.description }}
                        </p>
                        <p v-if="form.errors.kind" class="text-xs text-destructive">{{ form.errors.kind }}</p>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="flex min-w-0 flex-col gap-2">
                            <Label for="earning-currency">Currency</Label>
                            <Select v-model="form.currency" :disabled="form.processing">
                                <SelectTrigger
                                    id="earning-currency"
                                    class="w-full"
                                    :aria-invalid="form.errors.currency ? true : undefined"
                                >
                                    <SelectValue placeholder="Choose a currency" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem v-for="currency in currencies" :key="currency.value" :value="currency.value">
                                        {{ currency.label }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <p v-if="form.errors.currency" class="text-xs text-destructive">{{ form.errors.currency }}</p>
                        </div>

                        <div class="flex min-w-0 flex-col gap-2">
                            <Label for="earning-amount">Amount</Label>
                            <Input
                                id="earning-amount"
                                v-model="form.amount"
                                type="number"
                                step="0.01"
                                min="0.01"
                                inputmode="decimal"
                                required
                                :disabled="form.processing"
                                :aria-invalid="form.errors.amount ? true : undefined"
                            />
                            <p v-if="form.errors.amount" class="text-xs text-destructive">{{ form.errors.amount }}</p>
                        </div>
                    </div>

                    <div class="flex min-w-0 flex-col gap-2">
                        <Label for="earning-date">Date</Label>
                        <Input
                            id="earning-date"
                            v-model="form.date"
                            type="date"
                            required
                            :disabled="form.processing"
                            :aria-invalid="form.errors.date ? true : undefined"
                        />
                        <p v-if="form.errors.date" class="text-xs text-destructive">{{ form.errors.date }}</p>
                    </div>

                    <div class="flex min-w-0 flex-col gap-2">
                        <Label for="earning-description">Description</Label>
                        <Textarea
                            id="earning-description"
                            v-model="form.description"
                            rows="3"
                            maxlength="1000"
                            :disabled="form.processing"
                            :aria-invalid="form.errors.description ? true : undefined"
                        />
                        <p v-if="form.errors.description" class="text-xs text-destructive">
                            {{ form.errors.description }}
                        </p>
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="outline" :disabled="form.processing" @click="formOpen = false">
                            Cancel
                        </Button>
                        <Button type="submit" :disabled="form.processing">
                            {{ form.processing ? 'Saving…' : 'Save' }}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="deleteOpen">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Delete earning</DialogTitle>
                    <DialogDescription v-if="deleting">
                        Delete this {{ deleting.kind_label }} of {{ formatMoney(deleting.amount, deleting.currency) }}? This
                        cannot be undone. The audit log keeps a copy.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button type="button" variant="outline" :disabled="deleteProcessing" @click="deleteOpen = false">
                        Cancel
                    </Button>
                    <Button type="button" variant="destructive" :disabled="deleteProcessing" @click="destroy">
                        {{ deleteProcessing ? 'Deleting…' : 'Delete' }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </Card>
</template>
