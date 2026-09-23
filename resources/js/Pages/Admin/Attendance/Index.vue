<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { CalendarCheck, ChevronLeft, ChevronRight, Pencil } from '@lucide/vue';
import { ref } from 'vue';
import EditDayDialog from '@/Components/Attendance/EditDayDialog.vue';
import type {
    AttendanceDay,
    AttendanceRosterRow,
    AttendanceStatusOption,
    AttendanceSummaryRow,
} from '@/Components/Attendance/attendance';
import {
    attendanceRoutes,
    formatMinutes,
    formatShift,
    noStatusLabel,
} from '@/Components/Attendance/attendance';
import EmptyState from '@/Components/EmptyState.vue';
import PageShell from '@/Components/PageShell.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';

/**
 * Admin → Workforce → Attendance: the morning roster.
 *
 * The Admin opens this once a morning to see who is in. So it is one row per person with the
 * day's status as a **word**, the times, and — for a remote-timer employee — the minutes the
 * timer recorded. Nothing ranks anybody and nothing totals anybody up (Part H §1): the badges
 * along the top are counts of people per status, which is the question that was asked.
 *
 * ## Tapu reads "Remote", and the minutes are a seam
 *
 * `tracked_minutes` arrives null until the timer half of Phase 4 lands
 * (`AttendanceService::trackedMinutes()`), and the row prints that clause only when it is a
 * number — so today he reads *Remote* and afterwards *Remote — 2h 10m tracked*, with no change
 * to this file. Null is not zero: a "0m tracked" here would be a measurement nobody has taken.
 *
 * ## Why this is a list of cards and not a `DataTable`
 *
 * A roster is four to fifteen rows read once a day at a glance, and every row's most important
 * content is a status word plus two times — not a grid of sortable columns. `DataTable` would
 * have brought a column menu, a density toggle and a sort the controller does not implement,
 * which DESIGN.md §5.11 forbids on its own. Each row is a link to that person's month.
 */

const props = defineProps<{
    date: { value: string; label: string; previous: string; next: string; today: string };
    rows: AttendanceRosterRow[];
    summary: AttendanceSummaryRow[];
    statuses: AttendanceStatusOption[];
}>();

const editing = ref<{ day: AttendanceDay; employeeId: number; employeeName: string } | null>(null);
const editOpen = ref(false);

function edit(row: AttendanceRosterRow): void {
    editing.value = { day: row, employeeId: row.employee.id, employeeName: row.employee.name };
    editOpen.value = true;
}

function label(row: AttendanceRosterRow): string {
    return row.status_label ?? noStatusLabel(row);
}
</script>

<template>
    <Head title="Attendance" />

    <PageShell
        title="Attendance"
        :description="date.label"
        :breadcrumb="[{ label: 'Workforce' }, { label: 'Attendance' }]"
    >
        <template #actions>
            <div class="flex items-center gap-2">
                <Button as-child variant="outline" size="icon">
                    <Link :href="attendanceRoutes.roster(date.previous)" preserve-scroll>
                        <ChevronLeft class="size-4" aria-hidden="true" />
                        <span class="sr-only">Previous day</span>
                    </Link>
                </Button>
                <Button v-if="date.value !== date.today" as-child variant="outline">
                    <Link :href="attendanceRoutes.roster()">Today</Link>
                </Button>
                <Button as-child variant="outline" size="icon">
                    <Link :href="attendanceRoutes.roster(date.next)" preserve-scroll>
                        <ChevronRight class="size-4" aria-hidden="true" />
                        <span class="sr-only">Next day</span>
                    </Link>
                </Button>
            </div>
        </template>

        <div class="flex min-w-0 flex-col gap-4">
            <!-- Counts of people per status. Never a ranking. -->
            <ul v-if="summary.length" class="flex flex-wrap gap-2">
                <li v-for="row in summary" :key="row.key">
                    <StatusBadge
                        v-if="row.tone"
                        :status="row.tone"
                        :label="`${row.label}: ${row.count}`"
                        size="sm"
                    />
                </li>
            </ul>

            <Card v-if="!rows.length" class="p-6">
                <EmptyState
                    :icon="CalendarCheck"
                    title="Nobody is tracked yet"
                    description="Attendance rows appear for employees whose tracking mode is the office clock or the remote timer."
                />
            </Card>

            <ul v-else class="flex flex-col gap-2">
                <li
                    v-for="row in rows"
                    :key="row.employee.id"
                    class="flex flex-col gap-3 rounded-md border bg-card p-4 sm:flex-row sm:items-center sm:justify-between"
                >
                    <div class="flex min-w-0 flex-col gap-1">
                        <Link
                            :href="attendanceRoutes.show(row.employee.id)"
                            class="text-sm font-medium underline-offset-4 hover:underline"
                        >
                            {{ row.employee.name }}
                        </Link>
                        <p class="text-xs text-muted-foreground">
                            {{ row.employee.employee_number }}
                            <template v-if="row.employee.role"> · {{ row.employee.role }}</template>
                        </p>
                    </div>

                    <div class="flex min-w-0 flex-wrap items-center gap-2 sm:justify-end">
                        <!-- The word is always beside the tint. -->
                        <StatusBadge v-if="row.tone" :status="row.tone" :label="label(row)" size="sm" />
                        <span v-else class="text-sm text-muted-foreground">{{ label(row) }}</span>

                        <span v-if="row.clock_in" class="text-sm tabular-nums text-muted-foreground">
                            {{ formatShift(row) }}
                        </span>

                        <span v-if="row.worked_minutes !== null" class="text-sm tabular-nums text-muted-foreground">
                            {{ formatMinutes(row.worked_minutes) }}
                        </span>

                        <!-- The remote seam: printed only when the timer measured something. -->
                        <span v-if="row.tracked_minutes !== null" class="text-sm tabular-nums text-muted-foreground">
                            {{ formatMinutes(row.tracked_minutes) }} tracked
                        </span>

                        <!-- `can_edit` is the policy's answer for THIS row, resolved on the
                             server. A remote employee's day has no attendance record to
                             correct — their work is the timer's — so no button is drawn
                             rather than one the endpoint would refuse. -->
                        <Button v-if="row.can_edit && !row.is_future" variant="ghost" size="icon" @click="edit(row)">
                            <Pencil class="size-4" aria-hidden="true" />
                            <span class="sr-only">Edit {{ row.employee.name }}'s day</span>
                        </Button>
                    </div>
                </li>
            </ul>

            <p v-if="rows.length" class="text-xs text-muted-foreground">
                A row links to that person's month. Off days come from each employee's own work schedule, and a remote
                employee is never marked absent — their work is tracked by the timer.
            </p>
        </div>

        <EditDayDialog
            v-if="editing"
            v-model:open="editOpen"
            :employee-id="editing.employeeId"
            :employee-name="editing.employeeName"
            :day="editing.day"
            :statuses="statuses"
        />
    </PageShell>
</template>
