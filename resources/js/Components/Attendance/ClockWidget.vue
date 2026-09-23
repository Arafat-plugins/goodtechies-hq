<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { LogIn, LogOut } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
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
 * ## While the day is open, it counts
 *
 * `worked_minutes` is null until a clock-out — it is a difference and one end has not
 * happened yet — so until then this counts up from `clock_in_at` in the browser. It ticks once
 * a minute, not once a second: this figure is read in minutes, a seconds hand on it would be a
 * moving thing nobody asked to watch, and the ticking number is `aria-hidden` with the spoken
 * text carried beside it. The moment a clock-out lands, the server's `worked_minutes` takes
 * over and the browser stops guessing.
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

/**
 * Now, re-read once a minute while the day is open. A plain `Date.now()` in a computed would
 * be read once and never again, because nothing reactive changes when time passes.
 */
const now = ref(Date.now());
let tick: ReturnType<typeof setInterval> | null = null;

onMounted(() => {
    tick = setInterval(() => {
        now.value = Date.now();
    }, 60_000);
});

onBeforeUnmount(() => {
    if (tick !== null) {
        clearInterval(tick);
        tick = null;
    }
});

/**
 * How long they have been here — the server's figure once the day is closed, and the live
 * count from `clock_in_at` while it is open. Never both, and never a guess once a real
 * number exists.
 */
const elapsedMinutes = computed<number | null>(() => {
    if (props.today.worked_minutes !== null) {
        return props.today.worked_minutes;
    }

    if (!isOpen.value || props.today.clock_in_at === null) {
        return null;
    }

    const started = new Date(props.today.clock_in_at).getTime();

    if (Number.isNaN(started)) {
        return null;
    }

    // A clock skew between the browser and the server could make this negative for a moment
    // after a clock-in; zero is the honest floor, not a minus sign.
    return Math.max(0, Math.floor((now.value - started) / 60_000));
});

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

            <p v-if="elapsedMinutes !== null" class="text-xs tabular-nums text-muted-foreground">
                <span aria-hidden="true">
                    {{ formatMinutes(elapsedMinutes) }} {{ isFinished ? 'worked' : 'so far today' }}
                </span>
                <span class="sr-only">
                    {{ isFinished ? 'Worked' : 'Clocked in for' }} {{ formatMinutes(elapsedMinutes) }}
                </span>
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
