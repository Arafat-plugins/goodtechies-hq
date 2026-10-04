<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { computed, ref, useId, watch } from 'vue';
import type { PayrollCurrentMonth } from '@/Components/Payroll/payroll';
import { formatMonthValue, payrollRoutes } from '@/Components/Payroll/payroll';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';

/**
 * Draft a payroll for a chosen month — polish 005.
 *
 * Pay follows the work in Bangladesh: September is worked and paid in October, and its payslip
 * says September. So the month defaults to the one just finished (`current_month.value`, the
 * server's answer in the agency's timezone) and can be changed to any month up to this one
 * that has no payroll yet. The server refuses a future or taken month as well; this only says
 * so before the press.
 */
const props = defineProps<{ month: PayrollCurrentMonth; variant?: 'default' | 'outline' }>();

const inputId = useId();
const chosen = ref(props.month.value);
const drafting = ref(false);
const error = ref<string | null>(null);

watch(
    () => props.month.value,
    (value) => {
        chosen.value = value;
    },
);

const taken = computed(() => props.month.taken.includes(chosen.value));
const label = computed(() => formatMonthValue(chosen.value));

function submit(): void {
    if (drafting.value || taken.value || chosen.value === '') {
        return;
    }

    drafting.value = true;
    error.value = null;

    router.post(
        payrollRoutes.store(),
        { month: chosen.value },
        {
            preserveScroll: true,
            onError: (errors) => {
                error.value = errors.month ?? null;
            },
            onFinish: () => {
                drafting.value = false;
            },
        },
    );
}
</script>

<template>
    <form class="flex w-full min-w-0 flex-col gap-1" @submit.prevent="submit">
        <div class="flex min-w-0 flex-wrap items-center gap-2">
            <label :for="inputId" class="text-sm text-muted-foreground">Payroll for</label>
            <Input
                :id="inputId"
                v-model="chosen"
                type="month"
                :max="month.max"
                required
                class="w-44"
                :aria-invalid="taken || error !== null ? 'true' : undefined"
            />
            <!-- Polish 013: the month on the left, the Draft button at the end of the row. -->
            <Button
                type="submit"
                class="ml-auto"
                :variant="variant ?? 'default'"
                :disabled="drafting || taken || chosen === ''"
            >
                <Plus aria-hidden="true" />
                {{ drafting ? 'Drafting…' : `Draft ${label}` }}
            </Button>
        </div>
        <p v-if="taken" class="text-xs text-muted-foreground">{{ label }} already has a payroll.</p>
        <p v-else-if="error" class="text-xs text-destructive">{{ error }}</p>
    </form>
</template>
