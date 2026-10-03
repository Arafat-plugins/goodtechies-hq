<script lang="ts">
import type { Project, ProjectFinance } from '@/Components/Projects/ProjectForm.vue';

/**
 * There is no currency prop on this payload yet, so USD is hard-coded here — one helper,
 * shared by every project surface that shows money. Move it to a shared place when a
 * currency arrives.
 */
const MONEY = new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD',
    minimumFractionDigits: 0,
    maximumFractionDigits: 2,
});

export function formatMoney(amount: string | number | null | undefined): string | null {
    if (amount === null || amount === undefined || amount === '') {
        return null;
    }

    const value = typeof amount === 'number' ? amount : Number(amount);

    return Number.isFinite(value) ? MONEY.format(value) : null;
}

/** An hourly rate always shows its cents: "$40.00/hr", not "$40/hr". */
const RATE = new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD',
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
});

export function formatRate(amount: string | number | null | undefined): string | null {
    if (amount === null || amount === undefined || amount === '') {
        return null;
    }

    const value = typeof amount === 'number' ? amount : Number(amount);

    return Number.isFinite(value) ? RATE.format(value) : null;
}

/** The suffix a recurring amount carries; custom or no frequency keeps the historic `/mo`. */
const RECURRING_SUFFIX: Record<string, string> = {
    monthly: '/mo',
    quarterly: '/qtr',
    yearly: '/yr',
};

/**
 * One line of money per project: an hourly rate, else the recurring amount per period, else the
 * one-off price. Absent finance renders nothing at all.
 */
export function moneyLine(finance: ProjectFinance | null | undefined): string | null {
    if (finance?.billing_frequency === 'hourly') {
        const rate = formatRate(finance.hourly_rate);

        if (rate) {
            return `${rate}/hr`;
        }
    }

    const recurring = formatMoney(finance?.recurring_amount);

    if (recurring) {
        const suffix = RECURRING_SUFFIX[finance?.billing_frequency ?? ''] ?? '/mo';

        return `${recurring}${suffix}`;
    }

    return formatMoney(finance?.price);
}
</script>

<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Pencil } from '@lucide/vue';
import { computed, ref } from 'vue';
import {
    moneyInputValue,
    NO_FREQUENCY,
    type Option,
} from '@/Components/Projects/ProjectForm.vue';
import { Button } from '@/Components/ui/button';
import { Card, CardAction, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Textarea } from '@/Components/ui/textarea';

const props = defineProps<{
    project: Project;
    billingFrequencies: Option[];
    /** False hides the Edit button — the payload is read-only for this viewer. */
    canEdit: boolean;
}>();

const finance = computed(() => props.project.finance ?? null);

const editing = ref(false);

const form = useForm({
    price: moneyInputValue(finance.value?.price),
    recurring_amount: moneyInputValue(finance.value?.recurring_amount),
    billing_frequency: finance.value?.billing_frequency ?? NO_FREQUENCY,
    hourly_rate: moneyInputValue(finance.value?.hourly_rate),
    contract_value: moneyInputValue(finance.value?.contract_value),
    contract_terms: finance.value?.contract_terms ?? '',
    profitability_snapshot: moneyInputValue(finance.value?.profitability_snapshot),
});

/** The rate is asked for only while the frequency is Hourly. */
const isHourly = computed(() => form.billing_frequency === 'hourly');

/** A `type="number"` v-model yields a number, so the value is stringified before trimming. */
function blankToNull(value: string | number | null | undefined): string | null {
    if (value === null || value === undefined) {
        return null;
    }

    const text = String(value).trim();

    return text === '' ? null : text;
}

function startEditing(): void {
    form.clearErrors();
    form.price = moneyInputValue(finance.value?.price);
    form.recurring_amount = moneyInputValue(finance.value?.recurring_amount);
    form.billing_frequency = finance.value?.billing_frequency ?? NO_FREQUENCY;
    form.hourly_rate = moneyInputValue(finance.value?.hourly_rate);
    form.contract_value = moneyInputValue(finance.value?.contract_value);
    form.contract_terms = finance.value?.contract_terms ?? '';
    form.profitability_snapshot = moneyInputValue(finance.value?.profitability_snapshot);
    editing.value = true;
}

function submit(): void {
    if (form.processing) {
        return;
    }

    form.transform((data) => ({
        price: blankToNull(data.price),
        recurring_amount: blankToNull(data.recurring_amount),
        billing_frequency: data.billing_frequency === NO_FREQUENCY ? null : data.billing_frequency,
        hourly_rate: data.billing_frequency === 'hourly' ? blankToNull(data.hourly_rate) : null,
        contract_value: blankToNull(data.contract_value),
        contract_terms: blankToNull(data.contract_terms),
        profitability_snapshot: blankToNull(data.profitability_snapshot),
    }));

    form.put(`/admin/projects/${props.project.id}/finance`, {
        preserveScroll: true,
        onSuccess: () => {
            editing.value = false;
        },
    });
}

/** Label / value pairs for the read-only view; a null value shows an em dash. */
const rows = computed(() => [
    { label: 'Price', value: formatMoney(finance.value?.price) },
    { label: 'Recurring amount', value: formatMoney(finance.value?.recurring_amount) },
    { label: 'Billing frequency', value: finance.value?.billing_frequency_label ?? null },
    ...(blankToNull(finance.value?.hourly_rate) === null
        ? []
        : [{ label: 'Hourly rate', value: formatRate(finance.value?.hourly_rate) }]),
    { label: 'Contract value', value: formatMoney(finance.value?.contract_value) },
    { label: 'Profitability snapshot', value: formatMoney(finance.value?.profitability_snapshot) },
]);
</script>

<template>
    <Card class="min-w-0 gap-4">
        <CardHeader>
            <CardTitle class="text-sm font-medium">Finance</CardTitle>
            <CardDescription>What this project bills, and on what terms.</CardDescription>
            <CardAction v-if="canEdit && !editing">
                <Button type="button" variant="outline" size="sm" @click="startEditing">
                    <Pencil aria-hidden="true" />
                    Edit
                </Button>
            </CardAction>
        </CardHeader>

        <CardContent>
            <template v-if="!editing">
                <dl class="grid gap-x-4 gap-y-3 sm:grid-cols-2">
                    <div v-for="row in rows" :key="row.label" class="flex min-w-0 flex-col gap-1">
                        <dt class="text-xs text-muted-foreground">{{ row.label }}</dt>
                        <dd class="text-sm tabular-nums">{{ row.value ?? '—' }}</dd>
                    </div>
                    <div class="flex min-w-0 flex-col gap-1 sm:col-span-2">
                        <dt class="text-xs text-muted-foreground">Contract terms</dt>
                        <dd class="text-sm break-words whitespace-pre-wrap">
                            {{ finance?.contract_terms ?? '—' }}
                        </dd>
                    </div>
                </dl>
            </template>

            <form v-else class="flex flex-col gap-4" novalidate @submit.prevent="submit">
                <div class="grid gap-4 md:grid-cols-2">
                    <div class="flex min-w-0 flex-col gap-2">
                        <Label for="finance-price">Price</Label>
                        <Input
                            id="finance-price"
                            v-model="form.price"
                            type="number"
                            step="0.01"
                            min="0"
                            inputmode="decimal"
                            :disabled="form.processing"
                            :aria-invalid="form.errors.price ? true : undefined"
                        />
                        <p v-if="form.errors.price" class="text-xs text-destructive">{{ form.errors.price }}</p>
                    </div>

                    <div class="flex min-w-0 flex-col gap-2">
                        <Label for="finance-recurring-amount">Recurring amount</Label>
                        <Input
                            id="finance-recurring-amount"
                            v-model="form.recurring_amount"
                            type="number"
                            step="0.01"
                            min="0"
                            inputmode="decimal"
                            :disabled="form.processing"
                            :aria-invalid="form.errors.recurring_amount ? true : undefined"
                        />
                        <p v-if="form.errors.recurring_amount" class="text-xs text-destructive">
                            {{ form.errors.recurring_amount }}
                        </p>
                    </div>

                    <div class="flex min-w-0 flex-col gap-2">
                        <Label for="finance-billing-frequency">Billing frequency</Label>
                        <Select v-model="form.billing_frequency" :disabled="form.processing">
                            <SelectTrigger
                                id="finance-billing-frequency"
                                class="w-full"
                                :aria-invalid="form.errors.billing_frequency ? true : undefined"
                            >
                                <SelectValue placeholder="Choose a frequency" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem :value="NO_FREQUENCY">No frequency</SelectItem>
                                <SelectItem
                                    v-for="frequency in billingFrequencies"
                                    :key="frequency.value"
                                    :value="frequency.value"
                                >
                                    {{ frequency.label }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <p v-if="form.errors.billing_frequency" class="text-xs text-destructive">
                            {{ form.errors.billing_frequency }}
                        </p>
                    </div>

                    <div v-if="isHourly" class="flex min-w-0 flex-col gap-2">
                        <Label for="finance-hourly-rate">Hourly rate</Label>
                        <Input
                            id="finance-hourly-rate"
                            v-model="form.hourly_rate"
                            type="number"
                            step="0.01"
                            min="0"
                            inputmode="decimal"
                            required
                            :disabled="form.processing"
                            :aria-invalid="form.errors.hourly_rate ? true : undefined"
                        />
                        <p v-if="form.errors.hourly_rate" class="text-xs text-destructive">
                            {{ form.errors.hourly_rate }}
                        </p>
                    </div>

                    <div class="flex min-w-0 flex-col gap-2">
                        <Label for="finance-contract-value">Contract value</Label>
                        <Input
                            id="finance-contract-value"
                            v-model="form.contract_value"
                            type="number"
                            step="0.01"
                            min="0"
                            inputmode="decimal"
                            :disabled="form.processing"
                            :aria-invalid="form.errors.contract_value ? true : undefined"
                        />
                        <p v-if="form.errors.contract_value" class="text-xs text-destructive">
                            {{ form.errors.contract_value }}
                        </p>
                    </div>

                    <div class="flex min-w-0 flex-col gap-2">
                        <Label for="finance-profitability-snapshot">Profitability snapshot</Label>
                        <Input
                            id="finance-profitability-snapshot"
                            v-model="form.profitability_snapshot"
                            type="number"
                            step="0.01"
                            inputmode="decimal"
                            :disabled="form.processing"
                            :aria-invalid="form.errors.profitability_snapshot ? true : undefined"
                        />
                        <p v-if="form.errors.profitability_snapshot" class="text-xs text-destructive">
                            {{ form.errors.profitability_snapshot }}
                        </p>
                    </div>

                    <div class="flex min-w-0 flex-col gap-2 md:col-span-2">
                        <Label for="finance-contract-terms">Contract terms</Label>
                        <Textarea
                            id="finance-contract-terms"
                            v-model="form.contract_terms"
                            rows="3"
                            :disabled="form.processing"
                            :aria-invalid="form.errors.contract_terms ? true : undefined"
                        />
                        <p v-if="form.errors.contract_terms" class="text-xs text-destructive">
                            {{ form.errors.contract_terms }}
                        </p>
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <Button type="submit" size="sm" :disabled="form.processing">
                        {{ form.processing ? 'Saving…' : 'Save finance' }}
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        :disabled="form.processing"
                        @click="editing = false"
                    >
                        Cancel
                    </Button>
                </div>
            </form>
        </CardContent>
    </Card>
</template>
