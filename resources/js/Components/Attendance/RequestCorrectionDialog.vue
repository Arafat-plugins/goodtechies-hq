<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, useId, watch } from 'vue';
import type { AttendanceCorrection, AttendanceDay } from '@/Components/Attendance/attendance';
import { attendanceRoutes } from '@/Components/Attendance/attendance';
import { Button } from '@/Components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/Components/ui/dialog';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';
import { clock12 } from '@/lib/clock';

/**
 * Polish 029: an employee asks for one of their own days to be corrected — a Late by mistake,
 * a forgotten clock-in. The reason goes to the Admins; the day only changes if one approves.
 * When the day was declined before, the Admin's note is shown above the box.
 */
const props = defineProps<{
    open: boolean;
    day: AttendanceDay | null;
    previous?: AttendanceCorrection;
}>();

const emit = defineEmits<{ 'update:open': [open: boolean] }>();

const reasonId = useId();

const form = useForm({ date: '', reason: '' });

const heading = computed(() => (props.day ? `${props.day.weekday} ${props.day.day_of_month}` : 'Attendance'));

const marked = computed(() => {
    if (!props.day) {
        return '';
    }

    const label = props.day.status_label ?? 'this';

    return props.day.clock_in ? `${label} · in ${clock12(props.day.clock_in)}` : label;
});

watch(
    () => [props.open, props.day?.date],
    () => {
        if (!props.open || !props.day) {
            return;
        }

        form.clearErrors();
        form.date = props.day.date;
        form.reason = '';
    },
    { immediate: true },
);

function submit(): void {
    if (!props.day) {
        return;
    }

    form.post(attendanceRoutes.requestCorrection, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => emit('update:open', false),
    });
}
</script>

<template>
    <Dialog :open="open" @update:open="emit('update:open', $event)">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Ask to correct {{ heading }}</DialogTitle>
                <DialogDescription>Marked {{ marked }}. An Admin will approve or decline it.</DialogDescription>
            </DialogHeader>

            <form class="flex flex-col gap-4" @submit.prevent="submit">
                <p
                    v-if="previous?.status === 'rejected'"
                    class="rounded-md border bg-muted px-3 py-2 text-xs text-muted-foreground"
                >
                    Declined before<template v-if="previous.decided_by"> by {{ previous.decided_by }}</template
                    ><template v-if="previous.decision_note">: {{ previous.decision_note }}</template>
                </p>

                <div class="flex flex-col gap-2">
                    <Label :for="reasonId">What happened?</Label>
                    <Textarea
                        :id="reasonId"
                        v-model="form.reason"
                        rows="4"
                        maxlength="1000"
                        placeholder="For example: I was in the office at 8:50 but clocked in late by mistake."
                        required
                    />
                    <p v-if="form.errors.reason" class="text-xs text-destructive">{{ form.errors.reason }}</p>
                    <p v-if="form.errors.date" class="text-xs text-destructive">{{ form.errors.date }}</p>
                </div>

                <DialogFooter>
                    <Button type="button" variant="outline" @click="emit('update:open', false)">Cancel</Button>
                    <Button type="submit" :disabled="form.processing || form.reason.trim().length < 3">
                        {{ form.processing ? 'Sending…' : 'Send request' }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
