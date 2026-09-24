<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight } from '@lucide/vue';
import { ref } from 'vue';
import AttendanceMonth from '@/Components/Attendance/AttendanceMonth.vue';
import ClockWidget from '@/Components/Attendance/ClockWidget.vue';
import EditDayDialog from '@/Components/Attendance/EditDayDialog.vue';
import type {
    AttendanceDay,
    AttendanceMonth as AttendanceMonthPayload,
    AttendanceScheduleSummary,
    AttendanceStatusOption,
    AttendanceSummaryRow,
} from '@/Components/Attendance/attendance';
import { attendanceRoutes } from '@/Components/Attendance/attendance';
import PageShell from '@/Components/PageShell.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import AccountantLayout from '@/Layouts/AccountantLayout.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import EmployeeLayout from '@/Layouts/EmployeeLayout.vue';
import type { SharedProps } from '@/types';
import { usePagePoll } from '@/lib/pagePoll';

/**
 * Somebody's attendance: today's clock, and the month.
 *
 * One page for every surface, for the reason `Pages/Shared/Profile.vue` is one: an Admin
 * reading their own attendance and Yaseen reading his are the same screen and the same query.
 * The layout is picked from `auth.user.surface`, and the only thing that differs between the
 * two readings is whether the server resolved `permissions.can_edit` — which is also what
 * makes this the screen an Admin lands on from a roster row.
 *
 * Nothing on this page is a score. There is no total, no percentage and no comparison with
 * anybody else (Part H §1): the summary counts how many days of the month wore each status,
 * and the grid says what each day was.
 */

defineOptions({
    layout: (props: SharedProps) => {
        const surface = props.auth.user?.surface;

        if (surface === 'admin') {
            return AdminLayout;
        }

        return surface === 'accountant' ? AccountantLayout : EmployeeLayout;
    },
});

const props = defineProps<{
    subject: { id: number; name: string; is_self: boolean; tracking_mode: string };
    schedule: AttendanceScheduleSummary | null;
    month: AttendanceMonthPayload;
    days: AttendanceDay[];
    summary: AttendanceSummaryRow[];
    today: AttendanceDay;
    /**
     * `AttendanceStatus::editable()` — the four statuses a correction may write, from the
     * server, so the dialog's options and the request's `Rule::in` are one list. Empty for a
     * reader who may not edit, who has no dialog to fill.
     */
    statuses: AttendanceStatusOption[];
    permissions: { can_clock: boolean; can_edit: boolean };
}>();

/** Part 0.5 refresh rule: a second tab, and an Admin's edit to one of these days. */
usePagePoll(['days', 'summary', 'today']);

const editing = ref<AttendanceDay | null>(null);
const editOpen = ref(false);

function edit(day: AttendanceDay): void {
    editing.value = day;
    editOpen.value = true;
}

/** `sun` → `Sun`, in the order the server stored them. */
function workingDayLabels(days: string[]): string {
    return days.map((day) => day.charAt(0).toUpperCase() + day.slice(1)).join(', ');
}
</script>

<template>
    <Head :title="subject.is_self ? 'My attendance' : `${subject.name} — attendance`" />

    <PageShell
        :title="subject.is_self ? 'My attendance' : `${subject.name} — attendance`"
        :description="
            subject.is_self
                ? 'Your clock-ins, clock-outs and the status of each day.'
                : `Clock-ins, clock-outs and the status of each day for ${subject.name}.`
        "
        :breadcrumb="
            permissions.can_edit && !subject.is_self
                ? [{ label: 'Attendance', href: attendanceRoutes.roster() }, { label: subject.name }]
                : undefined
        "
    >
        <div class="flex min-w-0 flex-col gap-4">
            <ClockWidget v-if="subject.is_self" :today="today" :can-clock="permissions.can_clock" />

            <!-- The schedule, printed so a status nobody expected can be read back to the rule
                 that produced it rather than looking like a bug. -->
            <Card v-if="schedule" class="flex flex-col gap-1 p-4">
                <p class="text-sm font-medium">Work schedule</p>
                <p class="text-xs text-muted-foreground">
                    {{ workingDayLabels(schedule.working_days) || 'No working days set' }} ·
                    {{ schedule.working_hours_per_day }} hours a day ·
                    {{ schedule.office_or_remote === 'remote' ? 'Remote' : 'Office' }}
                </p>
                <p class="text-xs text-muted-foreground">
                    <template v-if="schedule.start_time">
                        Starts {{ schedule.start_time }} — arriving more than
                        {{ schedule.late_grace_minutes }} minutes after that is Late.
                    </template>
                    <template v-else> No start time, so a day here is never Late. </template>
                </p>
            </Card>

            <!-- Month navigation. The month is in the URL, so a month is a link somebody can
                 send (DESIGN.md §5.10). -->
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-lg font-semibold tracking-tight">{{ month.label }}</h2>

                <div class="flex items-center gap-2">
                    <Button as-child variant="outline" size="icon">
                        <Link
                            :href="attendanceRoutes.show(subject.is_self ? null : subject.id, month.previous)"
                            preserve-scroll
                        >
                            <ChevronLeft class="size-4" aria-hidden="true" />
                            <span class="sr-only">Previous month</span>
                        </Link>
                    </Button>
                    <Button as-child variant="outline" size="icon">
                        <Link
                            :href="attendanceRoutes.show(subject.is_self ? null : subject.id, month.next)"
                            preserve-scroll
                        >
                            <ChevronRight class="size-4" aria-hidden="true" />
                            <span class="sr-only">Next month</span>
                        </Link>
                    </Button>
                </div>
            </div>

            <!-- Counts of days per status. A count, never a rating. -->
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

            <AttendanceMonth :days="days" :can-edit="permissions.can_edit" @edit="edit" />
        </div>

        <EditDayDialog
            v-if="permissions.can_edit"
            v-model:open="editOpen"
            :employee-id="subject.id"
            :employee-name="subject.name"
            :day="editing"
            :statuses="statuses"
        />
    </PageShell>
</template>
