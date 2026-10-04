<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { CalendarClock } from '@lucide/vue';
import { ref, useId } from 'vue';
import { formatMonthValue, payrollRoutes } from '@/Components/Payroll/payroll';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';

/**
 * "Which month is this payroll for?" on a Draft — polish 005.
 *
 * Pay follows the work: a payroll drafted on 1 October for October, but paid for September's
 * work, is moved to September here, and its payslips then say September. Only a Draft moves;
 * the server refuses a future month or one that already has a payroll, and audits the change.
 */
const props = defineProps<{ periodId: number; month: string }>();

const inputId = useId();
const editing = ref(false);
const chosen = ref(props.month.slice(0, 7));
const saving = ref(false);
const error = ref<string | null>(null);

const maxMonth = (() => {
    const now = new Date();

    return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`;
})();

function save(): void {
    if (saving.value || chosen.value === '') {
        return;
    }

    saving.value = true;
    error.value = null;

    router.put(
        payrollRoutes.month(props.periodId),
        { month: chosen.value },
        {
            preserveScroll: true,
            onSuccess: () => {
                editing.value = false;
            },
            onError: (errors) => {
                error.value = errors.month ?? null;
            },
            onFinish: () => {
                saving.value = false;
            },
        },
    );
}
</script>

<template>
    <div class="flex min-w-0 flex-col gap-1">
        <Button v-if="!editing" type="button" variant="outline" size="sm" @click="editing = true">
            <CalendarClock aria-hidden="true" />
            Change month
        </Button>
        <form v-else class="flex min-w-0 flex-wrap items-center gap-2" @submit.prevent="save">
            <label :for="inputId" class="text-sm text-muted-foreground">This payroll is for</label>
            <Input :id="inputId" v-model="chosen" type="month" :max="maxMonth" required class="w-44" />
            <Button type="submit" size="sm" :disabled="saving || chosen === ''">
                {{ saving ? 'Saving…' : `Set to ${formatMonthValue(chosen)}` }}
            </Button>
            <Button type="button" variant="ghost" size="sm" :disabled="saving" @click="editing = false">
                Cancel
            </Button>
        </form>
        <p v-if="error" class="text-xs text-destructive">{{ error }}</p>
    </div>
</template>
