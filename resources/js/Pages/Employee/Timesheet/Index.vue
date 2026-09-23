<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import PageShell from '@/Components/PageShell.vue';
import type { TimeableTask } from '@/Components/Timer/timer';
import TimesheetView from '@/Components/Timesheet/TimesheetView.vue';
import type {
    TimesheetDay,
    TimesheetRow,
    TimesheetSubject,
    TimesheetTotals,
    TimesheetWeek,
} from '@/Components/Timesheet/timesheet';
import EmployeeLayout from '@/Layouts/EmployeeLayout.vue';

/**
 * The remote employee's own timesheet: this week, tasks × days.
 *
 * Tapu opens it on a Thursday to see whether he is near his 25 hours, which is why the week
 * total against the schedule's target is the first thing on it and the grid is the second.
 *
 * Everything below the title is `TimesheetView`, the same component the Admin's page mounts —
 * so his week and the Admin's reading of it can never show two different totals.
 */

const props = defineProps<{
    subject: TimesheetSubject;
    week: TimesheetWeek;
    days: TimesheetDay[];
    rows: TimesheetRow[];
    totals: TimesheetTotals;
    permissions: { can_add_time: boolean };
    manual_time_requires_approval: boolean;
    tasks: TimeableTask[];
}>();

// The persistent layout, as every other page in this folder declares it: the shell is not
// remounted on an Inertia visit, so the sidebar keeps its scroll and its rail state.
defineOptions({ layout: EmployeeLayout });
</script>

<template>
    <Head title="Timesheet" />

    <PageShell
        title="Timesheet"
        :description="`Your tracked hours for ${props.week.label}, task by task and day by day.`"
        :breadcrumb="[{ label: 'Timesheet' }]"
    >
        <TimesheetView
            surface="employee"
            :subject="subject"
            :week="week"
            :days="days"
            :rows="rows"
            :totals="totals"
            :permissions="permissions"
            :manual_time_requires_approval="manual_time_requires_approval"
            :tasks="tasks"
        />
    </PageShell>
</template>
