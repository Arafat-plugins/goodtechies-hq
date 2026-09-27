<script setup lang="ts">
import { computed } from 'vue';
import MeetingChip from '@/Components/Meetings/MeetingChip.vue';
import type { Meeting, MeetingDay, MeetingWindow } from '@/Components/Meetings/meetings';
import { meetingDayLabel, meetingDayNumber } from '@/Components/Meetings/meetings';
import { cn } from '@/lib/utils';

/**
 * The seven days of one week, as seven columns of meetings.
 *
 * ## Why this and not a time grid
 *
 * A time grid — hours down the left, meetings positioned against a pixels-per-minute scale — is
 * the shape people picture when they hear "week view", and it was rejected here for three
 * reasons that are all about this application rather than about taste:
 *
 *   1. **It is the fragile one.** Absolute positioning against a scale means every meeting's
 *      geometry is arithmetic, overlapping meetings need lane packing, and the whole thing lives
 *      inside a scroll container that has to be nudged to the working day on mount. `TaskCalendar`
 *      already carries that machinery for spans, and the Phase 2 close-out found two bugs in it.
 *   2. **It hides the short ones.** A fifteen-minute stand-up on a 24-hour scale is a sliver
 *      with no room for a word in it, so the thing it says has to move into a tooltip — which is
 *      to say, out of reach of anybody not using a mouse.
 *   3. **It does not survive 360 px.** Seven columns plus a gutter of hours is the widest layout
 *      in the application, and the usual escape is a day-at-a-time picker, which is a second
 *      view to build and a second thing to get wrong.
 *
 * A seven-column day list gives every meeting its full title, its full time and a real focus
 * order (document order, left to right, top to bottom), costs no geometry at all, and collapses
 * to one column on a phone by changing a single grid class. What it gives up is the visual sense
 * of *shape* — you cannot see at a glance that Tuesday afternoon is solid. For an agency of five
 * people booking a handful of meetings a week, that is not the question anybody is asking of
 * this screen; "what have I got on Thursday" is, and a list answers it better.
 *
 * ## Labelling
 *
 * Each column is a `<section>` with its own heading carrying the **full date** — *Thursday 24
 * September 2026* — and its count. Today gets a ring, a filled number and the word *Today*, so
 * it is marked three ways and none of them is hue (DESIGN.md §5.6).
 */

const props = defineProps<{
    window: MeetingWindow;
    days: MeetingDay[];
}>();

const byDate = computed(() => {
    const map = new Map<string, Meeting[]>();

    for (const day of props.days) {
        map.set(day.date, day.meetings);
    }

    return map;
});

interface Column {
    date: string;
    number: number;
    weekday: string;
    label: string;
    isToday: boolean;
    meetings: Meeting[];
}

const columns = computed<Column[]>(() =>
    props.window.days.map((date): Column => {
        const meetings = byDate.value.get(date) ?? [];
        const count = meetings.length;
        const full = meetingDayLabel(date);

        return {
            date,
            number: meetingDayNumber(date),
            weekday: full.split(' ')[0] ?? '',
            label: `${full}, ` + (count === 0 ? 'no meetings' : count === 1 ? '1 meeting' : `${count} meetings`),
            isToday: date === props.window.today,
            meetings,
        };
    }),
);
</script>

<template>
    <!--
        One column per day from `md`; one stacked column below it, where seven of anything is
        seven times too many. `min-w-0` on every level is what keeps a long title truncating
        inside its column instead of widening the row it sits in.
    -->
    <div class="grid min-w-0 grid-cols-1 gap-3 md:grid-cols-7 md:gap-px md:rounded-lg md:border md:bg-border md:overflow-hidden">
        <section
            v-for="column in columns"
            :key="column.date"
            :class="
                cn(
                    'flex min-w-0 flex-col gap-2 rounded-lg border p-2 md:rounded-none md:border-0',
                    column.isToday ? 'bg-card ring-2 ring-primary ring-inset' : 'bg-card',
                )
            "
        >
            <h3 class="flex min-w-0 items-baseline gap-1.5">
                <span class="sr-only">{{ column.label }}</span>
                <span aria-hidden="true" class="text-xs font-medium text-muted-foreground">
                    {{ column.weekday }}
                </span>
                <span
                    aria-hidden="true"
                    :class="
                        cn(
                            'text-sm tabular-nums',
                            column.isToday
                                ? 'inline-flex size-5 items-center justify-center rounded-full bg-primary text-xs font-semibold text-primary-foreground'
                                : 'font-medium',
                        )
                    "
                >
                    {{ column.number }}
                </span>
                <span v-if="column.isToday" aria-hidden="true" class="text-xs font-medium text-primary">
                    Today
                </span>
            </h3>

            <ul v-if="column.meetings.length > 0" class="flex min-w-0 flex-col gap-1">
                <li v-for="meeting in column.meetings" :key="meeting.id" class="min-w-0">
                    <MeetingChip :meeting="meeting" />
                </li>
            </ul>
            <p v-else aria-hidden="true" class="text-xs text-muted-foreground">—</p>
        </section>
    </div>
</template>
