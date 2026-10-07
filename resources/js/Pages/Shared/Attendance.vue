<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight } from '@lucide/vue';
import { computed, ref } from 'vue';
import AttendanceMonth from '@/Components/Attendance/AttendanceMonth.vue';
import ClockWidget from '@/Components/Attendance/ClockWidget.vue';
import EditDayDialog from '@/Components/Attendance/EditDayDialog.vue';
import RequestCorrectionDialog from '@/Components/Attendance/RequestCorrectionDialog.vue';
import type {
    AttendanceCorrection,
    AttendanceCorrectionRules,
    AttendanceDay,
    AttendanceMonth as AttendanceMonthPayload,
    AttendanceScheduleSummary,
    AttendanceStatusOption,
    AttendanceSummaryRow,
} from '@/Components/Attendance/attendance';
import { attendanceRoutes, attendanceStatusIcon, canAskCorrection } from '@/Components/Attendance/attendance';
import CountChip from '@/Components/CountChip.vue';
import PageShell from '@/Components/PageShell.vue';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { ATTENDANCE_POLL_MS } from '@/Components/Realtime/live';
import { useLiveProps } from '@/Components/Realtime/reload';
import AccountantLayout from '@/Layouts/AccountantLayout.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import EmployeeLayout from '@/Layouts/EmployeeLayout.vue';
import type { SharedProps } from '@/types';
import { clock12 } from '@/lib/clock';

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
    permissions: { can_clock: boolean; can_edit: boolean; can_request_correction: boolean };
    /** Polish 029: the latest correction request per day of this month, keyed by `Y-m-d`. */
    corrections: Record<string, AttendanceCorrection>;
    correction_rules: AttendanceCorrectionRules;
}>();

const asking = ref<AttendanceDay | null>(null);
const askOpen = ref(false);

const todayCorrection = computed(() => props.corrections[props.today.date]);
const todayAskable = computed(
    () =>
        props.permissions.can_request_correction &&
        !props.permissions.can_edit &&
        canAskCorrection(props.today, props.correction_rules, todayCorrection.value),
);

function ask(day: AttendanceDay): void {
    asking.value = day;
    askOpen.value = true;
}

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

/* ---------------------------------------------------------------- keeping it current */

/**
 * A clock-in from another device lands here — POLISH-BACKLOG §A.3's *"Attendance / Time"* line,
 * whose note is *"the clock widget's live counter already ticks locally; a clock-in from another
 * device should land"*.
 *
 * The two halves of that sentence are different problems and only the second one was open.
 * `ClockWidget` counts up from `clock_in_at` in the browser, once a minute, and always did — but
 * the `clock_in_at` it counts from is a prop, so somebody who tapped *Clock in* on their phone at
 * the door and then opened the laptop saw yesterday's answer until they reloaded. Now the page
 * re-reads `today` (and the month's `days` and `summary` under it) every thirty seconds, and the
 * local counter picks up the new start time from the fresh prop with no code of its own.
 *
 * **Thirty seconds**, because nobody clocks in twice in thirty seconds, and a clock-in is the one
 * event on this screen that happens while somebody is looking at it. Poll-only: attendance has no
 * broadcast event and no channel — adding one would be a room whose audience is "this employee
 * and whoever may manage their attendance", which is a policy question that has an answer but
 * would buy thirty seconds on a screen nobody watches for thirty seconds. Said out loud rather
 * than built (§A.4 rule 3).
 *
 * `useLiveProps` holds the refresh while the edit-a-day dialog is open, so a correction somebody
 * is typing is never re-read out from under them.
 */
useLiveProps(['today', 'days', 'summary', 'corrections'], { intervalMs: ATTENDANCE_POLL_MS });
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
            <ClockWidget
                v-if="subject.is_self"
                :today="today"
                :can-clock="permissions.can_clock"
                :can-ask-correction="todayAskable"
                :correction-label="todayCorrection?.status === 'pending' ? 'Correction asked' : null"
                @ask-correction="ask(today)"
            />

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
                        Starts {{ clock12(schedule.start_time) }} — arriving more than
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
                    <CountChip
                        v-if="row.tone"
                        :status="row.tone"
                        :label="row.label"
                        :count="row.count"
                        :icon="attendanceStatusIcon(row.key)"
                    />
                </li>
            </ul>

            <AttendanceMonth
                :days="days"
                :can-edit="permissions.can_edit"
                :corrections="corrections"
                :correction-rules="correction_rules"
                :can-request-correction="permissions.can_request_correction"
                @edit="edit"
                @request="ask"
            />
        </div>

        <EditDayDialog
            v-if="permissions.can_edit"
            v-model:open="editOpen"
            :employee-id="subject.id"
            :employee-name="subject.name"
            :day="editing"
            :statuses="statuses"
            :correction="editing ? corrections[editing.date] : undefined"
        />

        <RequestCorrectionDialog
            v-if="permissions.can_request_correction"
            v-model:open="askOpen"
            :day="asking"
            :previous="asking ? corrections[asking.date] : undefined"
        />
    </PageShell>
</template>
