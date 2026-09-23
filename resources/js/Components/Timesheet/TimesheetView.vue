<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { CalendarRange, ChevronLeft, ChevronRight, Plus } from '@lucide/vue';
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
import { Card } from '@/Components/ui/card';
import { Label } from '@/Components/ui/label';
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
        <div class="flex min-w-0 flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div v-if="surface === 'admin' && employees?.length" class="flex min-w-0 flex-col gap-1.5 sm:max-w-xs">
                <Label for="timesheet-employee">Employee</Label>
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

            <!--
                The way in that does not need a cell: the Time page has the same control in the
                same words, so somebody who thinks of it as "add time by hand" finds it on
                either screen. Drawn only where the policy allows it, never disabled (§5.12).
            -->
            <Button
                v-if="permissions.can_add_time && rows.length"
                type="button"
                variant="outline"
                class="self-start"
                @click="addByHand"
            >
                <Plus aria-hidden="true" />
                Add time by hand
            </Button>

            <div class="flex items-center gap-2 sm:ml-auto">
                <Button as-child variant="outline" size="icon">
                    <Link :href="weekHref(week.previous)" preserve-scroll>
                        <ChevronLeft class="size-4" aria-hidden="true" />
                        <span class="sr-only">The week before</span>
                    </Link>
                </Button>
                <Button v-if="!week.is_current" as-child variant="outline">
                    <Link :href="weekHref(week.current)">This week</Link>
                </Button>
                <Button as-child variant="outline" size="icon">
                    <Link :href="weekHref(week.next)" preserve-scroll>
                        <ChevronRight class="size-4" aria-hidden="true" />
                        <span class="sr-only">The week after</span>
                    </Link>
                </Button>
            </div>
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

        <!--
            The three sentences this screen owes its reader: where the week starts and why,
            what is not in the totals, and — when there is nothing on the grid for somebody
            else — why that might be. None of them is a number dressed as a verdict.
        -->
        <Card class="flex flex-col gap-2 p-4">
            <p class="text-xs text-muted-foreground">{{ week.starts_on_reason }}</p>

            <p v-if="totals.pending_seconds > 0" class="text-xs text-muted-foreground">
                {{ formatDuration(totals.pending_seconds) }} of this week is waiting for an Admin to sign it off and is
                not in the totals above. It is shown in the cell it belongs to in the meantime.
            </p>

            <p v-if="totals.rejected_seconds > 0" class="text-xs text-muted-foreground">
                {{ formatDuration(totals.rejected_seconds) }} of this week was not approved. The hours stay on the
                record; they do not count towards the total.
            </p>

            <p v-if="!subject.is_self && subject.tracking_mode !== 'remote_timer'" class="text-xs text-muted-foreground">
                {{ subject.name }}'s day is recorded by the office clock rather than the timer, so this week has no
                tracked hours to show. Their attendance is on Workforce → Attendance.
            </p>
        </Card>

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
