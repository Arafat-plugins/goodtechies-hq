<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ShieldAlert } from '@lucide/vue';
import { computed, useId, watch } from 'vue';
import type { AttendanceDay, AttendanceStatusOption } from '@/Components/Attendance/attendance';
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
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Textarea } from '@/Components/ui/textarea';

/**
 * An Admin correcting one day, with the reason it was corrected.
 *
 * ## The reason is not a nicety
 *
 * An attendance record is what Phase 9 pays somebody from, so the field is `required` in
 * `UpdateAttendanceRecordRequest` and the dialog says out loud that the change is recorded.
 * It is not a client-side rule — the endpoint refuses an empty one — and the sentence here
 * exists so that nobody is surprised by the audit row afterwards.
 *
 * ## Four statuses, and they come from the server
 *
 * `statuses` is `AttendanceStatus::editable()`, sent as data, which is the same list the
 * request's `Rule::in` checks. Leave and Holiday are not among them because they are owned by
 * their own records in Phase 5; Off Day and Remote are derived and the column refuses them
 * outright. A hard-coded list here would have been a fifth place for that split to drift.
 *
 * The write is an upsert on `(employee, date)` — the row's own identity — so this same dialog
 * records a day that has no row yet.
 */

const props = defineProps<{
    open: boolean;
    employeeId: number;
    employeeName: string;
    day: AttendanceDay | null;
    /** `AttendanceStatus::editable()`, from the server. */
    statuses: AttendanceStatusOption[];
}>();

const emit = defineEmits<{ 'update:open': [open: boolean] }>();

const statusId = useId();
const inId = useId();
const outId = useId();
const noteId = useId();

const form = useForm({
    status: 'present',
    // Plain strings, because that is what a `time` input binds to. They become null in
    // `transform()` below — Absent is a day with no times on it, and the endpoint has to be
    // able to receive that.
    clock_in: '',
    clock_out: '',
    note: '',
});

const heading = computed(() =>
    props.day ? `${props.day.weekday} ${props.day.day_of_month}` : 'Attendance',
);

/**
 * Fill the form from the day each time the dialog opens.
 *
 * A day with no record starts from the first editable status rather than from nothing, so the
 * select is never in reka's "nothing selected" state — which it reserves the empty string for
 * and which would make the first submit fail validation on a field the Admin never touched.
 */
watch(
    () => [props.open, props.day?.date],
    () => {
        if (!props.open || !props.day) {
            return;
        }

        form.clearErrors();
        form.status = props.day.status ?? props.statuses[0]?.value ?? 'present';
        form.clock_in = props.day.clock_in ?? '';
        form.clock_out = props.day.clock_out ?? '';
        form.note = '';
    },
    { immediate: true },
);

function submit(): void {
    if (!props.day) {
        return;
    }

    form
        .transform((data) => ({
            ...data,
            // An empty time input sends `''`, which is not a time. Absent is a day with no
            // times on it, so the endpoint has to be able to receive null.
            clock_in: data.clock_in === '' ? null : data.clock_in,
            clock_out: data.clock_out === '' ? null : data.clock_out,
        }))
        .put(attendanceRoutes.editDay(props.employeeId, props.day.date), {
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
                <DialogTitle>{{ heading }} — {{ employeeName }}</DialogTitle>
                <DialogDescription>
                    Correct this day. The change is recorded in the audit log with the old and new values.
                </DialogDescription>
            </DialogHeader>

            <form class="flex flex-col gap-4" @submit.prevent="submit">
                <div class="flex flex-col gap-2">
                    <Label :for="statusId">Status</Label>
                    <Select :id="statusId" v-model="form.status">
                        <SelectTrigger :id="statusId" class="w-full">
                            <SelectValue placeholder="Pick a status" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem v-for="option in statuses" :key="option.value" :value="option.value">
                                {{ option.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <p v-if="form.errors.status" class="text-xs text-destructive">{{ form.errors.status }}</p>
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div class="flex flex-col gap-2">
                        <Label :for="inId">Clock in</Label>
                        <Input :id="inId" v-model="form.clock_in" type="time" />
                        <p v-if="form.errors.clock_in" class="text-xs text-destructive">{{ form.errors.clock_in }}</p>
                    </div>
                    <div class="flex flex-col gap-2">
                        <Label :for="outId">Clock out</Label>
                        <Input :id="outId" v-model="form.clock_out" type="time" />
                        <p v-if="form.errors.clock_out" class="text-xs text-destructive">{{ form.errors.clock_out }}</p>
                    </div>
                </div>

                <div class="flex flex-col gap-2">
                    <Label :for="noteId">Reason</Label>
                    <Textarea
                        :id="noteId"
                        v-model="form.note"
                        rows="3"
                        placeholder="Why is this day being changed?"
                        required
                    />
                    <p v-if="form.errors.note" class="text-xs text-destructive">{{ form.errors.note }}</p>
                </div>

                <p class="flex items-start gap-2 text-xs text-muted-foreground">
                    <ShieldAlert class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                    <span>Attendance is the basis of payroll. Every edit is logged against your name.</span>
                </p>

                <DialogFooter>
                    <Button type="button" variant="outline" @click="emit('update:open', false)">Cancel</Button>
                    <Button type="submit" :disabled="form.processing">Save day</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
