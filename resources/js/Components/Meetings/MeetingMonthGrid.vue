<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { CalendarDays } from '@lucide/vue';
import { computed } from 'vue';
import EmptyState from '@/Components/EmptyState.vue';
import MeetingChip from '@/Components/Meetings/MeetingChip.vue';
import MeetingRow from '@/Components/Meetings/MeetingRow.vue';
import type { Meeting, MeetingDay, MeetingWindow } from '@/Components/Meetings/meetings';
import {
    WEEKDAY_NAMES,
    meetingDayLabel,
    meetingDayNumber,
    meetingDayWithin,
    meetingWeekOf,
    meetingsHref,
} from '@/Components/Meetings/meetings';
import { cn } from '@/lib/utils';

/**
 * The month, drawn from the window the server named.
 *
 * ## The grid never does date arithmetic
 *
 * `window.days` is the exact list of days the payload covers — Monday of the week the 1st falls
 * in, through the Sunday after the last — and this file chops it into rows of seven. Nothing
 * here counts days, works out leap years or guesses where the month starts, so the grid and the
 * query that filled it cannot disagree. `month_from` / `month_to` are what make a corner cell
 * read as *outside this month* rather than as empty.
 *
 * ## What it becomes at 360 px
 *
 * Below `sm` there is no grid. Seven columns across 360 px minus gutters leaves about 44 px a
 * cell, which fits a day number and nothing else — a calendar that shows you it has meetings
 * without telling you what any of them are. So the phone gets the **same month as an agenda**:
 * the days that have something on them, in order, each with its meetings at full width and full
 * legibility. The month, its heading and its arrows are unchanged; only the shape of the answer
 * is. The grid returns at `sm` and up.
 *
 * ## Labelling
 *
 * Every cell carries a **real date** as a visually-hidden `<h3>` — *"Monday 21 September 2026,
 * 2 meetings"* — so heading navigation walks the month and nobody has to infer a date from a
 * number in a box. Today is marked three ways and never by colour alone: a ring, a filled day
 * number, and the word *Today*.
 *
 * A cell that has more meetings than fit says how many more, as a link into that week rather
 * than as a dead "+3".
 */

const props = defineProps<{
    window: MeetingWindow;
    days: MeetingDay[];
    carry: Record<string, string>;
}>();

/** How many chips a cell shows before it starts counting instead. */
const CHIPS_PER_CELL = 3;

const byDate = computed(() => {
    const map = new Map<string, MeetingDay>();

    for (const day of props.days) {
        map.set(day.date, day);
    }

    return map;
});

interface Cell {
    date: string;
    number: number;
    label: string;
    outside: boolean;
    isToday: boolean;
    meetings: Meeting[];
    overflow: number;
}

const cells = computed<Cell[]>(() =>
    props.window.days.map((date): Cell => {
        const day = byDate.value.get(date);
        const meetings = day?.meetings ?? [];
        const count = meetings.length;

        return {
            date,
            number: meetingDayNumber(date),
            label:
                `${meetingDayLabel(date)}, ` +
                (count === 0 ? 'no meetings' : count === 1 ? '1 meeting' : `${count} meetings`),
            outside: !meetingDayWithin(date, props.window.month_from, props.window.month_to),
            isToday: date === props.window.today,
            meetings: meetings.slice(0, CHIPS_PER_CELL),
            overflow: Math.max(0, count - CHIPS_PER_CELL),
        };
    }),
);

const weeks = computed<Cell[][]>(() => {
    const rows: Cell[][] = [];

    for (let index = 0; index < cells.value.length; index += 7) {
        rows.push(cells.value.slice(index, index + 7));
    }

    return rows;
});

/** The days with something on them, for the phone agenda — already in date order. */
const agenda = computed(() => props.days.filter((day) => day.meetings.length > 0));

function weekHref(date: string): string {
    return meetingsHref({ ...props.carry, view: 'week', week: meetingWeekOf(date) });
}
</script>

<template>
    <div class="flex min-w-0 flex-col gap-4">
        <!-- ─────────────────────────────── the grid, from `sm` up ─────────────────────────── -->
        <div class="hidden min-w-0 sm:block">
            <div class="grid grid-cols-7 gap-px rounded-lg border bg-border overflow-hidden">
                <!-- Headings are decorative: every cell already names its own full date. -->
                <div
                    v-for="name in WEEKDAY_NAMES"
                    :key="name"
                    aria-hidden="true"
                    class="bg-muted px-2 py-1.5 text-center text-xs font-medium text-muted-foreground"
                >
                    {{ name }}
                </div>

                <template v-for="week in weeks" :key="week[0]?.date">
                    <div
                        v-for="cell in week"
                        :key="cell.date"
                        :class="
                            cn(
                                'flex min-h-24 min-w-0 flex-col gap-1 p-1.5',
                                cell.outside ? 'bg-muted/50' : 'bg-card',
                                cell.isToday ? 'ring-2 ring-primary ring-inset' : undefined,
                            )
                        "
                    >
                        <h3 class="sr-only">{{ cell.label }}</h3>

                        <p class="flex items-center justify-between gap-1">
                            <span
                                aria-hidden="true"
                                :class="
                                    cn(
                                        'text-xs tabular-nums',
                                        cell.isToday
                                            ? 'inline-flex size-5 items-center justify-center rounded-full bg-primary font-semibold text-primary-foreground'
                                            : cell.outside
                                              ? 'text-muted-foreground/70'
                                              : 'text-muted-foreground',
                                    )
                                "
                            >
                                {{ cell.number }}
                            </span>
                            <span
                                v-if="cell.isToday"
                                aria-hidden="true"
                                class="text-xs font-medium tracking-wide text-primary uppercase"
                            >
                                Today
                            </span>
                        </p>

                        <ul v-if="cell.meetings.length > 0" class="flex min-w-0 flex-col gap-1">
                            <li v-for="meeting in cell.meetings" :key="meeting.id" class="min-w-0">
                                <MeetingChip :meeting="meeting" />
                            </li>
                        </ul>

                        <Link
                            v-if="cell.overflow > 0"
                            :href="weekHref(cell.date)"
                            class="rounded-sm px-1.5 text-xs text-muted-foreground underline-offset-2 outline-none hover:underline focus-visible:ring-3 focus-visible:ring-ring"
                            :aria-label="`${cell.overflow} more on ${meetingDayLabel(cell.date)} — open that week`"
                        >
                            + {{ cell.overflow }} more
                        </Link>
                    </div>
                </template>
            </div>
        </div>

        <!-- ────────────────────────── the same month as an agenda, below `sm` ─────────────── -->
        <div class="flex min-w-0 flex-col gap-4 sm:hidden">
            <p class="text-xs text-muted-foreground">
                The month grid needs a wider screen. These are the days in {{ window.label }} that have
                something on them.
            </p>

            <section v-for="day in agenda" :key="day.date" class="flex min-w-0 flex-col gap-2">
                <h3 class="text-sm font-medium">
                    {{ day.label }}
                    <span v-if="day.is_today" class="text-xs font-normal text-muted-foreground">· Today</span>
                </h3>
                <ul class="flex min-w-0 flex-col gap-2">
                    <MeetingRow v-for="meeting in day.meetings" :key="meeting.id" :meeting="meeting" />
                </ul>
            </section>

            <EmptyState
                v-if="agenda.length === 0"
                :icon="CalendarDays"
                :title="`Nothing in ${window.label}`"
                description="Move to another month, or schedule something."
            />
        </div>
    </div>
</template>
