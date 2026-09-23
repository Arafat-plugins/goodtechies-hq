<script setup lang="ts">
import { Pencil } from '@lucide/vue';
import { computed } from 'vue';
import type { AttendanceDay } from '@/Components/Attendance/attendance';
import {
    WEEKDAY_HEADINGS,
    formatMinutes,
    formatShift,
    leadingBlanks,
    noStatusLabel,
} from '@/Components/Attendance/attendance';
import StatusBadge, { statusToneClass } from '@/Components/StatusBadge.vue';
import { Button } from '@/Components/ui/button';
import { cn } from '@/lib/utils';

/**
 * One month of somebody's attendance — *"month calendar/list"*, and it is genuinely both.
 *
 * ## What it becomes at 360 px, and why
 *
 * Below `md` it is a **list**, one row per day; at `md` and up it is the seven-column grid.
 * Both are in the DOM (`md:hidden` / `hidden md:grid`), which is the switch `DataTable`
 * already makes at the same breakpoint, so the repo has one responsive contract and not two.
 *
 * The reason is measured, not stylistic. A seven-column grid at 360 px gives each cell about
 * 48 px of usable width. Every status on this screen must print its **word** — colour alone is
 * never the carrier here, and DESIGN.md §2.2 is the measurement: with the label removed, four
 * of the eight status tones fail 3:1 in light mode, and two of them sit ΔE 0.16 apart under
 * deuteranopia. *Half day* does not fit in 48 px at any legal type size, so a phone grid would
 * have had to drop either the word or the day — and a table that needs sideways scrolling is a
 * table nobody reads. The list drops neither: each row has the date, the weekday, the status
 * word in its badge, and the times.
 *
 * ## The grid still prints every word
 *
 * At `md` a cell is ~95 px, which fits the longest label at `text-xs`. The tint comes from
 * `statusToneClass()` — the same triplet a `StatusBadge` wears, so there is one mapping of a
 * status to a colour in the app and not two — and DESIGN.md §4.2's rule for that class is that
 * whatever takes it still says the word. Each cell also carries an `aria-label` with the full
 * sentence, so the truncation a very narrow `md` column could cause is never the only copy.
 */

const props = defineProps<{
    days: AttendanceDay[];
    /** Whether an *Edit* control is drawn per day — `AttendanceRecordPolicy::update`. */
    canEdit?: boolean;
}>();

const emit = defineEmits<{ edit: [day: AttendanceDay] }>();

/** Empty cells before the 1st, so it lands under its own weekday column. */
const blanks = computed(() => Array.from({ length: leadingBlanks(props.days) }, (_, index) => index));

function label(day: AttendanceDay): string {
    return day.status_label ?? noStatusLabel(day);
}

/** The whole day in one sentence, for a screen reader and for the cell's title. */
function sentence(day: AttendanceDay): string {
    const parts = [`${day.weekday} ${day.day_of_month}`, label(day)];

    if (day.clock_in) {
        parts.push(formatShift(day));
    }

    if (day.worked_minutes !== null) {
        parts.push(`${formatMinutes(day.worked_minutes)} worked`);
    }

    if (day.tracked_minutes !== null) {
        parts.push(`${formatMinutes(day.tracked_minutes)} tracked`);
    }

    if (day.edited_by) {
        parts.push(`edited by ${day.edited_by}`);
    }

    return parts.join(' — ');
}

/** A day that has not happened is quieter, but still says what the schedule expects of it. */
function cellClass(day: AttendanceDay): string {
    return cn(
        'flex min-h-24 flex-col gap-1 rounded-md border p-2 text-left',
        day.tone ? statusToneClass(day.tone) : 'bg-card text-muted-foreground',
        day.is_future && 'opacity-60',
        // Today is marked by a ring as well as by the word "Today" under the date, so the
        // marking is never a colour on its own.
        day.is_today && 'ring-2 ring-ring',
    );
}
</script>

<template>
    <div>
        <!-- Below md: the list. Every day of the month, one row, nothing truncated. -->
        <ul class="flex flex-col gap-2 md:hidden" aria-label="Attendance by day">
            <li
                v-for="day in days"
                :key="day.date"
                :class="
                    cn(
                        'flex items-center gap-3 rounded-md border bg-card p-3',
                        day.is_future && 'opacity-60',
                        day.is_today && 'ring-2 ring-ring',
                    )
                "
            >
                <div class="w-12 shrink-0 text-center">
                    <p class="text-lg font-semibold tabular-nums">{{ day.day_of_month }}</p>
                    <p class="text-xs text-muted-foreground">{{ day.weekday.slice(0, 3) }}</p>
                </div>

                <div class="flex min-w-0 flex-1 flex-col gap-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <StatusBadge v-if="day.tone" :status="day.tone" :label="label(day)" size="sm" />
                        <span v-else class="text-sm text-muted-foreground">{{ label(day) }}</span>
                        <span v-if="day.is_today" class="text-xs font-medium">Today</span>
                    </div>

                    <p v-if="day.clock_in" class="text-xs tabular-nums text-muted-foreground">
                        {{ formatShift(day) }}
                        <template v-if="day.worked_minutes !== null">
                            · {{ formatMinutes(day.worked_minutes) }}
                        </template>
                    </p>

                    <!-- The remote timer's minutes. Printed only when the server sent a number:
                         `tracked_minutes` is null until the timer slice lands, and "0m tracked"
                         would be a measurement nobody took. -->
                    <p v-if="day.tracked_minutes !== null" class="text-xs tabular-nums text-muted-foreground">
                        {{ formatMinutes(day.tracked_minutes) }} tracked
                    </p>

                    <p v-if="day.note" class="text-xs text-muted-foreground">{{ day.note }}</p>
                    <p v-if="day.edited_by" class="text-xs text-muted-foreground">Edited by {{ day.edited_by }}</p>
                </div>

                <Button
                    v-if="canEdit && !day.is_future"
                    variant="ghost"
                    size="icon"
                    class="shrink-0"
                    @click="emit('edit', day)"
                >
                    <Pencil class="size-4" aria-hidden="true" />
                    <span class="sr-only">Edit {{ day.weekday }} {{ day.day_of_month }}</span>
                </Button>
            </li>
        </ul>

        <!-- md and up: the seven-column grid. -->
        <div class="hidden md:block">
            <div class="mb-2 grid grid-cols-7 gap-2" aria-hidden="true">
                <p v-for="heading in WEEKDAY_HEADINGS" :key="heading" class="text-xs font-medium text-muted-foreground">
                    {{ heading }}
                </p>
            </div>

            <div class="grid grid-cols-7 gap-2">
                <div v-for="blank in blanks" :key="`blank-${blank}`" aria-hidden="true" />

                <component
                    :is="canEdit && !day.is_future ? 'button' : 'div'"
                    v-for="day in days"
                    :key="day.date"
                    :type="canEdit && !day.is_future ? 'button' : undefined"
                    :class="cellClass(day)"
                    :aria-label="canEdit && !day.is_future ? `Edit ${sentence(day)}` : sentence(day)"
                    :title="sentence(day)"
                    @click="canEdit && !day.is_future ? emit('edit', day) : undefined"
                >
                    <div class="flex items-baseline justify-between gap-1">
                        <span class="text-sm font-semibold tabular-nums">{{ day.day_of_month }}</span>
                        <span v-if="day.is_today" class="text-xs font-medium">Today</span>
                    </div>

                    <!-- The word. Never a bare tint. -->
                    <span class="text-xs leading-tight font-medium">{{ label(day) }}</span>

                    <span v-if="day.clock_in" class="text-xs tabular-nums opacity-80">{{ day.clock_in }}</span>

                    <span v-if="day.tracked_minutes !== null" class="text-xs tabular-nums opacity-80">
                        {{ formatMinutes(day.tracked_minutes) }}
                    </span>

                    <span v-if="day.edited_by" class="mt-auto text-xs opacity-80">Edited</span>
                </component>
            </div>
        </div>
    </div>
</template>
