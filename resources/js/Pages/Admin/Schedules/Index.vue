<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { AlarmClock } from '@lucide/vue';
import { computed, ref, useId, watch } from 'vue';
import type { ScheduleEditorRow, WeekdayOption } from '@/Components/Attendance/attendance';
import { attendanceRoutes } from '@/Components/Attendance/attendance';
import EmptyState from '@/Components/EmptyState.vue';
import PageShell from '@/Components/PageShell.vue';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
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
import AdminLayout from '@/Layouts/AdminLayout.vue';

/**
 * Shipped without a layout: this page and the schedule editor were the only two under
 * `Pages/**` with no `defineOptions({ layout })`, so both rendered bare — no sidebar, no top
 * bar, no skip link. Every accessibility measurement on them passed, because a page with no
 * shell has nothing to overflow and almost nothing to tab through. Found by reading, not by
 * measuring.
 */
defineOptions({ layout: AdminLayout });


/**
 * Admin → Workforce → Work Schedule: the per-employee schedule editor.
 *
 * This screen is why no working week is hard-coded anywhere in the application. Every rule
 * that reads a schedule — Off Day, Late, and which days `hq:mark-absent` marks — is stated
 * once in `AttendanceService` and simply follows whatever is saved here.
 *
 * The seven day keys come from the server (`Weekday::cases()`), which is the same enum
 * `UpdateScheduleRequest` validates against, so a checkbox can never offer a key the request
 * would refuse.
 *
 * Two things the form says out loud, because both surprise people otherwise:
 *
 *   - **no start time means never Late.** It is the honest reading — there is nothing to be
 *     late for — and it is how a flexible or remote schedule is expressed;
 *   - **the change is audit-logged.** Moving a start time rewrites what a fortnight of past
 *     arrivals will be called, and Phase 9 reads those days.
 */

const props = defineProps<{
    rows: ScheduleEditorRow[];
    weekdays: WeekdayOption[];
}>();

const editing = ref<ScheduleEditorRow | null>(null);
const open = ref(false);

const hoursId = useId();
const startId = useId();
const locationId = useId();

const form = useForm({
    working_days: [] as string[],
    working_hours_per_day: 8,
    // A plain string, because that is what a `time` input binds to; it becomes null in
    // `transform()`, and the difference between "" and null is the difference between a
    // schedule that can be late and one that cannot.
    start_time: '',
    office_or_remote: 'office',
});

const heading = computed(() => editing.value?.employee.name ?? 'Work schedule');

function edit(row: ScheduleEditorRow): void {
    editing.value = row;
    form.clearErrors();
    form.working_days = [...(row.schedule?.working_days ?? [])];
    form.working_hours_per_day = row.schedule?.working_hours_per_day ?? 8;
    form.start_time = row.schedule?.start_time ?? '';
    form.office_or_remote = row.schedule?.office_or_remote ?? 'office';
    open.value = true;
}

function toggle(day: string, checked: boolean): void {
    form.working_days = checked
        ? [...new Set([...form.working_days, day])]
        : form.working_days.filter((value) => value !== day);
}

function submit(): void {
    if (!editing.value) {
        return;
    }

    form
        .transform((data) => ({
            ...data,
            // An empty time input sends `''`, which is not a time — and the difference between
            // "" and null here is the difference between a schedule that can be late and one
            // that cannot.
            start_time: data.start_time === '' ? null : data.start_time,
        }))
        .put(attendanceRoutes.saveSchedule(editing.value.employee.id), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                open.value = false;
            },
        });
}

/** `sun` → `Sun`, in the server's week order. */
function summarise(row: ScheduleEditorRow): string {
    if (!row.schedule) {
        return 'No schedule set';
    }

    const days = row.schedule.working_days
        .map((key) => props.weekdays.find((day) => day.value === key)?.short ?? key)
        .join(', ');

    const start = row.schedule.start_time ? `starts ${row.schedule.start_time}` : 'no start time';

    return `${days || 'No working days'} · ${row.schedule.working_hours_per_day} h/day · ${start}`;
}

/** Watch the dialog closing so a cancelled edit does not leave a half-filled form behind. */
watch(open, (isOpen) => {
    if (!isOpen) {
        form.clearErrors();
    }
});
</script>

<template>
    <Head title="Work schedule" />

    <PageShell
        title="Work schedule"
        description="Which days each employee works, how many hours, and when their day starts."
        :breadcrumb="[{ label: 'Workforce' }, { label: 'Work schedule' }]"
    >
        <div class="flex min-w-0 flex-col gap-4">
            <Card v-if="!rows.length" class="p-6">
                <EmptyState
                    :icon="AlarmClock"
                    title="Nobody is tracked yet"
                    description="A schedule exists for employees whose tracking mode is the office clock or the remote timer."
                />
            </Card>

            <ul v-else class="flex flex-col gap-2">
                <li
                    v-for="row in rows"
                    :key="row.employee.id"
                    class="flex flex-col gap-3 rounded-md border bg-card p-4 sm:flex-row sm:items-center sm:justify-between"
                >
                    <div class="flex min-w-0 flex-col gap-1">
                        <p class="text-sm font-medium">{{ row.employee.name }}</p>
                        <p class="text-xs text-muted-foreground">{{ summarise(row) }}</p>
                        <p v-if="!row.schedule" class="text-xs text-destructive">
                            Without a schedule this employee cannot clock in and is never marked absent.
                        </p>
                    </div>

                    <Button variant="outline" class="shrink-0 sm:w-auto" @click="edit(row)">
                        Edit schedule
                        <span class="sr-only">for {{ row.employee.name }}</span>
                    </Button>
                </li>
            </ul>
        </div>

        <Dialog v-model:open="open">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>{{ heading }}</DialogTitle>
                    <DialogDescription>
                        This decides which days are off days and which arrivals are late. The change is recorded in the
                        audit log.
                    </DialogDescription>
                </DialogHeader>

                <form class="flex flex-col gap-4" @submit.prevent="submit">
                    <fieldset class="flex flex-col gap-2">
                        <legend class="text-sm font-medium">Working days</legend>
                        <div class="flex flex-wrap gap-3">
                            <div v-for="day in weekdays" :key="day.value" class="flex items-center gap-2">
                                <Checkbox
                                    :id="`day-${day.value}`"
                                    :model-value="form.working_days.includes(day.value)"
                                    @update:model-value="toggle(day.value, $event === true)"
                                />
                                <Label :for="`day-${day.value}`" class="text-sm">{{ day.short }}</Label>
                            </div>
                        </div>
                        <p v-if="form.errors.working_days" class="text-xs text-destructive">
                            {{ form.errors.working_days }}
                        </p>
                    </fieldset>

                    <div class="flex flex-col gap-2">
                        <Label :for="hoursId">Hours a day</Label>
                        <Input
                            :id="hoursId"
                            v-model="form.working_hours_per_day"
                            type="number"
                            step="0.25"
                            min="0.5"
                            max="24"
                        />
                        <p v-if="form.errors.working_hours_per_day" class="text-xs text-destructive">
                            {{ form.errors.working_hours_per_day }}
                        </p>
                    </div>

                    <div class="flex flex-col gap-2">
                        <Label :for="startId">Start time</Label>
                        <Input :id="startId" v-model="form.start_time" type="time" />
                        <p class="text-xs text-muted-foreground">
                            Leave it empty for a flexible day — an employee with no start time is never Late.
                        </p>
                        <p v-if="form.errors.start_time" class="text-xs text-destructive">
                            {{ form.errors.start_time }}
                        </p>
                    </div>

                    <div class="flex flex-col gap-2">
                        <Label :for="locationId">Work location</Label>
                        <Select :id="locationId" v-model="form.office_or_remote">
                            <SelectTrigger :id="locationId" class="w-full">
                                <SelectValue placeholder="Office or remote" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="office">Office</SelectItem>
                                <SelectItem value="remote">Remote</SelectItem>
                            </SelectContent>
                        </Select>
                        <p v-if="form.errors.office_or_remote" class="text-xs text-destructive">
                            {{ form.errors.office_or_remote }}
                        </p>
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="outline" @click="open = false">Cancel</Button>
                        <Button type="submit" :disabled="form.processing">Save schedule</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </PageShell>
</template>
