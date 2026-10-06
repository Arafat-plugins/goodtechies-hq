<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { CircleAlert, LogIn, LogOut, RotateCw } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import type { AttendanceDay } from '@/Components/Attendance/attendance';
import { attendanceRoutes, formatMinutes, noStatusLabel } from '@/Components/Attendance/attendance';
import { setClockedIn } from '@/Components/Attendance/clockState';
import StatusBadge from '@/Components/StatusBadge.vue';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { inlineUploadFailure } from '@/lib/net';
import { clock12 } from '@/lib/clock';

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
 * The count is the closed sessions plus the open one: the server's `worked_minutes` (the sum
 * of the sessions already closed, null until the first one closes) plus the minutes since the
 * open session started (`open_since`, falling back to `clock_in_at`), counted in the browser.
 * The gaps between sessions are breaks and are not counted (decision 12-76). It ticks once
 * a minute, not once a second: this figure is read in minutes, a seconds hand on it would be a
 * moving thing nobody asked to watch, and the ticking number is `aria-hidden` with the spoken
 * text carried beside it. The moment a clock-out lands, the server's `worked_minutes` alone is
 * the figure and the browser stops guessing.
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

/**
 * Reliability slice 4: the clock-in or clock-out that did not reach goodERP.
 *
 * Said here, beside the button, with a *Try again* that resends the same action — and not also
 * toasted: slice 2's global handler is opted out of for this one visit (`inlineUploadFailure`,
 * the same per-visit opt-out the uploads use), so the failure is told once. There is no offline
 * queue on purpose: the server's time is the record, so a clock-in is only true once the server
 * has it, and a queued one would be a time nobody can vouch for.
 */
const CLOCK_IN_FAILED_TEXT = "Couldn't reach goodERP, so you are not clocked in yet.";
const CLOCK_OUT_FAILED_TEXT = "Couldn't reach goodERP, so you are not clocked out yet.";

const failed = ref<{ url: string; text: string } | null>(null);

/** Clocked in and not yet out. The only state in which Clock out is the next thing to do. */
const isOpen = computed(
    () => props.today.open_since !== null || (props.today.clock_in !== null && props.today.clock_out === null),
);

/** Clocked out; the next press starts a new session. */
const isFinished = computed(() => props.today.clock_out !== null && !isOpen.value);

const sessions = computed(() => props.today.sessions ?? []);
const hasBreaks = computed(() => sessions.value.length > 1);

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
 * How long they have worked today — while a session is open, the server's closed-session sum
 * plus the live count since the open session started; once clocked out, the server's figure
 * alone. Breaks between sessions are never counted.
 */
const elapsedMinutes = computed<number | null>(() => {
    if (!isOpen.value) {
        return props.today.worked_minutes;
    }

    const since = props.today.open_since ?? props.today.clock_in_at;

    if (since === null) {
        return props.today.worked_minutes;
    }

    const started = new Date(since).getTime();

    if (Number.isNaN(started)) {
        return props.today.worked_minutes;
    }

    // A clock skew between the browser and the server could make this negative for a moment
    // after a clock-in; zero is the honest floor, not a minus sign.
    return (props.today.worked_minutes ?? 0) + Math.max(0, Math.floor((now.value - started) / 60_000));
});

function clock(url: string): void {
    if (busy.value) {
        return;
    }

    busy.value = true;

    const text = url === attendanceRoutes.clockOut ? CLOCK_OUT_FAILED_TEXT : CLOCK_IN_FAILED_TEXT;

    router.post(
        url,
        {},
        {
            preserveScroll: true,
            // No answer or a 5xx: shown inline below. A 401 / 419 / 403 falls through to the
            // global handler, so the session dialog still speaks.
            ...inlineUploadFailure(() => {
                failed.value = { url, text };
            }),
            onSuccess: (page) => {
                failed.value = null;
                // The tab-close guard's state (decision 12-84). A refused write comes back as a
                // flash error with the state unchanged, so the server's own answer wins.
                setClockedIn(page.props.clock?.clocked_in ?? url === attendanceRoutes.clockIn);
            },
            onFinish: () => {
                busy.value = false;
            },
        },
    );
}

function retry(): void {
    if (failed.value !== null) {
        clock(failed.value.url);
    }
}
</script>

<template>
    <Card v-if="canClock" class="flex flex-col gap-4 p-4 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between sm:p-6">
        <div class="flex min-w-0 flex-col gap-2">
            <p class="text-sm font-medium">Today</p>

            <div class="flex flex-wrap items-center gap-2">
                <!-- The word, always. A tinted pill with no label is colour carrying meaning
                     alone, and four of the eight status dots fail 3:1 without one. -->
                <StatusBadge v-if="today.tone" :status="today.tone" :label="statusLabel" />
                <span v-else class="text-sm text-muted-foreground">{{ statusLabel }}</span>

                <span v-if="hasBreaks" class="text-sm tabular-nums text-muted-foreground">
                    First in {{ clock12(sessions[0].clock_in) }}<template v-if="today.clock_out"> · Last out {{ clock12(today.clock_out) }}</template>
                </span>
                <span v-else-if="today.clock_in" class="text-sm tabular-nums text-muted-foreground">
                    In {{ clock12(today.clock_in) }}<template v-if="today.clock_out"> · Out {{ clock12(today.clock_out) }}</template>
                </span>
            </div>

            <p v-if="elapsedMinutes !== null" class="text-xs tabular-nums text-muted-foreground">
                <span aria-hidden="true">
                    {{ formatMinutes(elapsedMinutes) }} {{ isOpen ? 'so far today' : 'worked today' }}
                </span>
                <span class="sr-only">
                    {{ isOpen ? 'Clocked in, worked so far' : 'Worked today' }} {{ formatMinutes(elapsedMinutes) }}
                </span>
            </p>

            <template v-if="hasBreaks">
                <ul aria-label="Today's sessions" class="flex flex-col gap-0.5 text-xs tabular-nums text-muted-foreground">
                    <li v-for="(s, i) in sessions" :key="i">
                        {{ clock12(s.clock_in) }} – {{ s.clock_out ? clock12(s.clock_out) : 'now' }}<template v-if="s.minutes !== null"> · {{ formatMinutes(s.minutes) }}</template>
                    </li>
                </ul>
                <p class="text-xs text-muted-foreground">Breaks between sessions are not counted.</p>
            </template>
        </div>

        <!-- One primary control. Full width on a phone so it can be hit with a thumb. -->
        <div class="shrink-0" :class="isFinished ? 'flex flex-col gap-2 sm:items-end' : ''">
            <p v-if="isFinished" class="text-sm text-muted-foreground">Clocked out at {{ clock12(today.clock_out) }}.</p>

            <Button
                class="h-11 w-full sm:w-auto"
                :variant="isOpen || today.clock_in === null ? 'default' : 'outline'"
                :disabled="busy"
                @click="clock(isOpen ? attendanceRoutes.clockOut : attendanceRoutes.clockIn)"
            >
                <component :is="isOpen ? LogOut : LogIn" class="size-4" aria-hidden="true" />
                {{ isOpen ? 'Clock out' : today.clock_in === null ? 'Clock in' : 'Clock in again' }}
            </Button>
        </div>

        <div
            v-if="failed"
            role="alert"
            class="flex w-full flex-col gap-2 rounded-md border border-destructive/40 bg-destructive/5 p-3 sm:basis-full sm:flex-row sm:items-center sm:justify-between sm:gap-4"
        >
            <p class="flex items-start gap-2 text-xs text-destructive">
                <CircleAlert class="mt-0.5 size-3 shrink-0" aria-hidden="true" />
                {{ failed.text }}
            </p>
            <Button type="button" size="sm" variant="outline" class="h-11 shrink-0 sm:h-9" :disabled="busy" @click="retry">
                <RotateCw class="size-4" aria-hidden="true" />
                Try again
            </Button>
        </div>
    </Card>
</template>
