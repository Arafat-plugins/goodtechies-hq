<script setup lang="ts">
import { computed } from 'vue';
import type { LeaveCalendarDay, LeaveCalendarPerson } from '@/Components/Leave/leave';
import StatusBadge from '@/Components/StatusBadge.vue';
import { cn } from '@/lib/utils';

/**
 * A month of who is away — the leave calendar.
 *
 * ## What it becomes at 360 px, and the measurement behind it
 *
 * Below `md` it is a **list of the days somebody is away on**; at `md` and up it is the
 * seven-column month grid. Both are in the DOM (`md:hidden` / `hidden md:block`), which is the
 * switch `DataTable` and `AttendanceMonth` already make at the same breakpoint, so the repo has
 * one responsive contract and not three.
 *
 * Decision 4-13 established the rule for the attendance month grid, and the arithmetic here is
 * worse rather than better. **Measured, in this page, with the grid forced visible:** a
 * seven-column cell is **40 px at 360 and 42 px at 375**, against 96 px at 768 and 133 px at
 * 1280. The attendance grid had about 48 px and could not fit one status word — *"Half day"*
 * does not fit at any legal type size.
 *
 * **A leave cell has to fit a person's name AND a status word**, because it says who is away
 * and whether that is settled or still only asked for, and neither may be carried by colour
 * alone (DESIGN.md §5.6; §1.4's measurement is that two of the eight tones sit ΔE 0.16 apart
 * under deuteranopia, and `waiting` and `changes` — Pending and Correction requested — are
 * exactly the pair that argument is about). The phone row's badge, measured, wants **184 px**.
 * At 40 px a grid would have had to drop the name, the word, or the date. The list drops none:
 * each row has the date, the weekday, and one badge per person carrying their name, their leave
 * type and their status word.
 *
 * It also **skips the empty days**, which the grid cannot: a month where two people are away on
 * four days is four rows on a phone and a thirty-cell grid everywhere else, and scrolling past
 * twenty-six empty rows to find them is not reading. The grid keeps every day because a grid
 * with holes in it is not a month.
 *
 * ## At `md` and up the cell still prints every word, and that took two lines
 *
 * The first attempt put `Yaseen · Approved` in one badge. Measured, it wanted **129 px** in a
 * **96 px** cell at both 768 and 1024 — and what truncation eats is the END of the string,
 * which is the status word. A tint with the word cut off is colour carrying meaning alone,
 * which is the one thing this screen may not do.
 *
 * So the cell is two lines: the name truncates on its own, where losing a few characters costs
 * nothing the `title` does not still carry, and the status keeps a badge that always fits —
 * measured at **78 px** against the same 96 px cell. Past three people the cell says *+2 more*
 * rather than growing, and the full list is in its `title` and `aria-label`.
 *
 * ## Zero is an answer
 *
 * A month with nobody away says so, in words, rather than rendering an empty box.
 */

const props = defineProps<{
    days: LeaveCalendarDay[];
    /** How many names a grid cell prints before it collapses the rest into a count. */
    maxPerCell?: number;
}>();

const WEEKDAY_ORDER = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

/** The seven column headings, short. */
const WEEKDAY_HEADINGS = WEEKDAY_ORDER.map((day) => day.slice(0, 3));

const cap = computed(() => props.maxPerCell ?? 3);

/**
 * Empty cells before the 1st, so it lands under its own weekday column.
 *
 * Derived from the first day's NAME, which the server sent, never from a `Date` built in the
 * browser: parsing `2026-10-01` here is a timezone conversion, and a month grid that slid by a
 * day either side of midnight is a bug nobody can reproduce (`attendance.ts` documents the same
 * trap).
 */
const blanks = computed(() => {
    const first = props.days[0];
    const index = first ? WEEKDAY_ORDER.indexOf(first.weekday) : 0;

    return Array.from({ length: Math.max(0, index) }, (_, i) => i);
});

/** Only the days somebody is away on — the phone reading. */
const busyDays = computed(() => props.days.filter((day) => day.people.length > 0));

const anybodyAway = computed(() => busyDays.value.length > 0);

/** The whole cell in one sentence, for a screen reader and for the cell's title. */
function sentence(day: LeaveCalendarDay): string {
    if (day.people.length === 0) {
        return `${day.weekday} ${day.day_of_month} — nobody away`;
    }

    const names = day.people.map((person) => `${person.name} (${person.type ?? 'Leave'}, ${person.status_label})`);

    return `${day.weekday} ${day.day_of_month} — ${names.join('; ')}`;
}

function shown(day: LeaveCalendarDay): LeaveCalendarPerson[] {
    return day.people.slice(0, cap.value);
}

function hidden(day: LeaveCalendarDay): number {
    return Math.max(0, day.people.length - cap.value);
}
</script>

<template>
    <div>
        <!--
            Below md: the days somebody is away on, one row each. Nothing truncated, and the
            empty days are skipped rather than scrolled past.
        -->
        <div class="md:hidden">
            <ul v-if="anybodyAway" class="flex flex-col gap-2" aria-label="Leave by day">
                <li
                    v-for="day in busyDays"
                    :key="day.date"
                    :class="
                        cn(
                            'flex items-start gap-3 rounded-md border bg-card p-3',
                            day.is_past && 'opacity-60',
                            day.is_today && 'ring-2 ring-ring',
                        )
                    "
                >
                    <div class="w-12 shrink-0 text-center">
                        <p class="text-lg font-semibold tabular-nums">{{ day.day_of_month }}</p>
                        <p class="text-xs text-muted-foreground">{{ day.weekday.slice(0, 3) }}</p>
                    </div>

                    <div class="flex min-w-0 flex-1 flex-col gap-2">
                        <p v-if="day.is_today" class="text-xs font-medium">Today</p>
                        <ul class="flex flex-wrap gap-2">
                            <li v-for="person in day.people" :key="`${day.date}-${person.request_id}`">
                                <StatusBadge
                                    :status="person.tone"
                                    :label="`${person.name} — ${person.type ?? 'Leave'}, ${person.status_label}`"
                                    size="sm"
                                />
                            </li>
                        </ul>
                    </div>
                </li>
            </ul>

            <p v-else class="rounded-md border bg-card p-4 text-sm text-muted-foreground">
                Nobody is away this month.
            </p>
        </div>

        <!-- md and up: the seven-column grid. Every day of the month, including the empty ones. -->
        <div class="hidden md:block">
            <div class="mb-2 grid grid-cols-7 gap-2" aria-hidden="true">
                <p
                    v-for="heading in WEEKDAY_HEADINGS"
                    :key="heading"
                    class="text-xs font-medium text-muted-foreground"
                >
                    {{ heading }}
                </p>
            </div>

            <div class="grid grid-cols-7 gap-2">
                <div v-for="blank in blanks" :key="`blank-${blank}`" aria-hidden="true" />

                <div
                    v-for="day in days"
                    :key="day.date"
                    :class="
                        cn(
                            'flex min-h-24 min-w-0 flex-col gap-1 rounded-md border bg-card p-2',
                            day.is_past && 'opacity-60',
                            day.is_today && 'ring-2 ring-ring',
                        )
                    "
                    :aria-label="sentence(day)"
                    :title="sentence(day)"
                >
                    <div class="flex items-baseline justify-between gap-1">
                        <span class="text-sm font-semibold tabular-nums">{{ day.day_of_month }}</span>
                        <span v-if="day.is_today" class="text-xs font-medium">Today</span>
                    </div>

                    <ul class="flex min-w-0 flex-col gap-1.5">
                        <li v-for="person in shown(day)" :key="`${day.date}-${person.request_id}`" class="min-w-0">
                            <!--
                                Two lines, and that is measured rather than stylistic. A cell
                                is 96px at `md` and 133px at `xl`; "Yaseen · Approved" in one
                                badge wants 129px, so a single line would have truncated — and
                                what truncation eats is the END of the string, which is the
                                status WORD. A tint with the word cut off is colour carrying
                                meaning alone (DESIGN.md §5.6), which is the one thing this
                                screen may not do.
                                So the name truncates on its own line, where losing a few
                                characters of a name costs nothing the `title` does not still
                                carry, and the status keeps a badge of its own that always fits.
                            -->
                            <p class="min-w-0 truncate text-xs font-medium">{{ person.name }}</p>
                            <StatusBadge
                                :status="person.tone"
                                :label="person.status_label"
                                size="sm"
                                class="max-w-full"
                            />
                        </li>
                    </ul>

                    <span v-if="hidden(day) > 0" class="mt-auto text-xs text-muted-foreground">
                        +{{ hidden(day) }} more
                    </span>
                </div>
            </div>
        </div>
    </div>
</template>
