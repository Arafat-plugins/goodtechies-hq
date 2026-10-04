<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import { formatMoney } from '@/Components/Finance/finance';
import type { SalaryRow } from '@/Components/Payroll/payslip';
import { formatEffectiveDate, salaryDeleteRoute } from '@/Components/Payroll/payslip';
import { Button } from '@/Components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/Components/ui/dialog';

/**
 * Delete one salary row (polish 002). The trigger is the row's own button, so the dialog
 * returns focus to it on cancel; on success the row is gone and focus falls to the page.
 */
const props = defineProps<{ row: SalaryRow; employeeName: string; currency: string }>();

const open = ref(false);
const deleting = ref(false);

function confirmDelete(): void {
    if (deleting.value) {
        return;
    }

    deleting.value = true;

    router.delete(salaryDeleteRoute(props.row.id), {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
        onFinish: () => {
            deleting.value = false;
        },
    });
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogTrigger as-child>
            <Button
                type="button"
                variant="ghost"
                size="icon"
                class="size-8 text-muted-foreground hover:text-destructive"
                :aria-label="`Delete ${employeeName}'s salary from ${formatEffectiveDate(row.effective_from)}`"
            >
                <Trash2 aria-hidden="true" />
            </Button>
        </DialogTrigger>

        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Delete this salary?</DialogTitle>
                <DialogDescription>
                    {{ formatMoney(row.base_salary, currency) }} for {{ employeeName }}, starting
                    {{ formatEffectiveDate(row.effective_from) }}. Months already drafted keep their figures. The
                    deletion is recorded in the audit log.
                </DialogDescription>
            </DialogHeader>
            <DialogFooter>
                <Button type="button" variant="outline" :disabled="deleting" @click="open = false">Keep it</Button>
                <Button type="button" variant="destructive" :disabled="deleting" @click="confirmDelete">
                    <Trash2 aria-hidden="true" />
                    {{ deleting ? 'Deleting…' : 'Delete salary' }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
