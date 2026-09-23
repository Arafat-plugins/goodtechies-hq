<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import PageShell from '@/Components/PageShell.vue';
import TimesheetView from '@/Components/Timesheet/TimesheetView.vue';
import type {
    TimesheetDay,
    TimesheetRow,
    TimesheetSubject,
    TimesheetTotals,
    TimesheetWeek,
} from '@/Components/Timesheet/timesheet';
import AdminLayout from '@/Layouts/AdminLayout.vue';

/**
 * Admin → Workforce → Timesheet: one employee's week.
 *
 * The same component the employee's own page mounts, so the two readings of a week are one
 * query and one set of totals. The differences arrive as data: an employee picker, and
 * `permissions.can_add_time` false — adding time by hand belongs to the person whose week it
 * is, so this page draws the grid and no control the server would refuse.
 *
 * It signs nothing off. Approving a pending entry is Workforce → Time.
 */

const props = defineProps<{
    subject: TimesheetSubject;
    week: TimesheetWeek;
    days: TimesheetDay[];
    rows: TimesheetRow[];
    totals: TimesheetTotals;
    permissions: { can_add_time: boolean };
    manual_time_requires_approval: boolean;
    employees: { id: number; name: string; tracking_mode: string }[];
}>();

// The persistent layout, as every other page in this folder declares it: the shell is not
// remounted on an Inertia visit, so the sidebar keeps its scroll and its rail state.
defineOptions({ layout: AdminLayout });
</script>

<template>
    <Head :title="`Timesheet — ${props.subject.name}`" />

    <PageShell
        title="Timesheet"
        :description="`${props.subject.name} — ${props.week.label}, task by task and day by day.`"
        :breadcrumb="[{ label: 'Workforce' }, { label: 'Timesheet' }]"
    >
        <TimesheetView
            surface="admin"
            :subject="subject"
            :week="week"
            :days="days"
            :rows="rows"
            :totals="totals"
            :permissions="permissions"
            :manual_time_requires_approval="manual_time_requires_approval"
            :employees="employees"
        />
    </PageShell>
</template>
