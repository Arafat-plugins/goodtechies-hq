<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { CalendarRange, Plus } from '@lucide/vue';
import { computed, ref } from 'vue';
import EmptyState from '@/Components/EmptyState.vue';
import StatCard from '@/Components/StatCard.vue';
import TimeEntryDialog from '@/Components/Timer/TimeEntryDialog.vue';
import type { TimeableTask } from '@/Components/Timer/timer';
import TimesheetGrid from '@/Components/Timesheet/TimesheetGrid.vue';
import type {
    TimesheetDay,
    TimesheetRow,
    TimesheetSubject,
    TimesheetTotals,
    TimesheetWeek,
} from '@/Components/Timesheet/timesheet';
import { againstTarget, formatDuration, timesheetRoutes } from '@/Components/Timesheet/timesheet';
import { Button } from '@/Components/ui/button';
import { Label } from '@/Components/ui/label';
import DateStepper from '@/Components/DateStepper.vue';
import { NativeSelect, NativeSelectOption } from '@/Components/ui/native-select';

/**
 * The weekly timesheet, below the page title — **one component, both surfaces**.
 *
 * The employee's own week and the Admin's view of anybody's are the same grid over the same
 * query, differing in exactly two things: who the subject is, and whether the *Add time*
 * control is drawn. Two copies of this would have been two places for a week total to be
 * assembled, which is the mistake `MyTasks.vue` already avoids for the bucket cards.
 *
 * ## Where the week starts is said out loud
 *
 * `week.starts_on_reason` is a sentence the server wrote from the employee's own
 * `schedules.working_days`. It is printed rather than hidden because a reader who expected
 * Monday and got Sunday should be able to read back why, instead of filing a bug about it.
 *
 * ## There is no score here
 *
 * The target appears twice — under each day column and under the week total — and both times
 * as the second half of "18h 20m of 25h". Never a percentage, never a bar filling up, never a
 * verdict. Hours waiting for approval are named separately and in words, the way the Time page
 * names them, so a grid total and the page total can never disagree about what is in them.
 */

const props = defineProps<{
    surface: 'admin' | 'employee';
    subject: TimesheetSubject;
    week: TimesheetWeek;
    days: TimesheetDay[];
    rows: TimesheetRow[];
    totals: TimesheetTotals;
    permissions: { can_add_time: boolean };
    manual_time_requires_approval: boolean;
    /** The Add-time picker's options. Employee surface only — the Admin adds nobody's time. */
    tasks?: TimeableTask[];
    /** Whose weeks may be read from here. Admin surface only. */
    employees?: { id: number; name: string; tracking_mode: string }[];
}>();

const dialogOpen = ref(false);
const preselected = ref<{ date: string; taskId: number | null }>({ date: '', taskId: null });

/** Where a week link on this surface points. Spelled once, in `timesheet.ts`. */
function weekHref(week: string): string {
    return props.surface === 'admin'
        ? timesheetRoutes.admin(props.subject.id, week)
        : timesheetRoutes.own(week);
}

/** How many days of the week are still to come — the answer to "why is the total low". */
const daysToCome = computed(
    () => props.days.filter((day) => day.is_working_day && day.is_future).length,
);

function openDialog(payload: { date: string; taskId: number | null }): void {
    preselected.value = payload;
    dialogOpen.value = true;
}

/** The page action: no cell in mind, so no day and no task preselected. */
function addByHand(): void {
    openDialog({ date: '', taskId: null });
}

/**
 * Switching to somebody else's week is a NAVIGATION, not a filter held in a ref: whose week
 * you are reading belongs in the URL, so it can be shared and so the back button works
 * (DESIGN.md §5.10).
 */
function switchEmployee(event: Event): void {
    const id = Number((event.target as HTMLSelectElement).value);

    if (Number.isFinite(id) && id !== props.subject.id) {
        router.get(timesheetRoutes.admin(id, props.week.start), {}, { preserveScroll: true });
    }
}
</script>

<template>
    <div class="flex min-w-0 flex-col gap-4">
        <!-- Whose week, and which week. Both belong in the URL, so both are links. -->
        <!-- Polish 007: one row — employee, the week between its arrows, then Add time at the end. -->
        <div class="flex min-w-0 flex-wrap items-center gap-3">
            <div v-if="surface === 'admin' && employees?.length" class="flex min-w-0 items-center gap-2 sm:max-w-xs">
                <Label for="timesheet-employee" class="sr-only">Employee</Label>
                <NativeSelect
                    id="timesheet-employee"
                    :model-value="subject.id"
                    @change="switchEmployee"
                >
                    <NativeSelectOption v-for="option in employees" :key="option.id" :value="option.id">
                        {{ option.name }}
                    </NativeSelectOption>
                </NativeSelect>
            </div>

            <DateStepper
                :label="week.label"
                unit="week"
                :previous-href="weekHref(week.previous)"
                :next-href="weekHref(week.next)"
                :today-href="week.is_current ? null : weekHref(week.current)"
            />

            <!--
                The way in that does not need a cell: the Time page has the same control in the
                same words, so somebody who thinks of it as "add time by hand" finds it on
                either screen. Drawn only where the policy allows it, never disabled (§5.12).
            -->
            <Button
                v-if="permissions.can_add_time && rows.length"
                type="button"
                variant="outline"
                class="sm:ml-auto"
                @click="addByHand"
            >
                <Plus aria-hidden="true" />
                Add time by hand
            </Button>

        </div>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            <StatCard
                label="Tracked this week"
                :value="againstTarget(totals.counted_seconds, totals.target_seconds)"
                :sub="
                    totals.target_seconds === null
                        ? 'No daily target on this work schedule'
                        : `${totals.working_days} working ${totals.working_days === 1 ? 'day' : 'days'} in this week`
                "
                :icon="CalendarRange"
            />
            <StatCard
                label="Waiting for approval"
                :value="formatDuration(totals.pending_seconds)"
                sub="Added or corrected by hand, and not counted until it is signed off"
            />
            <StatCard
                label="Still to come"
                :value="daysToCome"
                :sub="
                    daysToCome === 0
                        ? 'Every working day of this week has happened'
                        : 'Working days left in this week'
                "
            />
        </div>

        <EmptyState
            v-if="rows.length === 0"
            :icon="CalendarRange"
            title="Nothing tracked in this week"
            :description="
                subject.is_self
                    ? 'Start the timer on a task, or add a session by hand if the timer missed one.'
                    : `${subject.name} has no tracked hours in this week.`
            "
        >
            <template v-if="permissions.can_add_time" #action>
                <Button type="button" variant="outline" @click="addByHand">
                    <Plus aria-hidden="true" />
                    Add time by hand
                </Button>
            </template>
        </EmptyState>

        <TimesheetGrid
            v-else
            :days="days"
            :rows="rows"
            :totals="totals"
            :subject-name="subject.name"
            :can-add-time="permissions.can_add_time"
            @add-time="openDialog"
        />


        <TimeEntryDialog
            v-if="permissions.can_add_time"
            v-model:open="dialogOpen"
            :tasks="tasks ?? []"
            :requires-approval="manual_time_requires_approval"
            :default-date="preselected.date || null"
            :default-task-id="preselected.taskId"
        />
    </div>
</template>
