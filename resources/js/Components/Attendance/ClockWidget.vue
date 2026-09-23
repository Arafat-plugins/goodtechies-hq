<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { LogIn, LogOut } from '@lucide/vue';
import { computed, ref } from 'vue';
import type { AttendanceDay } from '@/Components/Attendance/attendance';
import { attendanceRoutes, formatMinutes, noStatusLabel } from '@/Components/Attendance/attendance';
import StatusBadge from '@/Components/StatusBadge.vue';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';

/**
 * Clock In / Clock Out, and today's status.
 *
 * The plan's *"Attendance widget (office roles incl. Admins): Clock In / Clock Out on
 * dashboard, today's status"*. It is a component and not a dashboard section on purpose —
 * wiring it onto the two dashboards is the next slice. It is mounted at the top of the self
 * attendance page, which is where somebody who opened *My Attendance* to clock in would look.
 *
 * ## It is used one-handed, at a door, on a phone
 *
 * So there is exactly one primary control, it is full width below `sm`, and it is at least 44
 * px tall. The status is a word in a `StatusBadge` and the times are beside it, because
 * somebody who has just tapped needs to see that the tap landed without reading a colour.
 *
 * ## `canClock` is the server's
 *
 * It is `AttendanceRecordPolicy::clock` — the `attendance.view_own` key AND
 * `tracking_mode = office_attendance` — resolved per record. Never a role read in Vue
 * (decisions 2-28, 2-31). When it is false this renders nothing at all rather than a disabled
 * button: a control somebody can never use is hidden, not greyed out (DESIGN.md §5.12).
 *
 * ## It announces nothing itself
 *
 * Both writes come back `back()->with(...)` carrying the server's own sentence — *"Clocked in
 * at 08:58. Today is Present."* — which the layout's `FlashMessage` speaks once. A `toast()`
 * here would be the same message said twice (DESIGN.md §5.19).
 */

const props = defineProps<{
    /** Today, as `AttendanceService::dayFor()` answered it. */
    today: AttendanceDay;
    /** `AttendanceRecordPolicy::clock`, resolved on the server. */
    canClock: boolean;
}>();

const busy = ref(false);

/** Clocked in and not yet out. The only state in which Clock out is the next thing to do. */
const isOpen = computed(() => props.today.clock_in !== null && props.today.clock_out === null);

/** The day is finished: both times are in, so there is nothing left to press. */
const isFinished = computed(() => props.today.clock_out !== null);

const statusLabel = computed(() => props.today.status_label ?? noStatusLabel(props.today));

function clock(url: string): void {
    if (busy.value) {
        return;
    }

    busy.value = true;

    router.post(
        url,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                busy.value = false;
            },
        },
    );
}
</script>

<template>
    <Card v-if="canClock" class="flex flex-col gap-4 p-4 sm:flex-row sm:items-center sm:justify-between sm:p-6">
        <div class="flex min-w-0 flex-col gap-2">
            <p class="text-sm font-medium">Today</p>

            <div class="flex flex-wrap items-center gap-2">
                <!-- The word, always. A tinted pill with no label is colour carrying meaning
                     alone, and four of the eight status dots fail 3:1 without one. -->
                <StatusBadge v-if="today.tone" :status="today.tone" :label="statusLabel" />
                <span v-else class="text-sm text-muted-foreground">{{ statusLabel }}</span>

                <span v-if="today.clock_in" class="text-sm tabular-nums text-muted-foreground">
                    In {{ today.clock_in }}<template v-if="today.clock_out"> · Out {{ today.clock_out }}</template>
                </span>
            </div>

            <p v-if="today.worked_minutes !== null" class="text-xs tabular-nums text-muted-foreground">
                {{ formatMinutes(today.worked_minutes) }} worked
            </p>
        </div>

        <!-- One primary control. Full width on a phone so it can be hit with a thumb. -->
        <div class="shrink-0">
            <Button
                v-if="!isFinished"
                class="h-11 w-full sm:w-auto"
                :disabled="busy"
                @click="clock(isOpen ? attendanceRoutes.clockOut : attendanceRoutes.clockIn)"
            >
                <component :is="isOpen ? LogOut : LogIn" class="size-4" aria-hidden="true" />
                {{ isOpen ? 'Clock out' : 'Clock in' }}
            </Button>

            <p v-else class="text-sm text-muted-foreground">Clocked out for the day.</p>
        </div>
    </Card>
</template>
